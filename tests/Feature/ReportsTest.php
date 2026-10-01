<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Еден трговски месец, пресметан рачно однапред — извештаите мора да го
 * дадат точно тоа. (Доказот од фаза 2 во планот.)
 *
 *   01.01 ПС   основачки капитал на банка                 100 / 900   50.000
 *   05.01 ВФ   набавка 10.000 + ДДВ 1.800 од С            650,130 / 220   11.800
 *   06.01 КЛ   приемница                                  660 / 650   10.000
 *   10.01 ИФ   продажба 15.000 + ДДВ 2.700 на К           120 / 741,230   17.700
 *   10.01 КЛ   набавна вредност на продаденото            701 / 660    9.000
 *   20.01 ИЗ   К плаќа                                    100 / 120   17.700
 *   21.01 ИЗ   плаќаме на С                               220 / 100   11.800
 *   21.01 ИЗ   провизија на банка                         449 / 100      300
 *
 * Очекувано салдо: банка 55.600 Д, залиха 1.000 Д, ДДВ 1.800 Д / 2.700 П,
 * набавна 9.000 Д, провизија 300 Д, приход 15.000 П, капитал 50.000 П —
 * добивка 5.700. Купувачи, добавувачи и 650 — нула.
 */
class ReportsTest extends TestCase
{
    use RefreshDatabase;

    private function month(): array
    {
        [$f, $u] = $this->ledgerFirm();
        $s = $this->partner($f, 'Добавувач С');
        $k = $this->partner($f, 'Купувач К');

        $this->entry($f, $u, [['100', 50000, 0], ['900', 0, 50000]], 'ПС', '2027-01-01')->assertCreated();
        $this->entry($f, $u, [['650', 10000, 0], ['130', 1800, 0], ['220', 0, 11800, $s]], 'ВФ', '2027-01-05')->assertCreated();
        $this->entry($f, $u, [['660', 10000, 0], ['650', 0, 10000]], 'КЛ', '2027-01-06')->assertCreated();
        $this->entry($f, $u, [['120', 17700, 0, $k], ['741', 0, 15000], ['230', 0, 2700]], 'ИФ', '2027-01-10')->assertCreated();
        $this->entry($f, $u, [['701', 9000, 0], ['660', 0, 9000]], 'КЛ', '2027-01-10')->assertCreated();
        $this->entry($f, $u, [['100', 17700, 0], ['120', 0, 17700, $k]], 'ИЗ', '2027-01-20')->assertCreated();
        $this->entry($f, $u, [['220', 11800, 0, $s], ['100', 0, 11800]], 'ИЗ', '2027-01-21')->assertCreated();
        $this->entry($f, $u, [['449', 300, 0], ['100', 0, 300]], 'ИЗ', '2027-01-21')->assertCreated();

        return [$f, $u, $s, $k];
    }

    public function test_bruto_bilans_po_konto(): void
    {
        [$f, $u] = $this->month();

        $r = $this->as($u, $f)->getJson('/api/v1/reports/trial-balance?from=2027-01-01&to=2027-01-31')->assertOk();
        $rows = collect($r->json('rows'))->keyBy('code');

        $bal = fn ($code) => $rows[$code]['balance_debit'] - $rows[$code]['balance_credit'];

        $this->assertEquals(55600, $bal('100'));
        $this->assertEquals(0, $bal('120'));
        $this->assertEquals(1800, $bal('130'));
        $this->assertEquals(0, $bal('220'));
        $this->assertEquals(-2700, $bal('230'));
        $this->assertEquals(0, $bal('650'));
        $this->assertEquals(1000, $bal('660'));
        $this->assertEquals(9000, $bal('701'));
        $this->assertEquals(-15000, $bal('741'));
        $this->assertEquals(300, $bal('449'));
        $this->assertEquals(-50000, $bal('900'));

        // ПС оди во „почетна“, не во „промет“.
        $this->assertEquals(50000, $rows['100']['opening_debit']);
        $this->assertEquals(17700, $rows['100']['debit']);
        $this->assertEquals(12100, $rows['100']['credit']);

        $r->assertJsonPath('balanced', true)
            ->assertJsonPath('totals.opening_debit', 50000)
            ->assertJsonPath('totals.debit', 78300)
            ->assertJsonPath('totals.credit', 78300)
            ->assertJsonPath('totals.balance_debit', 67700)
            ->assertJsonPath('totals.balance_credit', 67700);

        // Добивката: класа 7 (приходи − набавна) и 4 (трошоци).
        $this->assertEquals(5700, -($bal('741') + $bal('701') + $bal('449')));
    }

    public function test_istite_zbirovi_na_sekoe_nivo(): void
    {
        [$f, $u] = $this->month();

        foreach (['analytic', 'synthetic', 'group', 'class'] as $level) {
            $this->as($u, $f)->getJson("/api/v1/reports/trial-balance?from=2027-01-01&to=2027-01-31&level=$level")
                ->assertJsonPath('totals.debit', 78300)->assertJsonPath('balanced', true);
        }

        $classes = collect($this->as($u, $f)->getJson('/api/v1/reports/trial-balance?from=2027-01-01&to=2027-01-31&level=class')->json('rows'))->keyBy('code');
        // keyBy ги претвора „1“, „2“ во броеви — затоа се споредуваат кодовите од редовите.
        $this->assertSame(['1', '2', '4', '6', '7', '9'], $classes->pluck('code')->values()->all());
        $this->assertSame('class', $classes[1]['level']);
    }

    public function test_pochetna_sostojba_vo_sredina_na_mesec(): void
    {
        [$f, $u] = $this->month();

        // Од 15.01: сè до 14.01 е почетна. На банка до тогаш нема ништо освен ПС.
        $rows = collect($this->as($u, $f)->getJson('/api/v1/reports/trial-balance?from=2027-01-15&to=2027-01-31')->json('rows'))->keyBy('code');
        $this->assertEquals(17700, $rows['120']['opening_debit']);
        $this->assertEquals(17700, $rows['120']['credit']);
        $this->assertEquals(0, $rows['120']['debit']);
    }

    public function test_kartichka_na_banka(): void
    {
        [$f, $u] = $this->month();

        $c = $this->as($u, $f)->getJson('/api/v1/cards/account/'.$this->acc($f, '100').'?from=2027-01-01&to=2027-01-31')->assertOk();

        $c->assertJsonPath('opening.balance', 50000)
            ->assertJsonCount(3, 'lines')
            ->assertJsonPath('lines.0.debit', 17700)
            ->assertJsonPath('lines.0.balance', 67700)
            ->assertJsonPath('lines.2.balance', 55600)
            ->assertJsonPath('totals.balance', 55600);
    }

    public function test_kartichka_na_sintetika_gi_opfakja_podkontata(): void
    {
        [$f, $u] = $this->month();

        $c = $this->as($u, $f)->getJson('/api/v1/cards/account/'.$this->acc($f, '10').'?from=2027-01-01&to=2027-01-31')->assertOk();
        $c->assertJsonPath('totals.balance', 55600)->assertJsonPath('lines.0.account.code', '100');
    }

    public function test_kartichka_na_partner(): void
    {
        [$f, $u, $s, $k] = $this->month();

        $this->as($u, $f)->getJson("/api/v1/cards/partner/$k?from=2027-01-01&to=2027-01-31")
            ->assertOk()
            ->assertJsonCount(2, 'lines')
            ->assertJsonPath('lines.0.account.code', '120')
            ->assertJsonPath('lines.0.balance', 17700)
            ->assertJsonPath('totals.balance', 0);

        $this->as($u, $f)->getJson("/api/v1/cards/partner/$s?from=2027-01-01&to=2027-01-31")
            ->assertJsonPath('lines.0.balance', -11800)->assertJsonPath('totals.balance', 0);
    }

    public function test_dnevnik_i_glavna_kniga(): void
    {
        [$f, $u] = $this->month();

        $d = $this->as($u, $f)->getJson('/api/v1/reports/journal-book?from=2027-01-01&to=2027-01-31')->assertOk();
        $this->assertSame(range(1, 8), array_column($d->json('entries'), 'posting_seq'));
        $d->assertJsonPath('totals.debit', 128300)->assertJsonPath('totals.credit', 128300)
            ->assertJsonPath('entries.0.label', 'ПС-1/2027');

        $g = $this->as($u, $f)->getJson('/api/v1/reports/general-ledger?from=2027-01-01&to=2027-01-31')->assertOk();
        $this->assertSame(['100', '120', '130', '220', '230', '449', '650', '660', '701', '741', '900'], array_column(array_column($g->json('accounts'), 'account'), 'code'));
    }

    public function test_period_vo_edna_godina(): void
    {
        [$f, $u] = $this->month();

        $this->as($u, $f)->getJson('/api/v1/reports/trial-balance?from=2027-12-01&to=2028-01-31')->assertStatus(422);
        $this->as($u, $f)->getJson('/api/v1/reports/trial-balance?from=2027-02-01&to=2027-01-31')->assertStatus(422);
    }

    public function test_integritet_po_mesecot(): void
    {
        [$f, $u] = $this->month();

        $this->as($u, $f)->getJson('/api/v1/reports/integrity')
            ->assertJsonPath('chain_ok', true)->assertJsonPath('checked', 8)->assertJsonPath('gaps', []);
    }
}
