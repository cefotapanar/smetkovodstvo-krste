<?php

namespace App\Support;

use App\Models\Account;
use App\Models\Firm;
use App\Models\JournalType;

/**
 * Почетни шифрарници на фирма: законскиот контен план (Правилник 174/2011)
 * и видовите налози. Се викa при создавање фирма и од миграцијата за
 * постоечките; не допира фирма што веќе ги има.
 */
final class ChartOfAccounts
{
    /**
     * Синтетика што по правило се води по партнер (отворени ставки):
     * побарувања и обврски кон поврзани и неповрзани друштва, аванси, камати.
     * Без вредносното усогласување (…9) — тоа не е долг на партнер.
     * Аналитиката го наследува знакот од родителот; ТИА Конто може да го смени.
     */
    public const PARTNER_CODES = [
        '110', '111', '112', '113', '114', '115', '116', '118',
        '120', '121', '122', '123', '124', '125', '126', '127',
        '210', '211', '212', '213', '214', '215', '216', '218',
        '220', '221', '222', '223', '224', '225', '229',
    ];

    public const JOURNAL_TYPES = [
        ['ПС', 'Почетна состојба', true],
        ['ИФ', 'Излезни фактури', false],
        ['ВФ', 'Влезни фактури', false],
        ['ИЗ', 'Изводи', false],
        ['БЛ', 'Благајна', false],
        ['КЛ', 'Калкулации', false],
        ['ОС', 'Основни средства', false],
        ['ПЛ', 'Плати', false],
        ['РН', 'Рачен налог', false],
        ['ЗТ', 'Затворање', false],
    ];

    /** @return array<int, array{0: string, 1: string}> */
    public static function statutory(): array
    {
        return require database_path('data/kontni_plan_174_2011.php');
    }

    public static function ensure(Firm $firm): void
    {
        if (! Account::where('firm_id', $firm->id)->exists()) {
            $now = now();
            $partner = array_flip(self::PARTNER_CODES);
            $rows = [];

            foreach (self::statutory() as [$code, $name]) {
                $rows[] = [
                    'firm_id'       => $firm->id,
                    'code'          => $code,
                    'name'          => $name,
                    'is_statutory'  => true,
                    'needs_partner' => isset($partner[$code]),
                    'is_active'     => true,
                    'created_at'    => $now,
                    'updated_at'    => $now,
                ];
            }

            foreach (array_chunk($rows, 200) as $chunk) {
                Account::insert($chunk);
            }
        }

        if (! JournalType::where('firm_id', $firm->id)->exists()) {
            foreach (self::JOURNAL_TYPES as [$code, $name, $opening]) {
                JournalType::create(['firm_id' => $firm->id, 'code' => $code, 'name' => $name, 'is_opening' => $opening]);
            }
        }
    }
}
