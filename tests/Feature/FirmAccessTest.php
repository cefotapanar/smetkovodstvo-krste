<?php

namespace Tests\Feature;

use App\Models\Firm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Изолација на фирми и дозволи по фирма — темелот за сè што доаѓа:
 * налог во погрешна фирма е најскапата грешка во книгите.
 */
class FirmAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Пробни рути: вистински со `permission:` уште нема (фаза 2), а
        // middleware-от мора да се докаже сега, додека е мал.
        Route::middleware(['api', 'auth:sanctum', 'active', 'firm', 'permission:journal'])->group(function () {
            Route::get('/api/v1/_proba', fn () => ['firm' => Firm::current()->id]);
            Route::post('/api/v1/_proba', fn () => ['ok' => true]);
        });
        Route::middleware(['api', 'auth:sanctum', 'permission:journal'])
            ->get('/api/v1/_proba_bez_firma', fn () => ['ok' => true]);
    }

    public function test_bez_x_firm_400(): void
    {
        $this->as($this->makeSuper())->getJson('/api/v1/firm')
            ->assertStatus(400)->assertJsonPath('error', 'firm_required');
    }

    public function test_tugja_i_nepostoechka_firma_ist_odgovor(): void
    {
        $user = $this->makeUser();
        $nasha = $this->makeFirm();
        $tugja = $this->makeFirm();
        $this->grant($user, $nasha, ['journal']);

        $a = $this->as($user, $tugja)->getJson('/api/v1/firm');
        $b = $this->as($user)->withHeader('X-Firm', '99999')->getJson('/api/v1/firm');

        $a->assertStatus(403)->assertJsonPath('error', 'firm_forbidden');
        $b->assertStatus(403)->assertJsonPath('error', 'firm_forbidden');
        $this->assertSame($a->json('message'), $b->json('message'));
    }

    public function test_svoja_firma_so_ulogata_vo_nea(): void
    {
        $user = $this->makeUser();
        $firm = $this->makeFirm(['name' => 'Наша']);
        $role = $this->grant($user, $firm, ['cards', 'journal']);

        $this->as($user, $firm)->getJson('/api/v1/firm')
            ->assertOk()
            ->assertJsonPath('firm.name', 'Наша')
            ->assertJsonPath('firm.role', $role->name)
            ->assertJsonPath('firm.permissions', ['cards', 'journal']);
    }

    public function test_neaktivna_firma_zatvorena_za_obichen_korisnik_ne_za_admin(): void
    {
        $user = $this->makeUser();
        $firm = $this->makeFirm(['is_active' => false]);
        $this->grant($user, $firm, ['journal']);

        $this->as($user, $firm)->getJson('/api/v1/firm')->assertStatus(403);
        $this->as($this->makeSuper(), $firm)->getJson('/api/v1/firm')->assertOk();
    }

    public function test_pregled_bez_zapishuvanje(): void
    {
        $user = $this->makeUser();
        $firm = $this->makeFirm();
        $this->grant($user, $firm, ['journal']);

        $this->as($user, $firm)->getJson('/api/v1/_proba')->assertOk()->assertJsonPath('firm', $firm->id);
        $this->as($user, $firm)->postJson('/api/v1/_proba')->assertStatus(403)->assertJsonPath('error', 'forbidden');
    }

    public function test_zapishuvanje_so_write(): void
    {
        $user = $this->makeUser();
        $firm = $this->makeFirm();
        $this->grant($user, $firm, ['journal', 'journal.write']);

        $this->as($user, $firm)->postJson('/api/v1/_proba')->assertOk();
    }

    public function test_pravata_vo_edna_firma_ne_vazhat_vo_druga(): void
    {
        $user = $this->makeUser();
        $a = $this->makeFirm();
        $b = $this->makeFirm();
        $this->grant($user, $a, ['journal', 'journal.write']);
        $this->grant($user, $b, ['cards']);

        $this->as($user, $a)->postJson('/api/v1/_proba')->assertOk();
        $this->as($user, $b)->getJson('/api/v1/_proba')->assertStatus(403);
    }

    public function test_firma_bez_uloga_nema_nitu_edno_pravo(): void
    {
        $user = $this->makeUser();
        $firm = $this->makeFirm();
        $user->firms()->attach($firm->id, ['role_id' => null]);

        $this->as($user, $firm)->getJson('/api/v1/firm')->assertOk()->assertJsonPath('firm.permissions', []);
        $this->as($user, $firm)->getJson('/api/v1/_proba')->assertStatus(403);
    }

    public function test_izbrishana_dozvola_od_katalogot_ne_dava_pravo(): void
    {
        $user = $this->makeUser();
        $firm = $this->makeFirm();
        $this->grant($user, $firm, ['stara_dozvola', 'journal']);

        $this->as($user, $firm)->getJson('/api/v1/firm')->assertJsonPath('firm.permissions', ['journal']);
    }

    public function test_permission_bez_firm_pagja_glasno(): void
    {
        $this->withoutExceptionHandling();
        $this->expectException(\LogicException::class);

        $this->as($this->makeSuper())->getJson('/api/v1/_proba_bez_firma');
    }

    public function test_firma_od_prethodno_baranje_ne_ostanuva(): void
    {
        $user = $this->makeUser();
        $firm = $this->makeFirm();
        $this->grant($user, $firm, ['journal']);

        $this->as($user, $firm)->getJson('/api/v1/_proba')->assertOk();
        // Следното барање е без X-Firm — не смее да ја наследи фирмата од претходното.
        $this->as($user)->getJson('/api/v1/firm')->assertStatus(400);
    }
}
