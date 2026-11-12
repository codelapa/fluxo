<?php

namespace App\Observers;

use App\Models\Transaction;
use App\Models\User;

class TransactionObserver
{
    public function updated(Transaction $transaction): void
    {
        User::whereKey($transaction->user_id)->increment('dashboard_version');
    }

    public function deleted(Transaction $transaction): void
    {
        User::whereKey($transaction->user_id)->increment('dashboard_version');
    }
}
