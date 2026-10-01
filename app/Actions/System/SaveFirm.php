<?php

namespace App\Actions\System;

use App\Models\Firm;
use App\Support\ChartOfAccounts;
use Illuminate\Support\Facades\DB;

/**
 * Нова фирма или измена. Ја викаат API-то (Систем → Фирми) и `public/sistem.php`.
 *
 * Поле што НЕ е пратено останува какво што е — апликацијата праќа само
 * пополнетите полиња (лекција од ЕРП-от: „Undefined array key“ кај Понудите).
 */
class SaveFirm
{
    private const FIELDS = ['name', 'short_name', 'tax_id', 'reg_no', 'activity_code', 'size', 'vat_period', 'address', 'is_active'];

    public function run(array $data, ?Firm $firm = null): Firm
    {
        return DB::transaction(function () use ($data, $firm) {
            $firm ??= new Firm(['size' => 'small', 'is_active' => true]);

            foreach (self::FIELDS as $field) {
                if (array_key_exists($field, $data)) {
                    $firm->{$field} = $data[$field];
                }
            }

            $firm->save();

            // Фирма без контен план не може да книжи ниту ред — се прави заедно со неа.
            ChartOfAccounts::ensure($firm);

            return $firm;
        });
    }
}
