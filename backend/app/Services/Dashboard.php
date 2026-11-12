<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Cache;

class Dashboard
{
    public function forUser(int $userId, ?string $from = null, ?string $to = null): array
    {
        $version = User::whereKey($userId)->value('dashboard_version');
        $key = "dashboard:{$userId}:v{$version}:{$from}:{$to}";

        return Cache::remember($key, 300, function () use ($userId, $from, $to) {
            $q = Transaction::where('user_id', $userId)->when($from, fn ($q) => $q->where('date', '>=', $from))->when($to, fn ($q) => $q->where('date', '<=', $to));
            $s = (clone $q)->selectRaw("COALESCE(SUM(CASE WHEN type='income' THEN amount ELSE 0 END),0) AS income,COALESCE(SUM(CASE WHEN type='expense' THEN amount ELSE 0 END),0) AS expense,COUNT(*) AS count")->first();
            $months = (clone $q)->selectRaw("SUBSTR(date,1,7) AS month,SUM(CASE WHEN type='income' THEN amount ELSE 0 END) AS income,SUM(CASE WHEN type='expense' THEN amount ELSE 0 END) AS expense")->groupByRaw('SUBSTR(date,1,7)')->orderBy('month')->get()->map(fn ($r) => ['month' => $r->month, 'income' => (int) $r->income, 'expense' => (int) $r->expense]);

            return ['income' => (int) $s->income, 'expense' => (int) $s->expense, 'balance' => (int) $s->income - (int) $s->expense, 'count' => (int) $s->count, 'months' => $months, 'generated_at' => now()->toIso8601String()];
        });
    }
}
