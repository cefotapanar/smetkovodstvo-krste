<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SystemTest extends TestCase
{
    use RefreshDatabase;

    public function test_samo_glaven_administrator(): void
    {
        $user = $this->makeUser();
        $firm = $this->makeFirm();
        $this->grant($user, $firm, Role::allKeys()); // сите права во фирма ≠ Систем

        foreach (['/api/v1/system/users', '/api/v1/system/firms', '/api/v1/system/roles', '/api/v1/system/schema'] as $url) {
            $this->as($user, $firm)->getJson($url)->assertStatus(403);
        }
    }

    public function test_firma_edb_13_cifri_i_edinstven(): void
    {
        $admin = $this->makeSuper();
        $this->makeFirm(['tax_id' => '4030000000999']);

        $this->as($admin)->postJson('/api/v1/system/firms', ['name' => 'А', 'tax_id' => '123', 'vat_period' => 'month'])
            ->assertStatus(422)->assertJsonPath('errors.tax_id.0', 'ЕДБ мора да има точно 13 цифри.');

        $this->as($admin)->postJson('/api/v1/system/firms', ['name' => 'А', 'tax_id' => '4030000000999', 'vat_period' => 'month'])
            ->assertStatus(422)->assertJsonStructure(['errors' => ['tax_id']]);

        $this->as($admin)->postJson('/api/v1/system/firms', ['name' => 'А', 'tax_id' => '4030000000111', 'vat_period' => 'quarter'])
            ->assertCreated()->assertJsonPath('firm.vat_period', 'quarter')->assertJsonPath('firm.size', 'small');
    }

    public function test_izmena_na_firma_ne_gi_brishe_nepratenite_polinja(): void
    {
        $firm = $this->makeFirm(['reg_no' => '1234567', 'address' => 'Скопје']);

        $this->as($this->makeSuper())->putJson('/api/v1/system/firms/'.$firm->id, [
            'name' => 'Ново име', 'tax_id' => $firm->tax_id, 'vat_period' => 'month',
        ])->assertOk();

        $firm->refresh();
        $this->assertSame('Ново име', $firm->name);
        $this->assertSame('1234567', $firm->reg_no);
        $this->assertSame('Скопје', $firm->address);
    }

    public function test_uloga_so_nepoznata_dozvola_odbiena(): void
    {
        $this->as($this->makeSuper())->postJson('/api/v1/system/roles', ['name' => 'X', 'permissions' => ['journal', 'izmisleno']])
            ->assertStatus(422);

        $this->as($this->makeSuper())->postJson('/api/v1/system/roles', ['name' => 'Сметководител', 'permissions' => ['journal', 'journal.write', 'cards']])
            ->assertCreated()->assertJsonPath('role.permissions', ['journal', 'journal.write', 'cards']);
    }

    public function test_katalogot_na_dozvoli_se_vrakja(): void
    {
        $this->as($this->makeSuper())->getJson('/api/v1/system/roles')
            ->assertOk()
            ->assertJsonPath('catalog.Книговодство.journal', 'Налози за книжење')
            ->assertJsonPath('readonly', Role::READONLY_KEYS);
    }

    public function test_nov_korisnik_so_firmi_i_ulogi(): void
    {
        $admin = $this->makeSuper();
        $firm = $this->makeFirm();
        $role = Role::create(['name' => 'Сметководител', 'permissions' => ['journal']]);

        $r = $this->as($admin)->postJson('/api/v1/system/users', [
            'name' => 'ТИА Конто', 'email' => 'tia@proba.mk', 'password' => 'dolga-lozinka',
            'firms' => [['firm_id' => $firm->id, 'role_id' => $role->id]],
        ])->assertCreated()->assertJsonPath('user.firms.0.role_id', $role->id);

        $user = User::find($r->json('user.id'));
        $this->assertFalse($user->isSuper());
        $this->assertTrue($user->hasPermission('journal', $firm));
    }

    public function test_kratka_lozinka_odbiena(): void
    {
        $this->as($this->makeSuper())->postJson('/api/v1/system/users', ['name' => 'А', 'email' => 'a@proba.mk', 'password' => 'kratka'])
            ->assertStatus(422)->assertJsonStructure(['errors' => ['password']]);
    }

    public function test_posledniot_administrator_ne_mozhe_da_se_otstrani(): void
    {
        $admin = $this->makeSuper();

        $this->as($admin)->putJson('/api/v1/system/users/'.$admin->id, ['name' => $admin->name, 'email' => $admin->email, 'is_super' => false])
            ->assertStatus(422)->assertJsonStructure(['errors' => ['is_super']]);
        $this->as($admin)->putJson('/api/v1/system/users/'.$admin->id, ['name' => $admin->name, 'email' => $admin->email, 'is_active' => false])
            ->assertStatus(422);

        $this->assertTrue($admin->fresh()->is_super);
        $this->assertTrue($admin->fresh()->is_active);
    }

    public function test_vtor_administrator_mozhe_da_go_odzeme_prviot(): void
    {
        $prv = $this->makeSuper();
        $vtor = $this->makeSuper();

        $this->as($vtor)->putJson('/api/v1/system/users/'.$prv->id, ['name' => $prv->name, 'email' => $prv->email, 'is_super' => false])
            ->assertOk();
        $this->assertFalse($prv->fresh()->is_super);
    }

    public function test_deaktiviranje_i_nova_lozinka_gi_brishat_tokenite(): void
    {
        $admin = $this->makeSuper();
        $a = $this->makeUser();
        $b = $this->makeUser();
        $a->createToken('PC');
        $b->createToken('PC');

        $this->as($admin)->putJson('/api/v1/system/users/'.$a->id, ['name' => $a->name, 'email' => $a->email, 'is_active' => false])->assertOk();
        $this->as($admin)->putJson('/api/v1/system/users/'.$b->id, ['name' => $b->name, 'email' => $b->email, 'password' => 'nova-lozinka-1'])->assertOk();

        $this->assertSame(0, $a->tokens()->count());
        $this->assertSame(0, $b->tokens()->count());
    }

    public function test_izmena_bez_lozinka_ne_gi_brishe_tokenite(): void
    {
        $admin = $this->makeSuper();
        $a = $this->makeUser();
        $a->createToken('PC');

        $this->as($admin)->putJson('/api/v1/system/users/'.$a->id, ['name' => 'Ново име', 'email' => $a->email, 'password' => ''])->assertOk();

        $this->assertSame(1, $a->tokens()->count());
        $this->assertSame('Ново име', $a->fresh()->name);
    }

    public function test_odzemanje_na_ured(): void
    {
        $admin = $this->makeSuper();
        $a = $this->makeUser();
        $pc1 = $a->createToken('PC-1')->accessToken;
        $a->createToken('PC-2');

        $this->as($admin)->getJson('/api/v1/system/users')->assertOk();
        $this->as($admin)->deleteJson('/api/v1/system/users/'.$a->id.'/devices/'.$pc1->id)->assertOk();

        $this->assertSame(['PC-2'], $a->tokens()->pluck('name')->all());
    }
}
