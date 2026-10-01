<?php

namespace Tests\Feature;

use App\Support\SchemaState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Додека базата заостанува зад кодот — читањето поминува, запишувањето не.
 * Заостанување се глуми со бришење на редот на последната миграција.
 */
class SchemaGuardTest extends TestCase
{
    use RefreshDatabase;

    private function zaostani(): void
    {
        DB::table('migrations')->where('migration', SchemaState::head())->delete();
        SchemaState::flushMemo();
    }

    protected function tearDown(): void
    {
        SchemaState::flushMemo();
        parent::tearDown();
    }

    public function test_zapishuvanjeto_e_soprenno_chitanjeto_ne(): void
    {
        $admin = $this->makeSuper();
        $this->zaostani();

        $this->as($admin)->postJson('/api/v1/system/roles', ['name' => 'X'])
            ->assertStatus(503)->assertJsonPath('error', 'schema_outdated')->assertJsonPath('pending', 1);
        $this->as($admin)->getJson('/api/v1/system/roles')->assertOk();
    }

    public function test_najavata_i_pauzata_pominuvaat(): void
    {
        $admin = $this->makeSuper(['email' => 'a@proba.mk']);
        $this->zaostani();

        $this->postJson('/api/v1/login', ['email' => 'a@proba.mk', 'password' => 'lozinka-123', 'device_name' => 'PC'])->assertOk();
        // Паузата е излезот кога миграцијата падне на пола — не смее да ја сопре стражарот.
        $this->as($admin)->postJson('/api/v1/system/schema/pause')->assertOk();
        $this->as($admin)->postJson('/api/v1/system/roles', ['name' => 'X'])->assertCreated();
    }

    public function test_sostojbata_ja_pokazhuva_zaostanatata_migracija(): void
    {
        $admin = $this->makeSuper();
        $this->zaostani();

        $this->as($admin)->getJson('/api/v1/system/schema')
            ->assertOk()->assertJsonPath('pending', [SchemaState::head()])->assertJsonPath('db_guard', true);
    }
}
