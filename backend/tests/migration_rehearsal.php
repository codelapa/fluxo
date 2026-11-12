<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

// A completely isolated in-memory rehearsal of the legacy-data migration.
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'cache.default' => 'array']);
DB::purge('sqlite');
$db = DB::connection();
if ($db->getDriverName() !== 'sqlite' || $db->getDatabaseName() !== ':memory:') {
    throw new RuntimeException('Rehearsal must never use a persistent database.');
}
Artisan::call('migrate', ['--force' => true]);
$migration = require __DIR__.'/../database/migrations/2026_11_12_000002_add_transaction_identities.php';
$migration->down();
$db->table('migrations')->where('migration', '2026_11_12_000002_add_transaction_identities')->delete();
$u = $db->table('users')->insertGetId(['name' => 'Migration fixture', 'email' => 'migration@example.invalid', 'password' => 'not-a-login-hash', 'created_at' => now(), 'updated_at' => now()]);
$i = $db->table('imports')->insertGetId(['user_id' => $u, 'filename' => 'legacy.csv', 'path' => 'fixture', 'checksum' => hash('sha256', 'legacy'), 'status' => 'completed', 'total_rows' => 3, 'processed_rows' => 3, 'created_at' => now(), 'updated_at' => now()]);
foreach (['Same', ' SAME ', 'Different'] as $n => $description) {
    $db->table('transactions')->insert(['user_id' => $u, 'import_id' => $i, 'row_number' => $n + 1, 'date' => '2026-11-12', 'description' => $description, 'amount' => 100, 'type' => 'income', 'created_at' => now(), 'updated_at' => now()]);
}
Artisan::call('migrate', ['--force' => true]);
if ($db->table('transactions')->whereNull('deleted_at')->count() !== 2 || $db->table('transactions')->where('deletion_reason', 'duplicate')->count() !== 1 || $db->table('transaction_identities')->count() !== 2 || $db->table('imports')->value('skipped_rows') !== 1 || $db->table('imports')->value('processed_rows') !== 2) {
    throw new RuntimeException('Legacy deduplication failed');
}
echo "PASS migration: exact duplicate archived; distinct transaction preserved; history retained.\n";
