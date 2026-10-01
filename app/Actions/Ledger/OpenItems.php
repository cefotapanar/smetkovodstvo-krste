<?php

namespace App\Actions\Ledger;

use App\Models\Firm;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\OpenItemMatch;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Отворени ставки по партнер — кој што должи, по која фактура.
 *
 * Знакот е должи − побарува: фактура кон купувач е +, неговата уплата е −,
 * црвеното сторно на фактурата е исто −. Затоа едно правило важи за сè:
 * се затвораат две ставки на ИСТО конто и ИСТ партнер со СПРОТИВНИ знаци.
 *
 * Затворањето не е книжење: тоа е врска меѓу две прокнижени ставки и смее
 * да се отвори одново. Салдото на партнерот од тоа не се менува — само
 * одговорот на „по која фактура“.
 */
final class OpenItems
{
    /** @return Collection<int, array<string, mixed>> */
    public function list(Firm $firm, int $partnerId, ?int $accountId = null, bool $all = false): Collection
    {
        $lines = $this->postedLines($firm)
            ->where('journal_lines.partner_id', $partnerId)
            ->when($accountId, fn ($q) => $q->where('journal_lines.account_id', $accountId))
            ->with(['account', 'entry.type'])
            ->orderBy('journal_entries.date')->orderBy('journal_entries.posting_seq')->orderBy('journal_lines.line_no')
            ->get(['journal_lines.*']);

        $matched = $this->matchedByLine($lines->pluck('id')->all());

        return $lines->map(function (JournalLine $l) use ($matched) {
            $amount = Money::cents($l->debit) - Money::cents($l->credit);
            $m = $matched[$l->id] ?? 0;

            return [
                'line_id'    => $l->id,
                'entry_id'   => $l->journal_entry_id,
                'label'      => $l->entry->label(),
                'date'       => $l->entry->date->toDateString(),
                'account'    => ['id' => $l->account->id, 'code' => $l->account->code, 'name' => $l->account->name],
                'doc_number' => $l->doc_number,
                'due_date'   => $l->due_date?->toDateString(),
                'description' => $l->description,
                'amount'     => Money::out($amount),
                'matched'    => Money::out($m),
                'remaining'  => Money::out(($amount <=> 0) * (abs($amount) - $m)),
            ];
        })->filter(fn ($r) => $all || $r['remaining'] != 0)->values();
    }

    public function match(Firm $firm, User $user, int $lineId, int $otherId, ?string $amount = null): OpenItemMatch
    {
        return DB::transaction(function () use ($firm, $user, $lineId, $otherId, $amount) {
            $lines = $this->postedLines($firm)->whereIn('journal_lines.id', [$lineId, $otherId])
                ->with('account')->lockForUpdate()->get(['journal_lines.*'])->keyBy('id');

            $a = $lines[$lineId] ?? null;
            $b = $lines[$otherId] ?? null;

            if (! $a || ! $b || $lineId === $otherId) {
                throw ValidationException::withMessages(['line_id' => 'Ставките не постојат, не се прокнижени или се иста ставка.']);
            }
            if ((int) $a->account_id !== (int) $b->account_id || ! $a->partner_id || (int) $a->partner_id !== (int) $b->partner_id) {
                throw ValidationException::withMessages(['other_line_id' => 'Се затвораат само ставки на исто конто и ист партнер.']);
            }

            $sa = Money::cents($a->debit) - Money::cents($a->credit);
            $sb = Money::cents($b->debit) - Money::cents($b->credit);
            if (($sa <=> 0) * ($sb <=> 0) !== -1) {
                throw ValidationException::withMessages(['other_line_id' => 'Се затвораат само ставки со спротивни знаци (пр. фактура и уплата).']);
            }

            $matched = $this->matchedByLine([$a->id, $b->id]);
            $max = min(abs($sa) - ($matched[$a->id] ?? 0), abs($sb) - ($matched[$b->id] ?? 0));
            $want = $amount === null || $amount === '' ? $max : abs(Money::cents($amount));

            if ($max <= 0) {
                throw ValidationException::withMessages(['amount' => 'Една од ставките е веќе целосно затворена.']);
            }
            if ($want <= 0 || $want > $max) {
                throw ValidationException::withMessages(['amount' => 'Износот може да биде најмногу '.number_format($max / 100, 2, ',', '.').'.']);
            }

            return OpenItemMatch::create([
                'firm_id' => $firm->id, 'line_a_id' => $a->id, 'line_b_id' => $b->id,
                'amount' => Money::db($want), 'kind' => 'manual', 'created_by' => $user->id,
            ]);
        });
    }

    /**
     * По сторно: затворањата на оригиналот се отвораат (уплатата што беше
     * врзана за сторнираната фактура пак е отворена), а оригиналот се затвора
     * со својата сторно ставка — двете заедно се нула и не смеат да висат
     * како „отворени“.
     */
    public function afterStorno(JournalEntry $original, JournalEntry $storno, User $user): void
    {
        $original->loadMissing('lines.account');
        $byLineNo = $storno->lines->keyBy('line_no');

        foreach ($original->lines as $l) {
            if (! $l->partner_id || ! $l->account->needs_partner) {
                continue;
            }

            OpenItemMatch::where('line_a_id', $l->id)->orWhere('line_b_id', $l->id)->delete();

            $amount = abs(Money::cents($l->debit) - Money::cents($l->credit));
            $pair = $byLineNo[$l->line_no] ?? null;

            if ($pair && $amount > 0) {
                OpenItemMatch::create([
                    'firm_id' => $original->firm_id, 'line_a_id' => $l->id, 'line_b_id' => $pair->id,
                    'amount' => Money::db($amount), 'kind' => 'storno', 'created_by' => $user->id,
                ]);
            }
        }
    }

    private function postedLines(Firm $firm)
    {
        return JournalLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_lines.journal_entry_id')
            ->where('journal_entries.firm_id', $firm->id)
            ->where('journal_entries.status', JournalEntry::POSTED);
    }

    /**
     * @param  array<int>  $lineIds
     * @return array<int, int> затворено во стотинки по ставка
     */
    private function matchedByLine(array $lineIds): array
    {
        if (! $lineIds) {
            return [];
        }

        $out = [];
        $rows = OpenItemMatch::whereIn('line_a_id', $lineIds)->orWhereIn('line_b_id', $lineIds)->get();

        foreach ($rows as $m) {
            $c = Money::cents($m->amount);
            foreach ([$m->line_a_id, $m->line_b_id] as $id) {
                if (in_array((int) $id, $lineIds)) {
                    $out[$id] = ($out[$id] ?? 0) + $c;
                }
            }
        }

        return $out;
    }
}
