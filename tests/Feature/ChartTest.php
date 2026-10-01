<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\JournalType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChartTest extends TestCase
{
    use RefreshDatabase;

    public function test_nova_firma_go_dobiva_zakonskiot_kontni_plan(): void
    {
        $r = $this->as($this->makeSuper())->postJson('/api/v1/system/firms', ['name' => 'Нова', 'tax_id' => '4030000000777', 'vat_period' => 'month'])
            ->assertCreated();
        $firmId = $r->json('firm.id');

        $this->assertSame(507, Account::where('firm_id', $firmId)->count());
        $this->assertSame(428, Account::where('firm_id', $firmId)->whereRaw('LENGTH(code) = 3')->count());
        $this->assertSame(10, JournalType::where('firm_id', $firmId)->count());
        $this->assertTrue(Account::where('firm_id', $firmId)->where('code', '120')->value('needs_partner'));
        $this->assertFalse(Account::where('firm_id', $firmId)->where('code', '100')->value('needs_partner'));
        $this->assertSame('Стоки на залиха', Account::where('firm_id', $firmId)->where('code', '660')->value('name'));
    }

    public function test_samo_listovi_od_3_cifri_se_za_knizhenje(): void
    {
        [$firm, $user] = $this->ledgerFirm();

        $rows = collect($this->as($user, $firm)->getJson('/api/v1/accounts')->assertOk()->json('data'))->keyBy('code');

        $this->assertTrue($rows['120']['is_postable']);
        $this->assertFalse($rows['12']['is_postable']);
        $this->assertFalse($rows['1']['is_postable']);
        $this->assertSame('group', $rows['12']['level']);

        $postable = collect($this->as($user, $firm)->getJson('/api/v1/accounts?postable=1')->json('data'));
        $this->assertSame(428, $postable->count());
    }

    public function test_analitika_go_nasleduva_partnerot_i_go_zatvora_roditelot(): void
    {
        [$firm, $user] = $this->ledgerFirm();

        $this->as($user, $firm)->postJson('/api/v1/accounts', ['code' => '1200', 'name' => 'Купувачи — малопродажба'])
            ->assertCreated()
            ->assertJsonPath('account.level', 'analytic')
            ->assertJsonPath('account.needs_partner', true)
            ->assertJsonPath('account.is_postable', true);

        $rows = collect($this->as($user, $firm)->getJson('/api/v1/accounts?q=120')->json('data'))->keyBy('code');
        $this->assertFalse($rows['120']['is_postable']);
    }

    public function test_analitika_samo_pod_postoechka_sintetika(): void
    {
        [$firm, $user] = $this->ledgerFirm();

        $this->as($user, $firm)->postJson('/api/v1/accounts', ['code' => '5555', 'name' => 'X'])->assertStatus(422);
        $this->as($user, $firm)->postJson('/api/v1/accounts', ['code' => '12', 'name' => 'X'])->assertStatus(422);
        $this->as($user, $firm)->postJson('/api/v1/accounts', ['code' => '1200', 'name' => 'X'])->assertCreated();
        $this->as($user, $firm)->postJson('/api/v1/accounts', ['code' => '1200', 'name' => 'X'])->assertStatus(422);
    }

    public function test_analitika_ne_se_otvora_pod_konto_so_knizhenje(): void
    {
        [$firm, $user] = $this->ledgerFirm();
        $this->entry($firm, $user, [['100', 100, 0], ['900', 0, 100]])->assertCreated();

        $this->as($user, $firm)->postJson('/api/v1/accounts', ['code' => '1000', 'name' => 'Банка 1'])
            ->assertStatus(422)->assertJson(fn ($j) => $j->where('errors.code.0', fn ($m) => str_contains($m, 'веќе има книжење'))->etc());
    }

    public function test_zakonska_smetka_ne_se_preimenuva_ne_se_brishe(): void
    {
        [$firm, $user] = $this->ledgerFirm();
        $id = $this->acc($firm, '100');

        $this->as($user, $firm)->putJson("/api/v1/accounts/$id", ['name' => 'Ново'])->assertStatus(422);
        $this->as($user, $firm)->putJson("/api/v1/accounts/$id", ['code' => '1009'])->assertStatus(422);
        $this->as($user, $firm)->deleteJson("/api/v1/accounts/$id")->assertStatus(409)->assertJsonPath('error', 'locked');
        // Деактивирање смее.
        $this->as($user, $firm)->putJson("/api/v1/accounts/$id", ['is_active' => false])->assertOk()->assertJsonPath('account.is_active', false);
    }

    public function test_konto_so_knizhenje_ne_se_brishe(): void
    {
        [$firm, $user] = $this->ledgerFirm();
        $this->as($user, $firm)->postJson('/api/v1/accounts', ['code' => '1000', 'name' => 'Банка 1'])->assertCreated();
        $this->entry($firm, $user, [['1000', 100, 0], ['900', 0, 100]])->assertCreated();

        $this->as($user, $firm)->deleteJson('/api/v1/accounts/'.$this->acc($firm, '1000'))->assertStatus(409);
    }

    public function test_tugji_konta_ne_se_gledaat(): void
    {
        [$a, $userA] = $this->ledgerFirm();
        [$b] = $this->ledgerFirm();

        $this->as($userA, $a)->putJson('/api/v1/accounts/'.$this->acc($b, '100'), ['is_active' => false])->assertNotFound();
        $this->as($userA, $a)->getJson('/api/v1/cards/account/'.$this->acc($b, '100'))->assertNotFound();
    }

    public function test_partneri_edb_edinstven_vo_firmata(): void
    {
        [$firm, $user] = $this->ledgerFirm();

        $id = $this->as($user, $firm)->postJson('/api/v1/partners', ['name' => 'Купувач ДОО', 'tax_id' => '4030000000123', 'country' => 'mk'])
            ->assertCreated()->assertJsonPath('partner.country', 'MK')->json('partner.id');
        $this->as($user, $firm)->postJson('/api/v1/partners', ['name' => 'Друг', 'tax_id' => '4030000000123'])
            ->assertStatus(422)->assertJsonPath('errors.tax_id.0', 'Партнер со овој ЕДБ веќе постои.');
        $this->as($user, $firm)->putJson("/api/v1/partners/$id", ['name' => 'Купувач ДОО Скопје', 'tax_id' => '4030000000123'])->assertOk();

        $this->as($user, $firm)->getJson('/api/v1/partners?q=Скопје')->assertJsonPath('meta.total', 1);
    }
}
