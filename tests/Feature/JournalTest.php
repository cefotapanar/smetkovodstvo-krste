<?php

namespace Tests\Feature;

use App\Models\JournalEntry;
use App\Models\PeriodLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Правилата на книжењето од §6.2 на планот — секое со свој тест. */
class JournalTest extends TestCase
{
    use RefreshDatabase;

    public function test_nacrt_smee_da_ne_e_vo_ramnotezha(): void
    {
        [$firm, $user] = $this->ledgerFirm();

        $this->entry($firm, $user, [['100', 100, 0], ['900', 0, 90]], post: false)
            ->assertCreated()
            ->assertJsonPath('entry.status', 'draft')
            ->assertJsonPath('entry.number', null)
            ->assertJson(fn ($j) => $j->where('entry.label', fn ($l) => str_starts_with($l, 'Нацрт #'))->etc())
            ->assertJsonPath('entry.balanced', false);
    }

    public function test_ne_se_knizhi_bez_ramnotezha(): void
    {
        [$firm, $user] = $this->ledgerFirm();
        $id = $this->entry($firm, $user, [['100', '100.50', 0], ['900', 0, '100.49']], post: false)->json('entry.id');

        $this->as($user, $firm)->postJson("/api/v1/journal/$id/post")
            ->assertStatus(422)
            ->assertJsonPath('errors.lines.0', 'Налогот не е во рамнотежа: должи 100,50, побарува 100,49, разлика 0,01.');
    }

    public function test_site_greshki_odednash(): void
    {
        [$firm, $user] = $this->ledgerFirm();

        $r = $this->entry($firm, $user, [['120', 100, 0], ['12', 0, 50], ['900', 50, 50]])->assertStatus(422);

        $r->assertJsonStructure(['errors' => ['lines.0.partner_id', 'lines.1.account_id', 'lines.2.debit']]);
        // `post: true` е атомско — неуспешно книжење не остава ни нацрт.
        $this->assertSame(0, JournalEntry::count());
    }

    public function test_nula_stavka_i_edna_stavka_odbieni(): void
    {
        [$firm, $user] = $this->ledgerFirm();

        $this->entry($firm, $user, [['100', 0, 0], ['900', 0, 0]])->assertStatus(422)->assertJsonStructure(['errors' => ['lines.0.debit']]);
        $this->entry($firm, $user, [['100', 0, 0]])->assertStatus(422)->assertJsonStructure(['errors' => ['lines']]);
    }

    public function test_tri_decimali_odbieni(): void
    {
        [$firm, $user] = $this->ledgerFirm();

        $this->entry($firm, $user, [['100', '1.005', 0], ['900', 0, '1.005']])->assertStatus(422);
    }

    public function test_knizhenje_dava_broj_po_vid_i_red_vo_dnevnik(): void
    {
        [$firm, $user] = $this->ledgerFirm();

        $this->entry($firm, $user, [['100', 100, 0], ['900', 0, 100]], 'ИЗ')
            ->assertCreated()->assertJsonPath('entry.number', 1)->assertJsonPath('entry.posting_seq', 1)->assertJsonPath('entry.label', 'ИЗ-1/2027');
        $this->entry($firm, $user, [['100', 100, 0], ['900', 0, 100]], 'РН')
            ->assertJsonPath('entry.number', 1)->assertJsonPath('entry.posting_seq', 2);
        $this->entry($firm, $user, [['100', 100, 0], ['900', 0, 100]], 'ИЗ')
            ->assertJsonPath('entry.label', 'ИЗ-2/2027')->assertJsonPath('entry.posting_seq', 3);
        // Нова година — броевите почнуваат од 1.
        $this->entry($firm, $user, [['100', 100, 0], ['900', 0, 100]], 'ИЗ', '2028-01-02')
            ->assertJsonPath('entry.label', 'ИЗ-1/2028')->assertJsonPath('entry.posting_seq', 1);
    }

    public function test_izbrishan_nacrt_ne_ostava_dupka(): void
    {
        [$firm, $user] = $this->ledgerFirm();
        $a = $this->entry($firm, $user, [['100', 1, 0], ['900', 0, 1]], post: false)->json('entry.id');
        $b = $this->entry($firm, $user, [['100', 2, 0], ['900', 0, 2]], post: false)->json('entry.id');

        $this->as($user, $firm)->deleteJson("/api/v1/journal/$a")->assertOk();
        $this->as($user, $firm)->postJson("/api/v1/journal/$b/post")->assertOk()->assertJsonPath('entry.number', 1);
    }

    public function test_proknizhen_ne_se_menuva_ne_se_brishe_ne_se_knizhi_pak(): void
    {
        [$firm, $user] = $this->ledgerFirm();
        $id = $this->entry($firm, $user, [['100', 100, 0], ['900', 0, 100]])->json('entry.id');
        $body = ['journal_type_id' => $this->type($firm, 'РН'), 'date' => '2027-01-15', 'lines' => []];

        $this->as($user, $firm)->putJson("/api/v1/journal/$id", $body)->assertStatus(409)->assertJsonPath('error', 'locked');
        $this->as($user, $firm)->deleteJson("/api/v1/journal/$id")->assertStatus(409);
        $this->as($user, $firm)->postJson("/api/v1/journal/$id/post")->assertStatus(409);
    }

    public function test_redosledot_na_stavkite_e_kako_na_ekranot(): void
    {
        [$firm, $user] = $this->ledgerFirm();

        $r = $this->entry($firm, $user, [['900', 0, 30], ['100', 10, 0], ['102', 20, 0]])->assertCreated();

        $this->assertSame(['900', '100', '102'], array_column(array_column($r->json('entry.lines'), 'account'), 'code'));
    }

    public function test_zakluchen_mesec(): void
    {
        [$firm, $user] = $this->ledgerFirm();
        $draft = $this->entry($firm, $user, [['100', 1, 0], ['900', 0, 1]], date: '2027-02-10', post: false)->json('entry.id');

        $this->as($user, $firm)->postJson('/api/v1/periods/lock', ['year' => 2027, 'month' => 2])->assertOk();

        $this->entry($firm, $user, [['100', 1, 0], ['900', 0, 1]], date: '2027-02-11', post: false)
            ->assertStatus(422)->assertJsonPath('errors.date.0', 'Месецот 02/2027 е заклучен — изберете датум во отворен период.');
        $this->as($user, $firm)->postJson("/api/v1/journal/$draft/post")->assertStatus(422)->assertJsonStructure(['errors' => ['date']]);
        $this->entry($firm, $user, [['100', 1, 0], ['900', 0, 1]], date: '2027-03-01')->assertCreated();

        $this->as($user, $firm)->getJson('/api/v1/periods?year=2027')->assertJsonPath('data.1.locked', true)->assertJsonPath('data.2.locked', false);
        $this->as($user, $firm)->postJson('/api/v1/periods/unlock', ['year' => 2027, 'month' => 2])->assertOk();
        $this->as($user, $firm)->postJson("/api/v1/journal/$draft/post")->assertOk();
    }

    public function test_crveno_storno(): void
    {
        [$firm, $user] = $this->ledgerFirm();
        $p = $this->partner($firm, 'Купувач');
        $id = $this->entry($firm, $user, [['120', 1180, 0, $p], ['741', 0, 1000], ['230', 0, 180]], 'ИФ')->json('entry.id');

        $s = $this->as($user, $firm)->postJson("/api/v1/journal/$id/storno")
            ->assertCreated()
            ->assertJsonPath('entry.status', 'posted')
            ->assertJsonPath('entry.storno_of_id', $id)
            ->assertJsonPath('entry.label', 'ИФ-2/2027')
            ->assertJsonPath('entry.lines.0.debit', -1180)
            ->assertJsonPath('entry.lines.1.credit', -1000)
            ->assertJsonPath('entry.description', 'Сторно на ИФ-1/2027');

        $this->as($user, $firm)->getJson("/api/v1/journal/$id")->assertJsonPath('entry.stornoed_by_id', $s->json('entry.id'));
        $this->as($user, $firm)->postJson("/api/v1/journal/$id/storno")->assertStatus(409);
        $this->as($user, $firm)->postJson('/api/v1/journal/'.$s->json('entry.id').'/storno')->assertStatus(409);

        // Прометот е 0, не 1180 на двете страни.
        $tb = collect($this->as($user, $firm)->getJson('/api/v1/reports/trial-balance?from=2027-01-01&to=2027-12-31')->json('rows'))->keyBy('code');
        $this->assertEquals(0, $tb['120']['debit'] ?? 0);
    }

    public function test_storno_vo_zakluchen_mesec_bara_datum(): void
    {
        [$firm, $user] = $this->ledgerFirm();
        $id = $this->entry($firm, $user, [['100', 5, 0], ['900', 0, 5]], date: '2027-01-20')->json('entry.id');
        PeriodLock::create(['firm_id' => $firm->id, 'year' => 2027, 'month' => 1]);

        $this->as($user, $firm)->postJson("/api/v1/journal/$id/storno")
            ->assertStatus(422)->assertJsonPath('errors.date.0', 'Месецот на оригиналот (01/2027) е заклучен — изберете датум на сторното во отворен период.');
        $this->as($user, $firm)->postJson("/api/v1/journal/$id/storno", ['date' => '2027-02-01'])
            ->assertCreated()->assertJsonPath('entry.date', '2027-02-01');
    }

    public function test_storno_e_dozvoleno_i_na_deaktivirano_konto(): void
    {
        [$firm, $user] = $this->ledgerFirm();
        $id = $this->entry($firm, $user, [['100', 5, 0], ['900', 0, 5]])->json('entry.id');
        $this->as($user, $firm)->putJson('/api/v1/accounts/'.$this->acc($firm, '900'), ['is_active' => false])->assertOk();

        $this->as($user, $firm)->postJson("/api/v1/journal/$id/storno")->assertCreated();
    }

    public function test_tugji_shifri_vo_stavkite_odbieni(): void
    {
        [$a, $user] = $this->ledgerFirm();
        [$b] = $this->ledgerFirm();

        $this->as($user, $a)->postJson('/api/v1/journal', [
            'journal_type_id' => $this->type($a, 'РН'), 'date' => '2027-01-15',
            'lines' => [['account_id' => $this->acc($b, '100'), 'debit' => 1], ['account_id' => $this->acc($a, '900'), 'credit' => 1]],
        ])->assertStatus(422)->assertJsonStructure(['errors' => ['lines.0.account_id']]);

        $this->as($user, $a)->postJson('/api/v1/journal', ['journal_type_id' => $this->type($b, 'РН'), 'date' => '2027-01-15'])
            ->assertStatus(422)->assertJsonStructure(['errors' => ['journal_type_id']]);
    }

    public function test_samo_pregled_ne_knizhi(): void
    {
        [$firm] = $this->ledgerFirm();
        $viewer = $this->makeUser();
        $this->grant($viewer, $firm, ['journal', 'reports', 'cards']);

        $this->entry($firm, $viewer, [['100', 1, 0], ['900', 0, 1]])->assertStatus(403);
        $this->as($viewer, $firm)->getJson('/api/v1/journal')->assertOk();
        $this->as($viewer, $firm)->getJson('/api/v1/reports/trial-balance')->assertOk();
        $this->as($viewer, $firm)->postJson('/api/v1/periods/lock', ['year' => 2027, 'month' => 1])->assertStatus(403);
    }

    public function test_spisok_so_filtri(): void
    {
        [$firm, $user] = $this->ledgerFirm();
        $this->entry($firm, $user, [['100', 1, 0], ['900', 0, 1]], 'ИЗ', '2027-01-05');
        $this->entry($firm, $user, [['100', 2, 0], ['900', 0, 2]], 'РН', '2027-02-05', post: false);

        $this->as($user, $firm)->getJson('/api/v1/journal')->assertJsonPath('meta.total', 2);
        $this->as($user, $firm)->getJson('/api/v1/journal?status=posted')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.total_debit', 1);
        $this->as($user, $firm)->getJson('/api/v1/journal?from=2027-02-01')->assertJsonPath('meta.total', 1);
    }
}
