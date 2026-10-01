<?php

namespace App\Actions\Journal;

use App\Actions\Ledger\Counters;
use App\Actions\Ledger\OpenItems;
use App\Exceptions\DocumentLocked;
use App\Models\JournalEntry;
use App\Models\PeriodLock;
use App\Models\User;
use App\Support\Money;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * „Црвено“ сторно: нов налог со ИСТИТЕ конта и страни и негативни износи.
 *
 * Зошто црвено, а не спротивни страни: спротивните страни го надуваат
 * прометот (фактура од 100 и нејзино сторно би покажале промет 100 на двете
 * страни), а бруто билансот и ДДВ-евиденцијата треба да покажат дека
 * прометот е 0. Салдото излегува исто во двата начина.
 */
final class StornoEntry
{
    public function __construct(private PostEntry $post, private OpenItems $openItems)
    {
    }

    public function run(JournalEntry $original, User $user, ?string $date = null, ?string $description = null): JournalEntry
    {
        $original->loadMissing('type', 'lines');

        if (! $original->isPosted()) {
            throw new DocumentLocked('Нацрт не се сторнира — само се брише.');
        }
        if ($original->storno_of_id) {
            throw new DocumentLocked('Сторно налог не се сторнира. Ако сторното е грешка, внесете го оригиналот одново.');
        }
        if ($done = $original->stornoedBy()->first()) {
            throw new DocumentLocked('Налогот '.$original->label().' е веќе сторниран со '.$done->load('type')->label().'.');
        }

        $when = Carbon::parse($date ?? $original->date);
        if (PeriodLock::covers($original->firm, $when)) {
            throw ValidationException::withMessages(['date' => $date
                ? EntryRules::lockedMessage($when)
                : 'Месецот на оригиналот ('.$when->format('m/Y').') е заклучен — изберете датум на сторното во отворен период.']);
        }

        try {
            return DB::transaction(function () use ($original, $user, $when, $description) {
                Counters::lock($original->firm, 'chain'); // истата причина како во SaveEntry

                $storno = JournalEntry::create([
                    'firm_id'         => $original->firm_id,
                    'journal_type_id' => $original->journal_type_id,
                    'year'            => (int) $when->format('Y'),
                    'date'            => $when->toDateString(),
                    'description'     => $description ?: 'Сторно на '.$original->label(),
                    'status'          => JournalEntry::DRAFT,
                    'storno_of_id'    => $original->id,
                    'created_by'      => $user->id,
                ]);

                foreach ($original->lines as $l) {
                    $storno->lines()->create([
                        'line_no'        => $l->line_no,
                        'account_id'     => $l->account_id,
                        'partner_id'     => $l->partner_id,
                        'cost_center_id' => $l->cost_center_id,
                        'doc_number'     => $l->doc_number,
                        'doc_date'       => $l->doc_date,
                        'due_date'       => $l->due_date,
                        'debit'          => Money::db(-Money::cents($l->debit)),
                        'credit'         => Money::db(-Money::cents($l->credit)),
                        'description'    => $l->description,
                    ]);
                }

                $posted = $this->post->run($storno, $user, isStorno: true);
                $this->openItems->afterStorno($original, $posted, $user);

                return $posted;
            }, SaveEntry::ATTEMPTS);
        } catch (UniqueConstraintViolationException) {
            // Двајца сторнираат ист налог во ист миг — unique на storno_of_id го фати вториот.
            throw new DocumentLocked('Налогот '.$original->label().' е веќе сторниран.');
        }
    }
}
