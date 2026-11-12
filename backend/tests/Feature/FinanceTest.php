<?php

namespace Tests\Feature;

use App\Events\ImportCompleted;
use App\Jobs\ProcessImport;
use App\Models\Import;
use App\Models\User;
use App\Services\Dashboard;
use App\Services\ImportReader;
use App\Services\ImportWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FinanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function batch(User $user, string $csv): Import
    {
        $path = 'imports/test-'.uniqid().'.csv';
        Storage::disk('local')->put($path, $csv);

        return Import::create(['user_id' => $user->id, 'filename' => 'test.csv', 'path' => $path, 'checksum' => hash('sha256', $csv)]);
    }

    private function process(Import $i): void
    {
        (new ProcessImport($i->id))->handle(app(ImportReader::class), app(ImportWriter::class));
    }

    public function test_all_endpoints_require_authentication(): void
    {
        foreach (['/api/user', '/api/dashboard', '/api/transactions', '/api/imports', '/api/sample'] as $url) {
            $this->getJson($url)->assertUnauthorized();
        }
        $this->postJson('/api/imports')->assertUnauthorized();
    }

    public function test_login_and_logout(): void
    {
        $u = User::factory()->create(['password' => 'strong-test-password']);
        $this->postJson('/api/login', ['email' => $u->email, 'password' => 'wrong'])->assertUnprocessable();
        $this->postJson('/api/login', ['email' => $u->email, 'password' => 'strong-test-password'])->assertOk()->assertJsonPath('id', $u->id);
        $this->getJson('/api/user')->assertOk();
        $this->postJson('/api/logout')->assertNoContent();
        $this->assertGuest('web');
        Auth::forgetGuards();
        $this->getJson('/api/user')->assertUnauthorized();
    }

    public function test_upload_queues_without_parsing(): void
    {
        Queue::fake();
        $u = User::factory()->create();
        $this->actingAs($u)->postJson('/api/imports', ['file' => UploadedFile::fake()->createWithContent('batch.csv', "bad,header\n")])->assertAccepted()->assertJsonPath('import.status', 'pending');
        Queue::assertPushed(ProcessImport::class, 1);
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_repeated_file_is_idempotent(): void
    {
        Queue::fake();
        $u = User::factory()->create();
        $file = fn () => UploadedFile::fake()->createWithContent('test.csv', "date,description,amount,type\n2026-01-01,A,100,Receita\n");
        $this->actingAs($u)->postJson('/api/imports', ['file' => $file()])->assertAccepted();
        $this->postJson('/api/imports', ['file' => $file()])->assertOk()->assertJsonPath('duplicate', true);
        Queue::assertPushed(ProcessImport::class, 1);
        $this->assertDatabaseCount('imports', 1);
    }

    public function test_import_cents_balance_and_cache_invalidation(): void
    {
        $u = User::factory()->create();
        $service = app(Dashboard::class);
        $this->assertEquals(0, $service->forUser($u->id)['balance']);
        $i = $this->batch($u, "date,description,amount,type\n2026-01-01,Income,115346,Receita\n2026-01-02,Expense,32000,Despesa\n");
        $this->process($i);
        $this->assertEquals('completed', $i->fresh()->status);
        $this->actingAs($u)->getJson('/api/dashboard')->assertOk()->assertJsonPath('income', 115346)->assertJsonPath('expense', 32000)->assertJsonPath('balance', 83346);
        $this->assertEquals(1, $u->fresh()->dashboard_version);
        $this->assertTrue(Cache::has('dashboard:'.$u->id.':v0::'));
        $this->assertDatabaseCount('transactions', 2);
    }

    public function test_completed_job_is_safe_to_retry(): void
    {
        $u = User::factory()->create();
        $i = $this->batch($u, "date,description,amount,type\n2026-01-01,A,100,Receita\n");
        $this->process($i);
        $this->process($i);
        $this->assertDatabaseCount('transactions', 1);
        $this->assertEquals(1, $u->fresh()->dashboard_version);
    }

    public function test_invalid_row_prevents_partial_import(): void
    {
        $u = User::factory()->create();
        $i = $this->batch($u, "date,description,amount,type\n2026-01-01,A,100,Receita\n2026-02-31,B,-200,Despesa\n");
        $this->process($i);
        $this->assertEquals('failed', $i->fresh()->status);
        $this->assertNotEmpty($i->fresh()->errors);
        $this->assertDatabaseCount('transactions', 0);
        $this->assertEquals(0, $u->fresh()->dashboard_version);
    }

    public function test_invalid_headers_types_values_and_empty_files(): void
    {
        $u = User::factory()->create();
        foreach (["wrong,header\n", "date,description,amount,type\n", "date,description,amount,type\n2026-01-01,A,1.25,Receita\n", "date,description,amount,type\n2026-01-01,A,100,Other\n"] as $csv) {
            $i = $this->batch($u, $csv);
            $this->process($i);
            $this->assertEquals('failed', $i->fresh()->status);
        }
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_failed_commit_rolls_back_data_and_cache_version(): void
    {
        $u = User::factory()->create();
        $i = $this->batch($u, "date,description,amount,type\n2026-01-01,A,507,Receita\n");
        Event::listen(ImportCompleted::class, fn () => throw new \RuntimeException('Simulated failure'));
        try {
            $this->process($i);
            $this->fail('Expected exception');
        } catch (\RuntimeException $e) {
            $this->assertSame('Simulated failure', $e->getMessage());
        }
        $this->assertDatabaseCount('transactions', 0);
        $this->assertEquals(0, $u->fresh()->dashboard_version);
    }

    public function test_user_isolation(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        $i = $this->batch($a, "date,description,amount,type\n2026-01-01,Private,1000,Receita\n");
        $this->process($i);
        $this->actingAs($b)->getJson('/api/dashboard')->assertJsonPath('count', 0);
        $this->getJson('/api/transactions')->assertJsonPath('total', 0);
        $this->getJson('/api/imports/'.$i->id)->assertNotFound();
        $this->postJson('/api/imports/'.$i->id.'/retry')->assertNotFound();
    }

    public function test_large_import_pagination_and_filtering(): void
    {
        $u = User::factory()->create();
        $csv = "date,description,amount,type\n";
        for ($n = 1; $n <= 1201; $n++) {
            $csv .= "2026-01-01,Item {$n},100,".($n % 2 ? 'Receita' : 'Despesa')."\n";
        }
        $i = $this->batch($u, $csv);
        $this->process($i);
        $this->assertDatabaseCount('transactions', 1201);
        $this->actingAs($u)->getJson('/api/transactions?per_page=15&page=2&type=income')->assertOk()->assertJsonCount(15, 'data')->assertJsonPath('total', 601)->assertJsonPath('current_page', 2);
        $this->getJson('/api/dashboard')->assertJsonPath('balance', 100);
    }

    public function test_upload_constraints(): void
    {
        Queue::fake();
        $u = User::factory()->create();
        $this->actingAs($u)->postJson('/api/imports', ['file' => UploadedFile::fake()->create('bad.exe', 1)])->assertUnprocessable();
        $this->postJson('/api/imports', ['file' => UploadedFile::fake()->create('big.csv', 20481)])->assertUnprocessable();
        Queue::assertNothingPushed();
    }

    public function test_invalid_query_inputs(): void
    {
        $u = User::factory()->create();
        $this->actingAs($u)->getJson('/api/transactions?per_page=1000')->assertUnprocessable();
        $this->getJson('/api/dashboard?from=2026-02-31')->assertUnprocessable();
    }

    public function test_public_demo_access_is_disabled(): void
    {
        $this->postJson('/api/demo')->assertNotFound();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_only_failed_imports_can_be_requeued(): void
    {
        Queue::fake();
        $u = User::factory()->create();
        $i = $this->batch($u, "date,description,amount,type\n2026-01-01,A,100,Receita\n");
        $i->update(['status' => 'failed']);
        $this->actingAs($u)->postJson('/api/imports/'.$i->id.'/retry')->assertAccepted()->assertJsonPath('status', 'pending');
        $this->postJson('/api/imports/'.$i->id.'/retry')->assertStatus(409);
        Queue::assertPushed(ProcessImport::class, 1);
    }

    public function test_optional_date_bounds_and_reversed_range(): void
    {
        $u = User::factory()->create();
        $this->actingAs($u)->getJson('/api/dashboard?to=2026-01-01')->assertOk();
        $this->getJson('/api/dashboard?from=2026-02-01&to=2026-01-01')->assertUnprocessable();
    }
}
