<?php

namespace App\Actions\Reports;

use App\Models\Account;
use App\Models\Firm;
use App\Support\Money;

/**
 * Бруто биланс: почетна, промет, вкупно и салдо — по конто, синтетика, група
 * или класа.
 *
 * Збировите на вишите нивоа се собираат од ставките (листовите), никогаш од
 * подзбирови — така аналитика и нејзината синтетика не може да се избројат
 * двапати, а `totals` е ист на секое ниво.
 */
final class TrialBalance
{
    public const LEVELS = ['analytic' => null, 'synthetic' => 3, 'group' => 2, 'class' => 1];

    public function run(Firm $firm, Period $p, string $level = 'analytic'): array
    {
        $open = LedgerQuery::openingExpr($p);

        $sums = LedgerQuery::lines($firm, $p)
            ->groupBy('l.account_id')
            ->selectRaw("l.account_id,
                SUM(CASE WHEN $open THEN l.debit ELSE 0 END) AS od,
                SUM(CASE WHEN $open THEN l.credit ELSE 0 END) AS oc,
                SUM(CASE WHEN $open THEN 0 ELSE l.debit END) AS d,
                SUM(CASE WHEN $open THEN 0 ELSE l.credit END) AS c")
            ->get();

        $accounts = Account::where('firm_id', $firm->id)->get()->keyBy('id');
        $names = $accounts->pluck('name', 'code');
        $len = self::LEVELS[$level] ?? null;

        $rows = [];
        $totals = self::zero();

        foreach ($sums as $s) {
            $acc = $accounts[$s->account_id];
            $key = $len ? substr($acc->code, 0, $len) : $acc->code;
            $v = [Money::cents($s->od), Money::cents($s->oc), Money::cents($s->d), Money::cents($s->c)];

            $rows[$key] ??= self::zero();
            foreach ($v as $i => $x) {
                $rows[$key][$i] += $x;
                $totals[$i] += $x;
            }
        }

        ksort($rows, SORT_STRING);

        $out = [];
        $balD = $balC = 0;
        foreach ($rows as $code => $v) {
            if ($v === self::zero()) {
                continue;
            }
            $out[] = ['code' => (string) $code, 'name' => $names[$code] ?? '', 'level' => Account::levelOf((string) $code)] + self::shape($v);
            $net = $v[0] + $v[2] - $v[1] - $v[3];
            $balD += max($net, 0);
            $balC += max(-$net, 0);
        }

        // Салдата во збирот се ЗБИР НА САЛДАТА по редовите, не салдо на збирот:
        // салдо на збирот е секогаш нула (Д = П) и не кажува ништо. Збирот на
        // салдата е бројот што сметководителот го споредува со билансите.
        $t = ['balance_debit' => Money::out($balD), 'balance_credit' => Money::out($balC)] + self::shape($totals);

        return [
            'period'   => $p->toArray(),
            'level'    => $level,
            'rows'     => $out,
            'totals'   => $t,
            // Двојно книговодство: ако ова е false, нешто е запишано мимо PostEntry.
            'balanced' => $totals[0] === $totals[1] && $totals[2] === $totals[3] && $balD === $balC,
        ];
    }

    private static function zero(): array
    {
        return [0, 0, 0, 0];
    }

    /** @param array{0:int,1:int,2:int,3:int} $v */
    private static function shape(array $v): array
    {
        [$od, $oc, $d, $c] = $v;
        $td = $od + $d;
        $tc = $oc + $c;
        $bal = $td - $tc;

        return [
            'opening_debit'  => Money::out($od),
            'opening_credit' => Money::out($oc),
            'debit'          => Money::out($d),
            'credit'         => Money::out($c),
            'total_debit'    => Money::out($td),
            'total_credit'   => Money::out($tc),
            'balance_debit'  => Money::out(max($bal, 0)),
            'balance_credit' => Money::out(max(-$bal, 0)),
        ];
    }
}
