<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use App\Services\Dashboard;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FinanceController extends Controller
{
    private function filters(Request $r): array
    {
        return $r->validate(['from' => 'nullable|date_format:Y-m-d', 'to' => ['nullable', 'date_format:Y-m-d', Rule::when($r->filled('from'), 'after_or_equal:from')], 'status' => 'nullable|in:active,deleted', 'type' => 'nullable|in:income,expense', 'q' => 'nullable|string|max:150', 'page' => 'nullable|integer|min:1|max:100000', 'per_page' => 'nullable|integer|min:10|max:100']);
    }

    public function dashboard(Request $r, Dashboard $dashboard)
    {
        $f = $this->filters($r);

        return response()->json($dashboard->forUser($r->user()->id, $f['from'] ?? null, $f['to'] ?? null));
    }

    public function transactions(Request $r)
    {
        $f = $this->filters($r);

        return Transaction::when(($f['status'] ?? 'active') === 'deleted', fn ($q) => $q->onlyTrashed()->where('deletion_reason', 'user'))->where('user_id', $r->user()->id)->when($f['from'] ?? null, fn ($q, $v) => $q->where('date', '>=', $v))->when($f['to'] ?? null, fn ($q, $v) => $q->where('date', '<=', $v))->when($f['type'] ?? null, fn ($q, $v) => $q->where('type', $v))->when($f['q'] ?? null, fn ($q, $v) => $q->where('description', 'like', '%'.addcslashes($v, '%_\\').'%'))->orderByDesc('date')->orderByDesc('id')->paginate($f['per_page'] ?? 15);
    }
}
