<?php

namespace App\Projekt;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * ПРИВРЕМЕНО — ставките од кодот (`database/data/projekt.php`) влегуваат во
 * таблата при првото отворање по `git pull`.
 *
 * Така одлука донесена во разговорот „оди на веб со следниот update“ без
 * никој да ја претипкува. Правило: кодот го носи ТЕКСТОТ на своите ставки;
 * одговорите, коментарите и одлуките внесени на страницата не ги допира
 * никогаш — тие се на луѓето, не на кодот.
 */
final class ProjectSync
{
    public static function file(): string
    {
        return database_path('data/projekt.php');
    }

    /** Синхронизира само ако датотеката се сменила од последниот пат. */
    public static function ifChanged(): void
    {
        $hash = md5_file(self::file());

        if (Cache::get('projekt-sync-hash') !== $hash) {
            self::run();
            Cache::forever('projekt-sync-hash', $hash);
        }
    }

    public static function run(): void
    {
        $items = require self::file();

        DB::transaction(function () use ($items) {
            foreach ($items as $i => $data) {
                $item = ProjectItem::firstOrNew(['key' => $data['key']]);

                $item->fill([
                    'side'  => $data['side'],
                    'kind'  => $data['kind'] ?? 'question',
                    'phase' => $data['phase'] ?? null,
                    'title' => $data['title'],
                    'body'  => $data['body'] ?? null,
                    'sort'  => $i,
                ]);

                $decidedHere = $item->exists && $item->isDecided() && $item->decided_by !== null;

                // Одлука донесена во разговорот — се запишува, освен ако некој
                // веќе одлучил на самата страница (тогаш таа има предност).
                if (($data['status'] ?? 'open') === 'decided' && ! $decidedHere) {
                    $item->status = 'decided';
                    $item->decision = $data['decision'] ?? null;
                    $item->decided_by_name = $data['decided_by'] ?? 'во разговор';
                    $item->decided_at = isset($data['date']) ? Carbon::parse($data['date']) : ($item->decided_at ?? now());
                } elseif (! $item->exists) {
                    $item->status = 'open';
                }

                $item->save();
            }
        });
    }
}
