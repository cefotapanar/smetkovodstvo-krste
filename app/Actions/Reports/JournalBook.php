<?php

namespace App\Actions\Reports;

use App\Models\Account;
use App\Models\Firm;
use App\Models\JournalEntry;
use App\Support\Money;

/**
 * Дневник (задолжителна книга): прокнижените налози хронолошки, по реден број
 * на книжење — со ставките. Главна книга: картичката на секое конто со промет.
 */
final class JournalBook
{
    public function __construct(private Card $card)
    {
    }

    public function journal(Firm $firm, Period $p): array
    {
        $entries = JournalEntry::where('firm_id', $firm->id)
            ->where('status', JournalEntry::POSTED)
            ->where('year', $p->year)
            ->whereBetween('date', [$p->from->toDateString(), $p->to->toDateString()])
            ->with('type', 'lines.account', 'lines.partner')
            ->orderBy('posting_seq')
            ->get();

        $td = $tc = 0;
        $out = $entries->map(function (JournalEntry $e) use (&$td, &$tc) {
            $lines = $e->lines->map(function ($l) use (&$td, &$tc) {
                $d = Money::cents($l->debit);
                $c = Money::cents($l->credit);
                $td += $d;
                $tc += $c;

                return [
                    'line_no' => $l->line_no, 'account' => Card::acc($l->account),
                    'partner' => $l->partner ? ['id' => $l->partner->id, 'name' => $l->partner->name] : null,
                    'doc_number' => $l->doc_number, 'description' => $l->description,
                    'debit' => Money::out($d), 'credit' => Money::out($c),
                ];
            })->all();

            return [
                'id' => $e->id, 'posting_seq' => $e->posting_seq, 'label' => $e->label(),
                'date' => $e->date->toDateString(), 'description' => $e->description,
                'storno_of_id' => $e->storno_of_id, 'lines' => $lines,
            ];
        })->all();

        return ['period' => $p->toArray(), 'entries' => $out, 'totals' => ['debit' => Money::out($td), 'credit' => Money::out($tc)]];
    }

    public function generalLedger(Firm $firm, Period $p, ?int $accountId = null): array
    {
        $ids = LedgerQuery::lines($firm, $p)
            ->when($accountId, fn ($q) => $q->where('l.account_id', $accountId))
            ->distinct()->pluck('l.account_id');

        $accounts = Account::whereIn('id', $ids)->orderBy('code')->get();

        return [
            'period'   => $p->toArray(),
            'accounts' => $accounts->map(fn (Account $a) => $this->card->account($firm, $a, $p))->all(),
        ];
    }
}
