<?php

namespace Tests\Feature;

use App\Jobs\ProcessImport;
use App\Models\Import;
use App\Models\Transaction;
use App\Models\User;
use App\Services\ImportReader;
use App\Services\ImportWriter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EditingAndOfxTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function runImport(User $user, string $content, string $format = 'csv'): Import
    {
        $path = 'imports/'.uniqid().'.'.$format;
        Storage::disk('local')->put($path, $content);
        $i = Import::create(['user_id' => $user->id, 'filename' => 'file.'.$format, 'path' => $path, 'format' => $format, 'checksum' => hash('sha256', $content)]);
        (new ProcessImport($i->id))->handle(app(ImportReader::class), app(ImportWriter::class));

        return $i->fresh();
    }

    private function ofx(string $id = 'tx-1', string $amount = '123.45', string $account = '123', string $extra = ''): string
    {
        return "OFXHEADER:100\nDATA:OFXSGML\n\n<OFX><BANKMSGSRSV1><STMTTRNRS><STMTRS><CURDEF>BRL<BANKACCTFROM><BANKID>001<ACCTID>{$account}<ACCTTYPE>CHECKING</BANKACCTFROM><BANKTRANLIST><STMTTRN><TRNTYPE>OTHER<DTPOSTED>20261112120000[-3:BRT]<TRNAMT>{$amount}<FITID>{$id}<MEMO>Serviço</STMTTRN>{$extra}</BANKTRANLIST></STMTRS></STMTTRNRS></BANKMSGSRSV1></OFX>";
    }

    public function test_ofx_debits_are_negative_and_xml_is_supported(): void
    {
        $u = User::factory()->create();
        $s = '<OFX><STMTRS><CURDEF>BRL</CURDEF><BANKACCTFROM><BANKID>001</BANKID><ACCTID>1</ACCTID><ACCTTYPE>CHECKING</ACCTTYPE></BANKACCTFROM><BANKTRANLIST><STMTTRN><DTPOSTED>20261112</DTPOSTED><TRNAMT>-12.34</TRNAMT><FITID>abc</FITID><NAME>Compra</NAME></STMTTRN></BANKTRANLIST></STMTRS></OFX>';
        $i = $this->runImport($u, $s, 'ofx');
        $this->assertSame('completed', $i->status);
        $this->actingAs($u)->getJson('/api/dashboard')->assertJsonPath('expense', 1234)->assertJsonPath('balance', -1234);
    }

    public function test_overlapping_ofx_uses_account_and_fitid_not_amount_only(): void
    {
        $u = User::factory()->create();
        $this->runImport($u, $this->ofx(), 'ofx');
        $repeat = $this->runImport($u, $this->ofx()."\n", 'ofx');
        $this->assertSame(1, $repeat->skipped_rows);
        $this->assertSame(1, Transaction::count());
        $this->runImport($u, $this->ofx('different'), 'ofx');
        $this->runImport($u, $this->ofx('tx-1', '123.45', 'other-account'), 'ofx');
        $this->assertSame(3, Transaction::count());
    }

    public function test_changed_amount_for_same_fitid_fails_without_changing_existing_data(): void
    {
        $u = User::factory()->create();
        $this->runImport($u, $this->ofx(), 'ofx');
        $i = $this->runImport($u, $this->ofx('tx-1', '222.22'), 'ofx');
        $this->assertSame('failed', $i->status);
        $this->assertSame(1, Transaction::count());
        $this->assertSame(12345, Transaction::first()->amount);
    }

    public function test_csv_and_ofx_overlap_is_detected_in_both_orders(): void
    {
        foreach (['csv', 'ofx'] as $first) {
            $u = User::factory()->create();
            $csv = "date,description,amount,type\n2026-11-12,Serviço,12345,Receita\n";
            if ($first === 'csv') {
                $this->runImport($u, $csv);
                $i = $this->runImport($u, $this->ofx(), 'ofx');
            } else {
                $this->runImport($u, $this->ofx(), 'ofx');
                $i = $this->runImport($u, $csv);
            }
            $this->assertSame(1, $i->skipped_rows);
            $this->assertSame(1, Transaction::where('user_id', $u->id)->count());
        }
    }

    public function test_csv_duplicates_within_and_between_files_are_ignored_but_distinct_descriptions_survive(): void
    {
        $u = User::factory()->create();
        $csv = "date,description,amount,type\n2026-11-12,Serviço,100,Receita\n2026-11-12,Serviço,100,Receita\n2026-11-12,Outro,100,Receita\n";
        $i = $this->runImport($u, $csv);
        $this->assertSame(2, $i->processed_rows);
        $this->assertSame(1, $i->skipped_rows);
        $j = $this->runImport($u, str_replace("\n", "\r\n", $csv));
        $this->assertSame(0, $j->processed_rows);
        $this->assertSame(3, $j->skipped_rows);
        $this->assertSame(2, Transaction::count());
    }

    public function test_edit_delete_restore_recalculates_totals_and_reimport_does_not_restore_old_values(): void
    {
        $u = User::factory()->create();
        $csv = "date,description,amount,type\n2026-11-12,Initial,12345,Receita\n";
        $this->runImport($u, $csv);
        $t = Transaction::first();
        $this->actingAs($u);
        $this->getJson('/api/dashboard')->assertJsonPath('balance', 12345);
        $this->patchJson('/api/transactions/'.$t->id, ['description' => 'Edited', 'date' => '2026-11-10', 'amount' => 200, 'type' => 'expense'])->assertOk();
        $this->getJson('/api/dashboard')->assertJsonPath('balance', -200);
        $this->runImport($u, $csv."\n");
        $this->assertSame(1, Transaction::count());
        $this->assertSame(200, $t->fresh()->amount);
        $this->deleteJson('/api/transactions/'.$t->id)->assertNoContent();
        $this->getJson('/api/dashboard')->assertJsonPath('count', 0)->assertJsonPath('balance', 0);
        $this->getJson('/api/transactions?status=deleted')->assertJsonPath('total', 1);
        $this->runImport($u, str_replace("\n", "\r\n", $csv));
        $this->assertSame(0, Transaction::count());
        $this->postJson('/api/transactions/'.$t->id.'/restore')->assertOk();
        $this->getJson('/api/dashboard')->assertJsonPath('balance', -200);
    }

    public function test_edits_and_deletes_cannot_cross_users(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        $this->runImport($a, "date,description,amount,type\n2026-11-12,Private,100,Receita\n");
        $id = Transaction::first()->id;
        $this->actingAs($b)->patchJson('/api/transactions/'.$id, ['description' => 'Changed', 'date' => '2026-11-12', 'amount' => 500, 'type' => 'expense'])->assertNotFound();
        $this->deleteJson('/api/transactions/'.$id)->assertNotFound();
        $this->postJson('/api/transactions/'.$id.'/restore')->assertNotFound();
    }

    public function test_ofx_rejects_entities_missing_ids_currency_and_invalid_money(): void
    {
        $u = User::factory()->create();
        foreach (['<!DOCTYPE OFX [<!ENTITY x SYSTEM "file:///etc/passwd">]>'.$this->ofx(), str_replace('<FITID>tx-1', '<OTHER>tx-1', $this->ofx()), str_replace('<CURDEF>BRL', '<CURDEF>USD', $this->ofx()), $this->ofx('x', '1.234')] as $content) {
            $i = $this->runImport($u, $content, 'ofx');
            $this->assertSame('failed', $i->status);
        }
        $this->assertSame(0, Transaction::count());
    }

    public function test_edited_values_cannot_duplicate_an_existing_transaction(): void
    {
        $u = User::factory()->create();
        $this->runImport($u, "date,description,amount,type\n2026-11-12,A,100,Receita\n2026-11-12,B,100,Receita\n");
        $t = Transaction::where('description', 'B')->first();
        $this->actingAs($u)->patchJson('/api/transactions/'.$t->id, ['description' => 'A', 'date' => '2026-11-12', 'amount' => 100, 'type' => 'income'])->assertStatus(409);
        $this->assertSame('B', $t->fresh()->description);
    }

    public function test_credit_card_institution_is_part_of_ofx_identity(): void
    {
        $u = User::factory()->create();
        $base = '<OFX><SIGNONMSGSRSV1><FI><ORG>Bank A</ORG><FID>{FID}</FID></FI></SIGNONMSGSRSV1><CCSTMTRS><CURDEF>BRL</CURDEF><CCACCTFROM><ACCTID>1234</ACCTID></CCACCTFROM><BANKTRANLIST><STMTTRN><DTPOSTED>20261112</DTPOSTED><TRNAMT>-10.00</TRNAMT><FITID>same-id</FITID><MEMO>Card</MEMO></STMTTRN></BANKTRANLIST></CCSTMTRS></OFX>';
        $this->runImport($u, str_replace('{FID}', 'A', $base), 'ofx');
        $this->runImport($u, str_replace('{FID}', 'B', $base), 'ofx');
        $this->assertSame(2, Transaction::count());
    }

    public function test_new_routes_require_authentication(): void
    {
        $this->patchJson('/api/transactions/1', [])->assertUnauthorized();
        $this->deleteJson('/api/transactions/1')->assertUnauthorized();
        $this->postJson('/api/transactions/1/restore')->assertUnauthorized();
        $this->getJson('/api/documentation/readme')->assertUnauthorized();
    }

    public function test_ofx_upload_is_queued_without_parsing_request(): void
    {
        Queue::fake();
        $u = User::factory()->create();
        $this->actingAs($u)->postJson('/api/imports', ['file' => UploadedFile::fake()->createWithContent('statement.ofx', 'not-an-ofx')])->assertAccepted()->assertJsonPath('import.format', 'ofx');
        Queue::assertPushed(ProcessImport::class, 1);
    }
}
