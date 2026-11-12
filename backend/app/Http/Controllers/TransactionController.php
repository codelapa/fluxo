<?php

namespace App\Http\Controllers;

use App\Services\TransactionEditor;
use Illuminate\Http\Request;

class TransactionController extends Controller
{
    public function update(Request $request, int $id, TransactionEditor $editor)
    {
        $data = $request->validate(['description' => 'required|string|min:1|max:500', 'date' => 'required|date_format:Y-m-d', 'amount' => 'required|integer|min:1|max:10000000000', 'type' => 'required|in:income,expense']);
        $data['description'] = trim($data['description']);
        abort_if($data['description'] === '', 422, 'Informe a descrição.');

        return response()->json($editor->update($request->user(), $id, $data));
    }

    public function destroy(Request $request, int $id, TransactionEditor $editor)
    {
        $editor->delete($request->user(), $id);

        return response()->noContent();
    }

    public function restore(Request $request, int $id, TransactionEditor $editor)
    {
        return response()->json($editor->restore($request->user(), $id));
    }
}
