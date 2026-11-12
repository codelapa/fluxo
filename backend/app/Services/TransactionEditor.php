<?php

namespace App\Services;

use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class TransactionEditor
{
    private function locked(User $user, callable $operation): mixed
    {
        return DB::transaction(function () use ($user, $operation) {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();

            return $operation();
        }, 3);
    }

    public function update(User $user, int $id, array $data): Transaction
    {
        return $this->locked($user, function () use ($user, $id, $data) {
            $transaction = Transaction::where('user_id', $user->id)->lockForUpdate()->findOrFail($id);
            $key = TransactionFingerprint::canonical($data);
            $oldKey = TransactionFingerprint::canonical(['date' => $transaction->date->format('Y-m-d'), 'description' => $transaction->description, 'amount' => $transaction->amount, 'type' => $transaction->type]);
            $identity = DB::table('transaction_identities')->where('user_id', $user->id)->where('identity_key', $key)->first();
            abort_if($key !== $oldKey && $identity && $identity->transaction_id !== $transaction->id, 409, 'Já existe uma transação com estes dados.');
            $transaction->update($data);
            if (! $identity) {
                DB::table('transaction_identities')->insert(['user_id' => $transaction->user_id, 'transaction_id' => $transaction->id, 'identity_key' => $key, 'original_amount' => $data['amount'], 'original_type' => $data['type']]);
            }

return $transaction->fresh();
        });
    }

    public function delete(User $user, int $id): void
    {
        $this->locked($user, function () use ($user, $id) {
            $transaction = Transaction::where('user_id', $user->id)->lockForUpdate()->findOrFail($id);
            $transaction->deletion_reason = 'user';
            $transaction->saveQuietly();
            $transaction->delete();
        });
    }

    public function restore(User $user, int $id): Transaction
    {
        return $this->locked($user, function () use ($user, $id) {
            $transaction = Transaction::onlyTrashed()->where('user_id', $user->id)->where('deletion_reason', 'user')->lockForUpdate()->findOrFail($id);
            $transaction->deletion_reason = null;
            $transaction->restore();

            return $transaction->fresh();
        });
    }
}
