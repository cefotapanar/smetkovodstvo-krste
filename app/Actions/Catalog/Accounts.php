<?php

namespace App\Actions\Catalog;

use App\Exceptions\DocumentLocked;
use App\Models\Account;
use App\Models\Firm;
use Illuminate\Validation\ValidationException;

/**
 * Контен план: законската синтетика е дадена, фирмата отвора аналитика.
 */
final class Accounts
{
    public function create(Firm $firm, array $data): Account
    {
        $code = $data['code'];

        if (Account::where('firm_id', $firm->id)->where('code', $code)->exists()) {
            throw ValidationException::withMessages(['code' => "Контото $code веќе постои."]);
        }

        // Родител = најдолгата постоечка шифра што е почеток на новата.
        $parent = Account::where('firm_id', $firm->id)
            ->whereIn('code', array_map(fn ($n) => substr($code, 0, $n), range(3, strlen($code) - 1)))
            ->orderByRaw('LENGTH(code) DESC')
            ->first();

        if (! $parent) {
            throw ValidationException::withMessages(['code' => 'Аналитиката мора да почнува со постоечка синтетичка сметка (3 цифри) од контниот план.']);
        }

        // Ако родителот веќе има книжење, тоа салдо би останало на конто што
        // од сега „не се книжи“ — и бруто билансот на аналитиката не би се
        // собирал со синтетиката. Прво се префрла книжењето, па се отвора аналитика.
        if ($parent->isUsed()) {
            throw ValidationException::withMessages(['code' => "На контото {$parent->code} веќе има книжење — под него не може да се отвори аналитика."]);
        }

        return Account::create([
            'firm_id'           => $firm->id,
            'code'              => $code,
            'name'              => $data['name'],
            'is_statutory'      => false,
            'needs_partner'     => (bool) ($data['needs_partner'] ?? $parent->needs_partner),
            'needs_cost_center' => (bool) ($data['needs_cost_center'] ?? $parent->needs_cost_center),
            'is_active'         => true,
        ]);
    }

    public function update(Account $account, array $data): Account
    {
        if (array_key_exists('name', $data) && $data['name'] !== null && $data['name'] !== $account->name) {
            if ($account->is_statutory) {
                throw ValidationException::withMessages(['name' => 'Името на законска сметка (од Правилникот) не се менува.']);
            }
            $account->name = $data['name'];
        }

        foreach (['needs_partner', 'needs_cost_center', 'is_active'] as $f) {
            if (array_key_exists($f, $data) && $data[$f] !== null) {
                $account->{$f} = (bool) $data[$f];
            }
        }

        $account->save();

        return $account;
    }

    public function delete(Account $account): void
    {
        if ($account->is_statutory) {
            throw new DocumentLocked('Законска сметка (од Правилникот) не се брише — може да се деактивира.');
        }
        if ($account->isUsed()) {
            throw new DocumentLocked("Контото {$account->code} има книжења — не се брише, може да се деактивира.");
        }
        if ($account->hasChildren()) {
            throw new DocumentLocked("Контото {$account->code} има подконта — прво избришете ги тие.");
        }

        $account->delete();
    }
}
