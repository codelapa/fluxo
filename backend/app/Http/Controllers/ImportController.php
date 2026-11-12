<?php

namespace App\Http\Controllers;

use App\Actions\StartImport;
use App\Jobs\ProcessImport;
use App\Models\Import;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

class ImportController extends Controller
{
    public function index(Request $request)
    {
        $request->validate(['page' => 'nullable|integer|min:1|max:100000']);

        return Import::where('user_id', $request->user()->id)->latest('id')->paginate(10);
    }

    public function store(Request $request, StartImport $start)
    {
        $request->validate(['file' => ['required', 'file', 'max:20480', 'extensions:csv,ofx']]);
        [$import,$created] = $start->execute($request->user(), $request->file('file'));

        return response()->json(['import' => $import, 'duplicate' => ! $created], $created ? 202 : 200);
    }

    public function show(Request $request, int $id)
    {
        $import = Import::where('user_id', $request->user()->id)->findOrFail($id);

        return response()->json($import->toArray() + ['progress' => (int) Cache::get('import-progress:'.$id, $import->processed_rows)]);
    }

    public function retry(Request $request, int $id)
    {
        $import = Import::where('user_id', $request->user()->id)->findOrFail($id);
        abort_unless(Storage::disk('local')->exists($import->path), 410, 'Arquivo expirado. Envie o CSV novamente.');
        $changed = Import::whereKey($id)->where('status', 'failed')->update(['status' => 'pending', 'errors' => null]);
        abort_unless($changed, 409, 'Somente importações com falha podem ser reenviadas.');

        try {
            ProcessImport::dispatch($id);
        } catch (\Throwable $e) {
            $import->update(['status' => 'failed']);
            throw $e;
        }

        return response()->json($import->fresh(), 202);
    }

    public function sample()
    {
        return response()->download(storage_path('app/samples/financial_transactions.csv'), 'financial_transactions.csv', ['Content-Type' => 'text/csv']);
    }
}
