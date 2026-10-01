<?php

namespace App\Actions\Ledger;

use App\Models\Firm;
use Illuminate\Support\Facades\DB;

/**
 * Бројачи без дупки и без двојници.
 *
 * MAX(number)+1 не е доволно: двајца што книжат во ист миг го читаат истиот
 * MAX и добиваат ист број (а unique индексот потоа соборува еден налог, со
 * порака што никој не ја разбира). Редот во `journal_counters` се заклучува
 * со SELECT … FOR UPDATE, па вториот чека додека првиот не заврши.
 *
 * Мора да се вика ВНАТРЕ во трансакција — инаку заклучувањето трае до крајот
 * на наредбата, не до крајот на книжењето.
 */
final class Counters
{
    /** @return object{id: int, last_value: int, last_hash: ?string} */
    public static function lock(Firm $firm, string $key): object
    {
        $find = fn () => DB::table('journal_counters')
            ->where('firm_id', $firm->id)->where('key', $key)
            ->lockForUpdate()->first();

        // ⚠ Прво SELECT … FOR UPDATE, па INSERT само ако редот го нема.
        // Обратно (INSERT IGNORE секогаш) е замка: INSERT врз постоечки ред
        // зема ЗАЕДНИЧКО заклучување, па два процеса го држат истовремено и
        // потоа двата чекаат ексклузивно — deadlock на секое второ книжење
        // (пробата со 4 паралелни процеси: 118 од 400 паднаа).
        if ($row = $find()) {
            return $row;
        }

        DB::table('journal_counters')->insertOrIgnore([
            'firm_id' => $firm->id, 'key' => $key, 'last_value' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $find();
    }

    public static function next(Firm $firm, string $key): int
    {
        $row = self::lock($firm, $key);
        $value = (int) $row->last_value + 1;

        DB::table('journal_counters')->where('id', $row->id)->update(['last_value' => $value, 'updated_at' => now()]);

        return $value;
    }

    public static function advanceChain(object $chain, int $value, string $hash): void
    {
        DB::table('journal_counters')->where('id', $chain->id)
            ->update(['last_value' => $value, 'last_hash' => $hash, 'updated_at' => now()]);
    }
}
