<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OpenItemsTest extends TestCase
{
    use RefreshDatabase;

    /** Фактура 10.000 и уплата 6.000 од ист купувач. */
    private function scenario(): array
    {
        [$firm, $user] = $this->ledgerFirm();
        $p = $this->partner($firm, 'Купувач');
        $inv = $this->entry($firm, $user, [['120', 10000, 0, $p], ['741', 0, 10000]], 'ИФ')->json('entry');
        $pay = $this->entry($firm, $user, [['100', 6000, 0], ['120', 0, 6000, $p]], 'ИЗ')->json('entry');

        return [$firm, $user, $p, $inv['lines'][0]['id'], $pay['lines'][1]['id'], $inv['id']];
    }

    private function open($firm, $user, int $p, bool $all = false): array
    {
        return $this->as($user, $firm)->getJson("/api/v1/open-items?partner_id=$p".($all ? '&all=1' : ''))->assertOk()->json('data');
    }

    public function test_otvoreni_stavki_so_znak(): void
    {
        [$firm, $user, $p] = $this->scenario();

        $rows = $this->open($firm, $user, $p);
        $this->assertSame([10000, -6000], array_column($rows, 'remaining'));
        $this->assertSame('ИФ-1/2027', $rows[0]['label']);
    }

    public function test_zatvoranje_po_podrazbirane_kolku_shto_mozhe(): void
    {
        [$firm, $user, $p, $inv, $pay] = $this->scenario();

        $this->as($user, $firm)->postJson('/api/v1/open-items/match', ['line_id' => $inv, 'other_line_id' => $pay])
            ->assertCreated()->assertJsonPath('match.amount', 6000);

        $rows = $this->open($firm, $user, $p);
        $this->assertCount(1, $rows);
        $this->assertEquals(4000, $rows[0]['remaining']);
        $this->assertEquals(6000, $rows[0]['matched']);
    }

    public function test_pravila_na_zatvoranje(): void
    {
        [$firm, $user, $p, $inv, $pay] = $this->scenario();
        $drug = $this->partner($firm, 'Друг');
        $tugja = $this->entry($firm, $user, [['100', 500, 0], ['120', 0, 500, $drug]], 'ИЗ')->json('entry.lines.1.id');
        $ista = $this->entry($firm, $user, [['120', 300, 0, $p], ['741', 0, 300]], 'ИФ')->json('entry.lines.0.id');

        $m = fn ($a, $b, $amt = null) => $this->as($user, $firm)->postJson('/api/v1/open-items/match', array_filter(['line_id' => $a, 'other_line_id' => $b, 'amount' => $amt]));

        $m($inv, $tugja)->assertStatus(422);          // друг партнер
        $m($inv, $ista)->assertStatus(422);           // ист знак
        $m($inv, $pay, 7000)->assertStatus(422);      // повеќе од остатокот на уплатата
        $m($inv, $pay, 2500)->assertCreated();
        $m($inv, $pay, 3500)->assertCreated();
        $m($inv, $pay)->assertStatus(422);            // уплатата е потрошена
    }

    public function test_otvoranje(): void
    {
        [$firm, $user, $p, $inv, $pay] = $this->scenario();
        $id = $this->as($user, $firm)->postJson('/api/v1/open-items/match', ['line_id' => $inv, 'other_line_id' => $pay])->json('match.id');

        $this->as($user, $firm)->deleteJson("/api/v1/open-items/match/$id")->assertOk();
        $this->assertCount(2, $this->open($firm, $user, $p));
    }

    public function test_storno_ja_otvora_uplatata_i_ja_zatvora_fakturata(): void
    {
        [$firm, $user, $p, $inv, $pay, $invEntry] = $this->scenario();
        $this->as($user, $firm)->postJson('/api/v1/open-items/match', ['line_id' => $inv, 'other_line_id' => $pay])->assertCreated();

        $this->as($user, $firm)->postJson("/api/v1/journal/$invEntry/storno")->assertCreated();

        $rows = $this->open($firm, $user, $p);
        $this->assertCount(1, $rows);
        $this->assertSame($pay, $rows[0]['line_id']);
        $this->assertEquals(-6000, $rows[0]['remaining']);
        // Со `all` се гледаат и фактурата и нејзиното сторно — затворени меѓусебно.
        $this->assertCount(3, $this->open($firm, $user, $p, true));
    }
}
