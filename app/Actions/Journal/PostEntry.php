<?php

namespace App\Actions\Journal;

use App\Actions\Ledger\Counters;
use App\Actions\Ledger\EntryHash;
use App\Exceptions\DocumentLocked;
use App\Models\JournalEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Книжење: нацрт → прокнижен, со број, реден број во дневникот и хеш.
 *
 * Редоследот на заклучување е секогаш ист — прво „chain“ на фирмата, па
 * налогот. Затоа два истовремени книжења во иста фирма се редат еден по друг
 * (без дупки, без двојници, синџирот без рачвање), а во различни фирми не се
 * чекаат. Заклучување во различен ред би значело мртва точка.
 */
final class PostEntry
{
    public function run(JournalEntry $entry, User $user, bool $isStorno = false): JournalEntry
    {
        return DB::transaction(function () use ($entry, $user, $isStorno) {
            $firm = $entry->firm;
            $chain = Counters::lock($firm, 'chain');

            /** @var JournalEntry $e */
            $e = JournalEntry::whereKey($entry->id)->lockForUpdate()->firstOrFail();

            if ($e->isPosted()) {
                throw new DocumentLocked('Налогот '.$e->load('type')->label().' е веќе прокнижен.');
            }

            EntryRules::assertPostable($e, $isStorno);

            $e->number = Counters::next($firm, 'num:'.$e->year.':'.$e->journal_type_id);
            $e->posting_seq = Counters::next($firm, 'seq:'.$e->year);
            $e->chain_seq = (int) $chain->last_value + 1;
            $e->prev_hash = $chain->last_hash;
            $e->hash = EntryHash::of($e, $chain->last_hash);
            $e->status = JournalEntry::POSTED;
            $e->posted_by = $user->id;
            $e->posted_at = now();
            $e->save();

            Counters::advanceChain($chain, $e->chain_seq, $e->hash);

            return $e->fresh(['type', 'lines.account', 'lines.partner', 'lines.costCenter']);
        }, SaveEntry::ATTEMPTS);
    }
}
