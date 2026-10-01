<?php

namespace App\Actions\Journal;

use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\PeriodLock;
use App\Support\Money;
use Illuminate\Validation\ValidationException;

/**
 * Правилата за книжење — на ЕДНО место. Ги вика `PostEntry` и за рачен
 * налог и за сторно (сторното ги прескокнува правилата за контото: мора да
 * може да се поправи и книжење на конто што во меѓувреме е деактивирано).
 *
 * Сите грешки се собираат одеднаш: сметководител што поправа ставка по
 * ставка, со по едно „Зачувај“ меѓу нив, губи време.
 */
final class EntryRules
{
    public static function assertPostable(JournalEntry $entry, bool $isStorno = false): void
    {
        $entry->loadMissing('lines.account');
        $errors = [];

        if (PeriodLock::covers($entry->firm, $entry->date)) {
            $errors['date'][] = self::lockedMessage($entry->date);
        }

        $lines = $entry->lines;
        if ($lines->count() < 2) {
            $errors['lines'][] = 'Налогот мора да има најмалку две ставки.';
        }

        $withChildren = $isStorno ? [] : self::codesWithChildren($entry->firm_id);
        $debit = $credit = 0;

        foreach ($lines->values() as $i => $line) {
            $n = $i + 1;
            $d = Money::cents($line->debit);
            $c = Money::cents($line->credit);
            $debit += $d;
            $credit += $c;
            $acc = $line->account;

            if ($d !== 0 && $c !== 0) {
                $errors["lines.$i.debit"][] = "Ставка $n: или должи или побарува, не двете.";
            } elseif ($d === 0 && $c === 0) {
                $errors["lines.$i.debit"][] = "Ставка $n: нема износ.";
            }

            if ($isStorno) {
                continue;
            }

            if (! $acc->is_active) {
                $errors["lines.$i.account_id"][] = "Ставка $n: контото {$acc->code} е неактивно.";
            }
            if (strlen($acc->code) < 3 || isset($withChildren[$acc->code])) {
                $errors["lines.$i.account_id"][] = "Ставка $n: на контото {$acc->code} не се книжи — има подконта или е група.";
            }
            if ($acc->needs_partner && ! $line->partner_id) {
                $errors["lines.$i.partner_id"][] = "Ставка $n: контото {$acc->code} бара партнер.";
            }
            if ($acc->needs_cost_center && ! $line->cost_center_id) {
                $errors["lines.$i.cost_center_id"][] = "Ставка $n: контото {$acc->code} бара место на трошок.";
            }
        }

        if ($debit !== $credit) {
            $errors['lines'][] = 'Налогот не е во рамнотежа: должи '.self::fmt($debit).', побарува '.self::fmt($credit)
                .', разлика '.self::fmt($debit - $credit).'.';
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }
    }

    public static function lockedMessage(\DateTimeInterface $date): string
    {
        return 'Месецот '.$date->format('m/Y').' е заклучен — изберете датум во отворен период.';
    }

    /** @return array<string, true> шифри што имаат подконта */
    public static function codesWithChildren(int $firmId): array
    {
        $codes = Account::where('firm_id', $firmId)->pluck('code')->all();
        sort($codes, SORT_STRING);
        $out = [];

        // Сортирани како текст: подконтата доаѓаат веднаш по родителот.
        foreach ($codes as $i => $code) {
            $next = $codes[$i + 1] ?? null;
            if ($next !== null && str_starts_with($next, $code)) {
                $out[$code] = true;
            }
        }

        return $out;
    }

    /** 1234567 → „12.345,67“ */
    public static function fmt(int $cents): string
    {
        return number_format($cents / 100, 2, ',', '.');
    }
}
