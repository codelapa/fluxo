<?php

namespace App\Services;

use App\Exceptions\InvalidFinancialFile;
use App\Models\Import;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;

class ImportWriter
{
    public function chunk(Import $import, array $rows): array
    {
        $keys = [];
        foreach ($rows as $r) {
            $keys[] = TransactionFingerprint::canonical($r);
            if (isset($r['external_key'])) {
                $keys[] = $r['external_key'];
            }
        }
        $known = DB::table('transaction_identities as i')->join('transactions as t', 't.id', '=', 'i.transaction_id')->where('i.user_id', $import->user_id)->whereIn('i.identity_key', array_unique($keys))->select('i.*', 't.source_account')->get()->keyBy('identity_key')->map(fn ($r) => (array) $r)->all();
        $new = [];
        $aliases = [];
        $adopt = [];
        $skipped = 0;
        foreach ($rows as $row) {
            $canonical = TransactionFingerprint::canonical($row);
            $external = $row['external_key'] ?? null;
            $key = $external ?? $canonical;
            if (isset($known[$key])) {
                if ($external && ((int) $known[$key]['original_amount'] !== $row['amount'] || $known[$key]['original_type'] !== $row['type'])) {
                    throw new InvalidFinancialFile(['O mesmo FITID foi recebido com valores diferentes. Confira o extrato e ajuste a transação existente.']);
                }
                $skipped++;

                continue;
            }
            $reference = null;
            if ($external && isset($known[$canonical]) && $known[$canonical]['source_account'] === null) {
                $reference = $known[$canonical];
                $adopt[$row['source_account']][] = $reference['transaction_id'];
                $known[$canonical]['source_account'] = $row['source_account'];
            }
            if ($reference === null) {
                $rowNumber = $row['row_number'];
                $new[] = ['user_id' => $import->user_id, 'import_id' => $import->id, 'row_number' => $rowNumber, 'date' => $row['date'], 'description' => $row['description'], 'amount' => $row['amount'], 'type' => $row['type'], 'source_account' => $row['source_account'] ?? null, 'created_at' => now(), 'updated_at' => now()];
                $reference = ['row_number' => $rowNumber, 'source_account' => $row['source_account'] ?? null];
            } else {
                $skipped++;
            }
            $receipt = $reference + ['user_id' => $import->user_id, 'original_amount' => $row['amount'], 'original_type' => $row['type']];
            $known[$key] = $receipt;
            $aliases[$key] = $receipt;
            if (! isset($known[$canonical])) {
                $known[$canonical] = $receipt;
                $aliases[$canonical] = $receipt;
            }
        }
        if ($new) {
            Transaction::insert($new);
            $ids = DB::table('transactions')->where('import_id', $import->id)->whereIn('row_number', array_column($new, 'row_number'))->pluck('id', 'row_number');
        } else {
            $ids = collect();
        }
        $receipts = [];
        foreach ($aliases as $key => $r) {
            $receipts[] = ['user_id' => $r['user_id'], 'transaction_id' => $r['transaction_id'] ?? $ids[$r['row_number']], 'identity_key' => $key, 'original_amount' => $r['original_amount'], 'original_type' => $r['original_type']];
        }
        if ($receipts) {
            DB::table('transaction_identities')->insert($receipts);
        }
        foreach ($adopt as $account => $ids) {
            DB::table('transactions')->whereIn('id', array_unique($ids))->update(['source_account' => $account]);
        }

        return ['inserted' => count($new), 'skipped' => $skipped];
    }
}
