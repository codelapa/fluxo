<?php

namespace App\Jobs;

use App\Events\ImportCompleted;
use App\Exceptions\InvalidFinancialFile;
use App\Models\Import;
use App\Models\User;
use App\Services\ImportReader;
use App\Services\ImportWriter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ProcessImport implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 300;

    public function __construct(public readonly int $importId) {}

    public function backoff(): array
    {
        return [10, 30];
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('import:'.$this->importId))->releaseAfter(5)->expireAfter(360)];
    }

    public function handle(ImportReader $reader, ImportWriter $writer): void
    {
        $import = Import::findOrFail($this->importId);
        if ($import->status === 'completed') {
            return;
        }
        $import->update(['status' => 'processing', 'errors' => null]);
        $path = Storage::disk('local')->path($import->path);
        try {
            $format = $import->format ?: 'csv';
            $total = $reader->validate($path, $format);
            $import->update(['total_rows' => $total]);
            DB::transaction(function () use ($reader, $writer, $path, $format, $import) {
                User::whereKey($import->user_id)->lockForUpdate()->firstOrFail();
                if ($import->fresh()->status === 'completed') {
                    return;
                }
                $chunk = [];
                $seen = 0;
                $inserted = 0;
                $skipped = 0;
                foreach ($reader->rows($path, $format) as $row) {
                    $chunk[] = $row;
                    if (count($chunk) === 500) {
                        $result = $writer->chunk($import, $chunk);
                        $inserted += $result['inserted'];
                        $skipped += $result['skipped'];
                        $seen += count($chunk);
                        $chunk = [];
                        Cache::put('import-progress:'.$import->id, $seen, 600);
                    }
                }
                if ($chunk) {
                    $result = $writer->chunk($import, $chunk);
                    $inserted += $result['inserted'];
                    $skipped += $result['skipped'];
                }
                $import->update(['status' => 'completed', 'processed_rows' => $inserted, 'skipped_rows' => $skipped, 'completed_at' => now(), 'errors' => null]);
                event(new ImportCompleted($import->user_id));
            }, 3);
            try {
                Cache::forget('import-progress:'.$import->id);
                Storage::disk('local')->delete($import->path);
            } catch (\Throwable) {
                report(new \RuntimeException('Import cleanup deferred: '.$import->id));
            }
        } catch (InvalidFinancialFile $e) {
            $import->update(['status' => 'failed', 'errors' => $e->errors, 'processed_rows' => 0]);
            Cache::forget('import-progress:'.$import->id);
        } catch (\Throwable $e) {
            $import->update(['status' => 'retrying', 'errors' => ['Falha temporária. Uma nova tentativa será realizada.']]);
            Cache::forget('import-progress:'.$import->id);
            throw $e;
        }
    }

    public function failed(?\Throwable $exception): void
    {
        Import::whereKey($this->importId)->update(['status' => 'failed', 'errors' => json_encode(['Não foi possível processar o arquivo. Tente novamente.'])]);
        Cache::forget('import-progress:'.$this->importId);
    }
}
