<?php

namespace App\Actions\Reports;

use App\Models\Account;
use App\Models\Firm;
use App\Models\Partner;
use App\Support\Money;
use Illuminate\Database\Query\Builder;

/**
 * Картичка — на конто (со подконтата) или на партнер. Почетна состојба,
 * ставки по ред на книжење и салдо по секој ред. Главната книга е истата
 * картичка за секое конто со промет.
 */
final class Card
{
    public function account(Firm $firm, Account $account, Period $p, ?int $partnerId = null): array
    {
        $q = LedgerQuery::lines($firm, $p)
            ->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->where('a.code', 'like', $account->code.'%')
            ->when($partnerId, fn ($q) => $q->where('l.partner_id', $partnerId));

        return ['account' => self::acc($account)] + $this->build($q, $p, withAccount: strlen($account->code) < 3 || $account->hasChildren());
    }

    public function partner(Firm $firm, Partner $partner, Period $p, ?int $accountId = null): array
    {
        $q = LedgerQuery::lines($firm, $p)
            ->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->where('l.partner_id', $partner->id)
            ->when($accountId, fn ($q) => $q->where('l.account_id', $accountId));

        return ['partner' => ['id' => $partner->id, 'name' => $partner->name, 'tax_id' => $partner->tax_id]]
            + $this->build($q, $p, withAccount: true);
    }

    private function build(Builder $q, Period $p, bool $withAccount): array
    {
        $open = LedgerQuery::openingExpr($p);

        $o = (clone $q)->whereRaw($open)->selectRaw('SUM(l.debit) AS d, SUM(l.credit) AS c')->first();
        $od = Money::cents($o->d ?? 0);
        $oc = Money::cents($o->c ?? 0);

        $rows = (clone $q)->whereRaw("NOT $open")
            ->leftJoin('partners as p', 'p.id', '=', 'l.partner_id')
            ->orderBy('e.date')->orderBy('e.posting_seq')->orderBy('l.line_no')
            ->get([
                'l.id', 'l.journal_entry_id', 'l.doc_number', 'l.description', 'l.debit', 'l.credit',
                'e.date', 'e.posting_seq', 'e.number', 'e.year', 't.code as type_code',
                'a.id as account_id', 'a.code as account_code', 'a.name as account_name',
                'p.id as partner_id', 'p.name as partner_name',
            ]);

        $bal = $od - $oc;
        $td = $tc = 0;
        $lines = [];

        foreach ($rows as $r) {
            $d = Money::cents($r->debit);
            $c = Money::cents($r->credit);
            $td += $d;
            $tc += $c;
            $bal += $d - $c;

            $line = [
                'line_id'     => $r->id,
                'entry_id'    => $r->journal_entry_id,
                'label'       => $r->type_code.'-'.$r->number.'/'.$r->year,
                'posting_seq' => $r->posting_seq,
                'date'        => substr((string) $r->date, 0, 10),
                'doc_number'  => $r->doc_number,
                'partner'     => $r->partner_id ? ['id' => $r->partner_id, 'name' => $r->partner_name] : null,
                'description' => $r->description,
                'debit'       => Money::out($d),
                'credit'      => Money::out($c),
                'balance'     => Money::out($bal),
            ];
            if ($withAccount) {
                $line['account'] = ['id' => $r->account_id, 'code' => $r->account_code, 'name' => $r->account_name];
            }
            $lines[] = $line;
        }

        return [
            'period'  => $p->toArray(),
            'opening' => ['debit' => Money::out($od), 'credit' => Money::out($oc), 'balance' => Money::out($od - $oc)],
            'lines'   => $lines,
            'totals'  => [
                'debit'         => Money::out($td),
                'credit'        => Money::out($tc),
                'total_debit'   => Money::out($od + $td),
                'total_credit'  => Money::out($oc + $tc),
                'balance'       => Money::out($bal),
            ],
        ];
    }

    public static function acc(Account $a): array
    {
        return ['id' => $a->id, 'code' => $a->code, 'name' => $a->name];
    }
}
