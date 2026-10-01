<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_ping_bez_najava(): void
    {
        $this->getJson('/api/v1/ping')
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('server.version', config('version.number'))
            ->assertJsonPath('server.local_app_min', config('version.local_app_min'));
    }

    public function test_pogreshna_lozinka_i_nepostoechka_eposhta_davaat_ista_poraka(): void
    {
        $this->makeUser(['email' => 'a@proba.mk']);

        $a = $this->postJson('/api/v1/login', ['email' => 'a@proba.mk', 'password' => 'pogresna-1', 'device_name' => 'PC']);
        $b = $this->postJson('/api/v1/login', ['email' => 'nema@proba.mk', 'password' => 'pogresna-1', 'device_name' => 'PC']);

        $a->assertStatus(401)->assertJsonPath('error', 'invalid_credentials');
        $b->assertStatus(401);
        $this->assertSame($a->json('message'), $b->json('message'));
    }

    public function test_najava_vrakja_token_profil_i_firmi(): void
    {
        $user = $this->makeUser(['email' => 'a@proba.mk']);
        $firm = $this->makeFirm(['name' => 'Наша фирма']);
        $this->grant($user, $firm, ['journal', 'journal.write']);
        $this->makeFirm(); // туѓа — не смее да се види

        $r = $this->postJson('/api/v1/login', ['email' => 'a@proba.mk', 'password' => 'lozinka-123', 'device_name' => 'PC-1'])
            ->assertOk()
            ->assertJsonPath('user.email', 'a@proba.mk')
            ->assertJsonCount(1, 'firms')
            ->assertJsonPath('firms.0.name', 'Наша фирма')
            ->assertJsonPath('firms.0.permissions', ['journal', 'journal.write']);

        $this->assertNotEmpty($r->json('token'));
    }

    public function test_deaktiviran_ne_dobiva_token(): void
    {
        $this->makeUser(['email' => 'a@proba.mk', 'is_active' => false]);

        $this->postJson('/api/v1/login', ['email' => 'a@proba.mk', 'password' => 'lozinka-123', 'device_name' => 'PC'])
            ->assertStatus(403)->assertJsonPath('error', 'inactive');
    }

    public function test_deaktiviran_so_star_token_e_odbien(): void
    {
        $user = $this->makeUser();
        $req = $this->as($user);
        $user->forceFill(['is_active' => false])->save();

        $req->getJson('/api/v1/me')->assertStatus(403)->assertJsonPath('error', 'inactive');
    }

    public function test_povtorna_najava_od_ist_ured_go_brishe_stariot_token(): void
    {
        $user = $this->makeUser(['email' => 'a@proba.mk']);
        $login = fn () => $this->postJson('/api/v1/login', ['email' => 'a@proba.mk', 'password' => 'lozinka-123', 'device_name' => 'PC-1']);

        $login();
        $login();

        $this->assertSame(1, $user->tokens()->count());
    }

    public function test_odjava_go_brishe_samo_svojot_token(): void
    {
        $user = $this->makeUser();
        $user->createToken('drug-kompjuter');

        $this->as($user)->postJson('/api/v1/logout')->assertOk();

        $this->assertSame(['drug-kompjuter'], $user->tokens()->pluck('name')->all());
    }

    public function test_bez_token_401_na_makedonski(): void
    {
        $this->getJson('/api/v1/me')
            ->assertStatus(401)
            ->assertJsonPath('error', 'unauthenticated')
            ->assertJson(fn ($j) => $j->where('message', fn ($m) => str_contains($m, 'најав'))->etc());
    }

    public function test_prestara_aplikacija_dobiva_426(): void
    {
        config(['version.local_app_min' => '0.5.0']);

        $this->withHeader('X-App-Version', '0.4.9')->getJson('/api/v1/ping')
            ->assertStatus(426)->assertJsonPath('error', 'client_outdated')->assertJsonPath('need', '0.5.0');

        $this->withHeader('X-App-Version', '0.5.0')->getJson('/api/v1/ping')->assertOk();
    }

    public function test_validacija_e_vo_ist_oblik_i_na_makedonski(): void
    {
        $this->postJson('/api/v1/login', ['device_name' => 'PC'])
            ->assertStatus(422)
            ->assertJsonPath('error', 'validation')
            ->assertJsonPath('message', 'Внесете е-пошта.')
            ->assertJsonStructure(['errors' => ['email', 'password']]);
    }
}
