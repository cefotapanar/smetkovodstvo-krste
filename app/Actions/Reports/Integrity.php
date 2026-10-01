<?php

namespace App\Actions\Reports;

use App\Actions\Ledger\EntryHash;
use App\Models\Firm;
use App\Models\JournalEntry;
use App\Support\LedgerGuard;

/**
 * „Дали некој ги дирал книгите?“ — одговор што се докажува, не се тврди.
 *
 * Се проверуваат три работи: синџирот на хешови (секоја промена на
 * прокнижен налог го крши од тоа место), броевите без дупки (дневник по
 * година, налог по вид и година) и дали тригерите во базата постојат.
 */
final class Integrity
{
    public function run(Firm $firm): array
    {
        $prev = null;
        $expected = 1;
        $broken = null;
        $checked = 0;

        JournalEntry::where('firm_id', $firm->id)->where('status', JournalEntry::POSTED)
            ->orderBy('chain_seq')
            ->with('type', 'lines.account')
            ->chunk(200, function ($entries) use (&$prev, &$expected, &$broken, &$checked) {
                foreach ($entries as $e) {
                    $checked++;
                    $ok = (int) $e->chain_seq === $expected
                        && $e->prev_hash === $prev
                        && hash_equals((string) $e->hash, EntryHash::of($e, $prev));

                    if (! $ok) {
                        $broken = ['chain_seq' => $e->chain_seq, 'entry_id' => $e->id, 'label' => $e->label()];

                        return false;
                    }

                    $prev = $e->hash;
                    $expected++;
                }
            });

        return [
            'chain_ok'  => $broken === null,
            'checked'   => $checked,
            'broken_at' => $broken,
            'gaps'      => $this->gaps($firm),
            'db_guard'  => LedgerGuard::isInstalled(),
        ];
    }

    /** Дупки во дневникот (по година) и во броевите на налозите (по вид и година). */
    private function gaps(Firm $firm): array
    {
        $out = [];
        $rows = JournalEntry::where('firm_id', $firm->id)->where('status', JournalEntry::POSTED)
            ->with('type')->get(['id', 'year', 'journal_type_id', 'number', 'posting_seq']);

        foreach ($rows->groupBy('year') as $year => $byYear) {
            if ($missing = self::missing($byYear->pluck('posting_seq')->all())) {
                $out[] = ['year' => (int) $year, 'what' => 'дневник', 'missing' => $missing];
            }
            foreach ($byYear->groupBy('journal_type_id') as $byType) {
                if ($missing = self::missing($byType->pluck('number')->all())) {
                    $out[] = ['year' => (int) $year, 'what' => $byType->first()->type->code, 'missing' => $missing];
                }
            }
        }

        return $out;
    }

    /** @return array<int> */
    private static function missing(array $nums): array
    {
        $nums = array_map('intval', $nums);
        $max = $nums ? max($nums) : 0;

        return array_values(array_diff(range(1, max($max, 1)), $nums ?: [1]));
    }
}
