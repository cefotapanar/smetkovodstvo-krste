<?php

namespace Tests\Feature;

use App\Projekt\ProjectItem;
use App\Projekt\ProjectSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/** ПРИВРЕМЕНО — работната табла; се брише заедно со неа. */
class ProjektBoardTest extends TestCase
{
    use RefreshDatabase;

    private function adri()
    {
        $u = $this->makeUser(['name' => 'Адри', 'email' => 'adri@proba.mk']);
        $u->forceFill(['project_side' => 'accountant'])->save();

        return $u;
    }

    public function test_bez_najava_kon_login(): void
    {
        $this->get('/')->assertRedirect('/login');
        $this->get('/login')->assertOk()->assertSee('Сметководство КРСТЕ');
    }

    public function test_najava(): void
    {
        $this->makeUser(['email' => 'a@proba.mk']);
        $this->makeUser(['email' => 'b@proba.mk', 'is_active' => false]);

        $this->post('/login', ['email' => 'a@proba.mk', 'password' => 'pogresna-1'])->assertSessionHasErrors('email');
        $this->post('/login', ['email' => 'b@proba.mk', 'password' => 'lozinka-123'])->assertSessionHasErrors('email');
        $this->post('/login', ['email' => 'a@proba.mk', 'password' => 'lozinka-123'])->assertRedirect('/');
    }

    public function test_tablata_go_pokazhuva_planot_i_stavkite_od_kodot(): void
    {
        $this->actingAs($this->makeSuper())->get('/')
            ->assertOk()
            ->assertSee('Како се книжи секој документ')       // планот
            ->assertSee('Копче „Книжи“ во ЕРП-от')            // одлука од разговорот
            ->assertSee('Вашиот аналитички контен план')      // прашање за сметководителката
            ->assertSee('Сопственик (во разговор)');

        $this->assertSame(count(require ProjectSync::file()), ProjectItem::count());
        $this->assertTrue(ProjectItem::where('key', 'odl-kopce-knizi')->first()->isDecided());
    }

    public function test_sinhronizacijata_ne_gi_gazi_odgovorite(): void
    {
        $adri = $this->adri();
        $this->actingAs($adri)->get('/');
        $item = ProjectItem::where('key', 'adri-storno')->first();

        $this->actingAs($adri)->post("/projekt/stavki/{$item->id}/odluka", ['body' => 'Црвено е во ред.'])->assertRedirect();

        Cache::forget('projekt-sync-hash');
        ProjectSync::run();

        $item->refresh();
        $this->assertSame('Црвено е во ред.', $item->decision);
        $this->assertSame($adri->id, $item->decided_by);
    }

    public function test_strana_odluchuva_samo_za_sebe(): void
    {
        $adri = $this->adri();
        $this->actingAs($adri)->get('/');
        $mine = ProjectItem::where('key', 'adri-pocetna')->first();
        $owners = ProjectItem::where('key', 'owner-pristap')->first();

        $this->actingAs($adri)->post("/projekt/stavki/{$owners->id}/odluka", ['body' => 'x'])->assertForbidden();
        $this->actingAs($adri)->post("/projekt/stavki/{$owners->id}/komentar", ['body' => 'Само прашање.'])->assertRedirect();
        $this->actingAs($adri)->post("/projekt/stavki/{$mine->id}/odluka", ['body' => 'Во Excel, до 20.01.'])->assertRedirect();

        $this->assertTrue($mine->fresh()->isDecided());
        $this->assertSame(1, $owners->comments()->count());
    }

    public function test_otvoranje_ja_chuva_starata_odluka(): void
    {
        $owner = $this->makeSuper(['name' => 'Сопственикот']);
        $this->actingAs($owner)->get('/');
        $item = ProjectItem::where('key', 'owner-pristap')->first();

        $this->actingAs($owner)->post("/projekt/stavki/{$item->id}/odluka", ['body' => 'Никој друг.']);
        $this->actingAs($owner)->post("/projekt/stavki/{$item->id}/otvori")->assertRedirect();

        $item->refresh();
        $this->assertSame('open', $item->status);
        $this->assertStringContainsString('Никој друг.', $item->comments()->first()->body);
    }

    public function test_novo_od_poslednata_poseta(): void
    {
        $owner = $this->makeSuper();
        $adri = $this->adri();
        $this->actingAs($owner)->get('/');
        $this->travel(1)->minutes();

        $item = ProjectItem::where('key', 'adri-shemi')->first();
        $this->actingAs($adri)->post("/projekt/stavki/{$item->id}/komentar", ['body' => 'Ќе ги поминам до петок.']);
        $this->travel(1)->minutes();

        $this->actingAs($owner)->get('/')->assertSee('НОВО')->assertSee('Ќе ги поминам до петок.');
        // Втора посета — веќе видено.
        $this->travel(1)->minutes();
        $this->actingAs($owner)->get('/')->assertDontSee('<span class="chip new">', false);
    }

    public function test_novo_prashanje(): void
    {
        $adri = $this->adri();

        $this->actingAs($adri)->post('/projekt/stavki', ['title' => 'Каде е извозот?', 'body' => 'Текст', 'side' => 'owner', 'kind' => 'question'])
            ->assertRedirect();

        $this->assertSame($adri->id, ProjectItem::where('title', 'Каде е извозот?')->value('created_by'));
    }

    public function test_pristap_pravi_samo_administrator(): void
    {
        $adri = $this->adri();
        $data = ['name' => 'Нов', 'email' => 'nov@proba.mk', 'password' => 'dolga-lozinka', 'side' => 'accountant'];

        $this->actingAs($adri)->post('/projekt/korisnici', $data)->assertForbidden();
        $this->actingAs($this->makeSuper())->post('/projekt/korisnici', $data)->assertRedirect();

        $this->assertDatabaseHas('users', ['email' => 'nov@proba.mk', 'project_side' => 'accountant', 'is_super' => false]);
    }

    public function test_izvoz_samo_so_kluch(): void
    {
        $this->get('/projekt/izvoz')->assertNotFound();

        config(['projekt.key' => 'tajna-123']);
        $this->actingAs($this->makeSuper())->get('/');

        $this->get('/projekt/izvoz', ['X-Projekt-Key' => 'pogresen'])->assertNotFound();
        $this->get('/projekt/izvoz', ['X-Projekt-Key' => 'tajna-123'])->assertOk()->assertJsonStructure(['items' => [['key', 'status', 'comments']]]);
    }

    public function test_deaktiviran_se_odjavuva(): void
    {
        $adri = $this->adri();
        $adri->forceFill(['is_active' => false])->save();

        $this->actingAs($adri)->get('/')->assertRedirect('/login');
    }
}
