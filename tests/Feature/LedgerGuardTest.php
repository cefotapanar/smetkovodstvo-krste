<?php

namespace Tests\Feature;

use App\Exceptions\DocumentLocked;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Support\LedgerGuard;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Прокнижено не се менува — докажано на трите нивоа: модел, база, хеш.
 * Тестовите со DB::table намерно го заобиколуваат кодот: така би изгледал
 * рачен зафат во phpMyAdmin.
 */
class LedgerGuardTest extends TestCase
{
    use RefreshDatabase;

    private function posted(): array
    {
        [$firm, $user] = $this->ledgerFirm();
        $id = $this->entry($firm, $user, [['100', 100, 0], ['900', 0, 100]])->assertCreated()->json('entry.id');

        return [$firm, $user, $id];
    }

    public function test_bazata_odbiva_izmena_na_stavka(): void
    {
        [, , $id] = $this->posted();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage(LedgerGuard::TAG);
        DB::table('journal_lines')->where('journal_entry_id', $id)->update(['debit' => 999]);
    }

    public function test_bazata_odbiva_brishenje_na_nalog(): void
    {
        [, , $id] = $this->posted();

        $this->expectException(QueryException::class);
        DB::table('journal_entries')->where('id', $id)->delete();
    }

    public function test_bazata_odbiva_nova_stavka_vo_proknizhen(): void
    {
        [$firm, , $id] = $this->posted();

        $this->expectException(QueryException::class);
        DB::table('journal_lines')->insert([
            'journal_entry_id' => $id, 'line_no' => 3, 'account_id' => $this->acc($firm, '100'),
            'debit' => 1, 'credit' => 0,
        ]);
    }

    public function test_bazata_odbiva_vrakjanje_vo_nacrt(): void
    {
        [, , $id] = $this->posted();

        $this->expectException(QueryException::class);
        DB::table('journal_entries')->where('id', $id)->update(['status' => 'draft']);
    }

    public function test_modelot_odbiva_pred_bazata(): void
    {
        [, , $id] = $this->posted();

        $this->expectException(DocumentLocked::class);
        JournalLine::where('journal_entry_id', $id)->first()->update(['description' => 'x']);
    }

    public function test_nacrt_slobodno_se_menuva(): void
    {
        [$firm, $user] = $this->ledgerFirm();
        $id = $this->entry($firm, $user, [['100', 1, 0], ['900', 0, 1]], post: false)->json('entry.id');

        DB::table('journal_lines')->where('journal_entry_id', $id)->update(['description' => 'ок']);
        $this->assertSame(1, JournalEntry::whereKey($id)->delete());
    }

    public function test_integritet_ja_fakja_rachnata_izmena(): void
    {
        [$firm, $user, $id] = $this->posted();
        $this->entry($firm, $user, [['100', 50, 0], ['900', 0, 50]])->assertCreated();

        $this->as($user, $firm)->getJson('/api/v1/reports/integrity')
            ->assertOk()->assertJsonPath('chain_ok', true)->assertJsonPath('checked', 2)
            ->assertJsonPath('db_guard', true)->assertJsonPath('gaps', []);

        // Некој ги гаси тригерите и рачно менува износ (и на двете страни, за
        // бруто билансот да остане „во ред“ и да не се забележи).
        LedgerGuard::uninstall();
        DB::table('journal_lines')->where('journal_entry_id', $id)->where('line_no', 1)->update(['debit' => 1000]);
        DB::table('journal_lines')->where('journal_entry_id', $id)->where('line_no', 2)->update(['credit' => 1000]);

        $this->as($user, $firm)->getJson('/api/v1/reports/integrity')
            ->assertJsonPath('chain_ok', false)
            ->assertJsonPath('broken_at.entry_id', $id)
            ->assertJsonPath('db_guard', false);
    }

    public function test_greshka_od_bazata_e_409_ne_500(): void
    {
        [$firm, $user, $id] = $this->posted();

        // Глума на код со грешка што ја заобиколува заштитата на моделот.
        \Illuminate\Support\Facades\Route::middleware(['api', 'auth:sanctum', 'firm'])
            ->post('/api/v1/_rasipano', fn () => DB::table('journal_lines')->where('journal_entry_id', $id)->update(['debit' => 1]));

        $this->as($user, $firm)->postJson('/api/v1/_rasipano')->assertStatus(409)->assertJsonPath('error', 'locked');
    }
}
