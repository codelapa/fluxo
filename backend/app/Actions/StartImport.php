<?php

namespace App\Actions;

use App\Jobs\ProcessImport;
use App\Models\Import;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class StartImport
{
    public function execute(User $user, UploadedFile $file): array
    {
        $hash = hash_file('sha256', $file->getRealPath());
        $existing = Import::where('user_id', $user->id)->where('checksum', $hash)->first();
        if ($existing) {
            return [$existing, false];
        }
        $path = $file->store('imports/'.$user->id, 'local');
        try {
            $import = Import::firstOrCreate(['user_id' => $user->id, 'checksum' => $hash], ['filename' => mb_substr(basename($file->getClientOriginalName()), 0, 200), 'path' => $path, 'status' => 'pending', 'format' => strtolower($file->getClientOriginalExtension())]);
            if (! $import->wasRecentlyCreated) {
                Storage::disk('local')->delete($path);

                return [$import, false];
            }ProcessImport::dispatch($import->id);

            return [$import, true];
        } catch (\Throwable $e) {
            if (isset($import)) {
                $import->update(['status' => 'failed', 'errors' => ['Não foi possível iniciar o processamento. Tente novamente.']]);
            } else {
                Storage::disk('local')->delete($path);
            }
            throw $e;
        }
    }
}
