<?php

namespace App\Actions\Reports;

use App\Models\Firm;
use App\Models\JournalEntry;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Едно место за „кои ставки влегуваат во извештај“.
 *
 * Сите извештаи (бруто биланс, картички, главна книга) мора да ја делат
 * ИСТАТА поделба на почетна / промет — инаку картичката и бруто билансот
 * за исто конто покажуваат различна почетна состојба, и сметководителот
 * бара грешка што не е во книгите туку во извештајот.
 *
 *   почетна = налог ПС  ИЛИ  датум пред `from`
 *   промет  = не-ПС     И    датум од `from` до `to`
 */
final class LedgerQuery
{
    public static function lines(Firm $firm, Period $p): Builder
    {
        return DB::table('journal_lines as l')
            ->join('journal_entries as e', 'e.id', '=', 'l.journal_entry_id')
            ->join('journal_types as t', 't.id', '=', 'e.journal_type_id')
            ->where('e.firm_id', $firm->id)
            ->where('e.status', JournalEntry::POSTED)
            ->where('e.year', $p->year)
            ->where(fn ($q) => $q->where('t.is_opening', true)->orWhere('e.date', '<=', $p->to->toDateString()));
    }

    /** SQL израз: 1 ако ставката е во почетната состојба. */
    public static function openingExpr(Period $p): string
    {
        return "(t.is_opening = 1 OR e.date < '".$p->from->toDateString()."')";
    }
}
