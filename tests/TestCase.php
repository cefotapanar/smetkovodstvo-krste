<?php

namespace Tests;

use App\Models\Firm;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    private static int $seq = 0;

    /**
     * Барањата во ист тест се праќаат како `user`, со свеж токен.
     *
     * `forgetGuards()` е задолжително: Laravel во ист тест го памети ПРВИОТ
     * најавен корисник и вториот токен тивко би бил пак првиот — тест за
     * дозволи што лаже (научено во ЕРП-от).
     */
    protected function as(User $user, ?Firm $firm = null): static
    {
        $this->app['auth']->forgetGuards();
        // Заглавијата се трупаат низ тестот: X-Firm од претходниот повик инаку
        // би останал и би ја „избрал“ фирмата наместо корисникот.
        $this->flushHeaders();

        $headers = ['Authorization' => 'Bearer '.$user->createToken('proba')->plainTextToken, 'Accept' => 'application/json'];
        if ($firm) {
            $headers['X-Firm'] = (string) $firm->id;
        }

        return $this->withHeaders($headers);
    }

    protected function makeUser(array $attrs = []): User
    {
        $n = ++self::$seq;

        return User::create($attrs + [
            'name'      => 'Корисник '.$n,
            'email'     => 'k'.$n.'@proba.mk',
            'password'  => 'lozinka-123',
            'is_super'  => false,
            'is_active' => true,
        ]);
    }

    protected function makeSuper(array $attrs = []): User
    {
        return $this->makeUser($attrs + ['is_super' => true]);
    }

    protected function makeFirm(array $attrs = []): Firm
    {
        $n = ++self::$seq;

        return Firm::create($attrs + [
            'name'       => 'Фирма '.$n,
            'tax_id'     => (string) (4030000000000 + $n),
            'vat_period' => 'month',
            'size'       => 'small',
            'is_active'  => true,
        ]);
    }

    // ── Книговодство ────────────────────────────────────────────────────────

    /** Фирма со законскиот контен план (преку SaveFirm, како во живо) и корисник со сите права во неа. */
    protected function ledgerFirm(): array
    {
        $n = ++self::$seq;
        $firm = app(\App\Actions\System\SaveFirm::class)->run([
            'name' => 'Книги '.$n, 'tax_id' => (string) (4030000000000 + $n), 'vat_period' => 'month',
        ]);
        $user = $this->makeUser();
        $this->grant($user, $firm, Role::allKeys());

        return [$firm, $user];
    }

    protected function acc(Firm $firm, string $code): int
    {
        return \App\Models\Account::where('firm_id', $firm->id)->where('code', $code)->value('id')
            ?? throw new \RuntimeException("Нема конто $code");
    }

    protected function type(Firm $firm, string $code): int
    {
        return \App\Models\JournalType::where('firm_id', $firm->id)->where('code', $code)->value('id');
    }

    protected function partner(Firm $firm, string $name): int
    {
        return \App\Models\Partner::create(['firm_id' => $firm->id, 'name' => $name])->id;
    }

    /**
     * Налог преку API-то. Ставка: [конто, должи, побарува, партнер?].
     *
     * @param  array<int, array{0: string, 1: float|int|string, 2: float|int|string, 3?: int|null}>  $lines
     */
    protected function entry(Firm $firm, User $user, array $lines, string $type = 'РН', string $date = '2027-01-15', bool $post = true): \Illuminate\Testing\TestResponse
    {
        return $this->as($user, $firm)->postJson('/api/v1/journal', [
            'journal_type_id' => $this->type($firm, $type),
            'date'            => $date,
            'post'            => $post,
            'lines'           => array_map(fn ($l) => [
                'account_id' => $this->acc($firm, $l[0]),
                'debit'      => $l[1],
                'credit'     => $l[2],
                'partner_id' => $l[3] ?? null,
            ], $lines),
        ]);
    }

    /** Пристап на корисник до фирма со улога што ги има дадените дозволи. */
    protected function grant(User $user, Firm $firm, array $permissions): Role
    {
        $role = Role::create(['name' => 'Улога '.(++self::$seq), 'permissions' => $permissions]);
        $user->firms()->attach($firm->id, ['role_id' => $role->id]);

        return $role;
    }
}
