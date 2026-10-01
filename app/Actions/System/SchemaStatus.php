<?php

namespace App\Actions\System;

use App\Support\SchemaState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * „Состојба на базата“ — копија од ЕРП-от. Ја викаат `public/sistem.php`
 * (прво пуштање, кога уште нема корисник) и апликацијата (Систем → База).
 *
 * На Plesk нема SSH за `php artisan migrate`, па ова е единствениот начин
 * базата да се дотера по ажурирање на кодот.
 */
class SchemaStatus
{
    public function status(): array
    {
        $error = null;
        $pending = $ahead = $applied = [];

        try {
            $pending = SchemaState::pending();
            $ahead   = SchemaState::ahead();
            $applied = SchemaState::applied();
        } catch (\Throwable $e) {
            report($e);
            $error = $e->getMessage();
        }

        try {
            $expected = SchemaState::expected();
        } catch (\Throwable $e) {
            report($e);
            $expected = [];
            $error ??= $e->getMessage();
        }

        return [
            'expected'   => $expected,
            'applied'    => $applied,
            'pending'    => $pending,
            'ahead'      => $ahead,
            'head'       => $expected === [] ? null : end($expected),
            'error'      => $error,
            'guardOn'    => (bool) config('schema.guard', true),
            // Кеширан конфиг од порано го нема config/schema.php — тогаш важи
            // стандардот, а не она што пишува во .env. Мора да се разликува.
            'guardKnown' => config()->has('schema.guard'),
            'pausedTill' => SchemaState::pausedUntil(),
            'connection' => DB::getDefaultConnection(),
            'database'   => DB::connection()->getDatabaseName(),
        ];
    }

    /**
     * @return array{ok: bool, message: string, output: string, failed: bool}
     *         `ok=false, failed=false` — друга миграција веќе тече.
     */
    public function migrate(): array
    {
        // Две истовремени миграции би тргнале по иста табела и втората би
        // паднала на пола пат. Заклучувањето е поевтино од чистењето потоа.
        $lock = Cache::lock('schema-migrate', 600);

        if (! $lock->get()) {
            return ['ok' => false, 'failed' => false, 'output' => '',
                'message' => 'Миграцијата веќе се извршува. Почекај да заврши, па освежи.'];
        }

        try {
            @set_time_limit(600);
            Artisan::call('migrate', ['--force' => true]);

            return ['ok' => true, 'failed' => false, 'message' => 'Миграциите се извршени.',
                'output' => trim(Artisan::output())];
        } catch (\Throwable $e) {
            report($e);

            // MySQL не враќа назад структурни промени, па паднатата миграција
            // може да остане на пола. Повторното пуштање тогаш пак ќе падне —
            // затоа тука се нуди паузата, а не само гола порака за грешка.
            return ['ok' => false, 'failed' => true, 'message' => 'Миграцијата падна: ' . $e->getMessage(),
                'output' => trim(Artisan::output())];
        } finally {
            $lock->release();
        }
    }

    /**
     * Привремено гаснење на стражарот — излез кога миграцијата паѓа на пола
     * пат и запишувањето би останало сопрено без пристап до серверот.
     */
    public function pause(): string
    {
        SchemaState::pause(30);

        return 'Стражарот е паузиран 30 минути. Запишувањето е дозволено, но внимавај — базата сè уште заостанува.';
    }

    public function resume(): string
    {
        SchemaState::resume();

        return 'Стражарот е повторно вклучен.';
    }
}
