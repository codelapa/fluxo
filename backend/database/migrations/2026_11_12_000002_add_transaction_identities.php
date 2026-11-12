<?php

use App\Services\TransactionFingerprint;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('imports', function (Blueprint $t) {
            $t->string('format', 8)->default('csv');
            $t->unsignedInteger('skipped_rows')->default(0);
        });
        Schema::table('transactions', function (Blueprint $t) {
            $t->softDeletes();
            $t->char('source_account', 64)->nullable();
            $t->string('deletion_reason', 20)->nullable();
            $t->unsignedBigInteger('duplicate_of')->nullable();
        });
        Schema::create('transaction_identities', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('transaction_id')->constrained()->cascadeOnDelete();
            $t->char('identity_key', 64);
            $t->unsignedBigInteger('original_amount');
            $t->string('original_type', 10);
            $t->unique(['user_id', 'identity_key']);
            $t->index('transaction_id');
        });
        $duplicates = [];
        DB::table('transactions')->orderBy('id')->chunkById(500, function ($rows) use (&$duplicates) {
            $keys = [];
            foreach ($rows as $r) {
                $keys[] = TransactionFingerprint::canonical((array) $r);
            }$known = DB::table('transaction_identities')->whereIn('identity_key', $keys)->get()->keyBy(fn ($r) => $r->user_id.':'.$r->identity_key)->all();
            $insert = [];
            foreach ($rows as $r) {
                $key = TransactionFingerprint::canonical((array) $r);
                $compound = $r->user_id.':'.$key;
                if (isset($known[$compound])) {
                    DB::table('transactions')->where('id', $r->id)->update(['deleted_at' => now(), 'deletion_reason' => 'duplicate', 'duplicate_of' => $known[$compound]->transaction_id]);
                    $duplicates[$r->import_id] = ($duplicates[$r->import_id] ?? 0) + 1;
                } else {
                    $receipt = ['user_id' => $r->user_id, 'transaction_id' => $r->id, 'identity_key' => $key, 'original_amount' => $r->amount, 'original_type' => $r->type];
                    $insert[] = $receipt;
                    $known[$compound] = (object) $receipt;
                }
            }if ($insert) {
                DB::table('transaction_identities')->insert($insert);
            }
        });
        foreach ($duplicates as $id => $count) {
            DB::table('imports')->where('id', $id)->update(['skipped_rows' => $count, 'processed_rows' => DB::raw('processed_rows - '.(int) $count)]);
        }
        DB::table('users')->increment('dashboard_version');
    }

    public function down(): void
    {
        Schema::dropIfExists('transaction_identities');
        DB::table('transactions')->where('deletion_reason', 'duplicate')->update(['deleted_at' => null]);
        Schema::table('transactions', fn (Blueprint $t) => $t->dropColumn(['deleted_at', 'source_account', 'deletion_reason', 'duplicate_of']));
        Schema::table('imports', fn (Blueprint $t) => $t->dropColumn(['format', 'skipped_rows']));
    }
};
