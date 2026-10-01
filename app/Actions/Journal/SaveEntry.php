<?php

namespace App\Actions\Journal;

use App\Actions\Ledger\Counters;
use App\Exceptions\DocumentLocked;
use App\Models\Firm;
use App\Models\JournalEntry;
use App\Models\PeriodLock;
use App\Models\User;
use App\Support\Money;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Нов нацрт или измена на нацрт. Ставките се заменуваат цели — така ред од
 * апликацијата е секогаш точно она што е на екранот, без спојување.
 *
 * Со `post: true` зачувувањето и книжењето се ЕДНА трансакција: ако
 * книжењето падне (Д ≠ П), не останува ни нацртот — апликацијата сè уште го
 * држи внесеното и човекот поправа таму.
 */
final class SaveEntry
{
    /**
     * Laravel сам ја повторува трансакцијата кога MySQL ќе ја прекине како
     * deadlock. Безбедно е: сè во неа се враќа, а броевите се доделуваат дури
     * при книжењето, па повторот не остава дупка.
     */
    public const ATTEMPTS = 3;

    public function __construct(private PostEntry $post)
    {
    }

    public function run(Firm $firm, User $user, array $data, ?JournalEntry $entry = null): JournalEntry
    {
        if ($entry?->isPosted()) {
            throw new DocumentLocked('Прокнижен налог не се менува — само сторно.');
        }

        $date = Carbon::parse($data['date']);
        if (PeriodLock::covers($firm, $date)) {
            throw ValidationException::withMessages(['date' => EntryRules::lockedMessage($date)]);
        }

        return DB::transaction(function () use ($firm, $user, $data, $entry, $date) {
            // При „зачувај и прокнижи“ бројачот на фирмата се заклучува ПРВ,
            // пред да се запише ред. Инаку два процеса прво ги пишуваат своите
            // ставки, па се чекаат еден со друг на бројачот — MySQL тоа го
            // прекинува како deadlock (пробата со три паралелни процеси:
            // 62 од 180 книжења паднаа). Со ова тие се редат уште на влез.
            if (! empty($data['post'])) {
                Counters::lock($firm, 'chain');
            }

            $entry ??= new JournalEntry(['firm_id' => $firm->id, 'status' => JournalEntry::DRAFT, 'created_by' => $user->id]);
            $entry->journal_type_id = (int) $data['journal_type_id'];
            $entry->date = $date->toDateString();
            $entry->year = (int) $date->format('Y');
            if (array_key_exists('description', $data)) {
                $entry->description = $data['description'];
            }
            $entry->save();

            $entry->lines()->delete();

            // validated() може да ги преуреди редовите кога некој клуч фали
            // (научено во ЕРП-от) — редоследот на налогот мора да е како на екранот.
            $lines = $data['lines'] ?? [];
            ksort($lines);

            $no = 0;
            foreach ($lines as $l) {
                $entry->lines()->create([
                    'line_no'        => ++$no,
                    'account_id'     => (int) $l['account_id'],
                    'partner_id'     => $l['partner_id'] ?? null,
                    'cost_center_id' => $l['cost_center_id'] ?? null,
                    'doc_number'     => $l['doc_number'] ?? null,
                    'doc_date'       => $l['doc_date'] ?? null,
                    'due_date'       => $l['due_date'] ?? null,
                    'debit'          => Money::db(Money::cents($l['debit'] ?? 0)),
                    'credit'         => Money::db(Money::cents($l['credit'] ?? 0)),
                    'description'    => $l['description'] ?? null,
                ]);
            }

            if (! empty($data['post'])) {
                return $this->post->run($entry, $user);
            }

            return $entry->fresh(['type', 'lines.account', 'lines.partner', 'lines.costCenter']);
        }, self::ATTEMPTS);
    }
}
