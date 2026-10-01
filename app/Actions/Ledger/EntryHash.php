<?php

namespace App\Actions\Ledger;

use App\Models\JournalEntry;
use App\Support\Money;

/**
 * Хеш на прокнижен налог, врзан за хешот на претходниот (синџир по фирма).
 *
 * Промена на кој било прокнижен налог — дури и рачна, во базата, покрај
 * тригерите — го крши синџирот од тој налог наваму, и „Интегритет“ го покажува
 * точно каде. Ова е доказот за даночна контрола дека книгите не се дирани.
 *
 * ⚠ Содржината на хешот НЕ смее да се менува откако ќе има прокнижени
 * налози во продукција — секој стар налог би изгледал „расипан“. Ако мора,
 * нова верзија со ознака (`v2|…`), а старите се проверуваат по старата.
 */
final class EntryHash
{
    public static function of(JournalEntry $entry, ?string $prevHash): string
    {
        $entry->loadMissing('type', 'lines.account');

        $payload = [
            'v'           => 1,
            'firm'        => $entry->firm_id,
            'year'        => (int) $entry->year,
            'type'        => $entry->type->code,
            'number'      => (int) $entry->number,
            'posting_seq' => (int) $entry->posting_seq,
            'chain_seq'   => (int) $entry->chain_seq,
            'date'        => $entry->date->format('Y-m-d'),
            'description' => (string) $entry->description,
            'storno_of'   => $entry->storno_of_id,
            'lines'       => $entry->lines->map(fn ($l) => [
                (int) $l->line_no,
                $l->account->code,
                $l->partner_id,
                $l->cost_center_id,
                (string) $l->doc_number,
                $l->doc_date?->format('Y-m-d'),
                $l->due_date?->format('Y-m-d'),
                Money::cents($l->debit),
                Money::cents($l->credit),
                (string) $l->description,
            ])->values()->all(),
        ];

        return hash('sha256', ($prevHash ?? '').'|'.json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
