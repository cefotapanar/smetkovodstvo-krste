<?php

namespace App\Support;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Споредба меѓу миграциите што ги носи КОДОТ и оние што се извршени во БАЗАТА.
 *
 * Зошто воопшто: при ажурирање прво се повлекува кодот, па дури потоа се
 * извршуваат миграциите. Во тој меѓупростор нов код гледа стара база и
 * запишувањето паѓа со гола SQL грешка („Unknown column…"). Уште полошо е
 * ако некој заборави да ги пушти миграциите — тогаш состојбата трае со денови.
 *
 * Не се води посебна колона со „верзија“: Laravel и онака во секоја база држи
 * табела `migrations` со список на извршени миграции. Тоа е изворот на вистина
 * и не може да се разлади со заборавено запишување.
 */
class SchemaState
{
    /** @var array<string>|null Список од дискот — не се менува во текот на барањето. */
    private static ?array $files = null;

    /** @var array<string, array{pending: array<string>, ahead: array<string>}> */
    private static array $memo = [];

    /**
     * Миграциите што ги носи кодот.
     * Шемата `*_*.php` е истата што ја користи и Laravel Migrator, за да не
     * се разликува нашето броење од неговото.
     *
     * @return array<string>
     */
    public static function expected(): array
    {
        if (self::$files !== null) {
            return self::$files;
        }

        $files = glob(database_path('migrations') . '/*_*.php');

        // glob() враќа false при проблем со папката (дозволи, open_basedir).
        // Празна листа тука би значела „нема ништо да се мигрира“ — значи тивко
        // изгаснат стражар. Подобро е гласно да падне и да се пријави.
        if ($files === false) {
            throw new \RuntimeException('Не може да се прочита database/migrations.');
        }

        $out = [];
        foreach ($files as $file) {
            $out[] = basename($file, '.php');
        }
        sort($out);

        return self::$files = $out;
    }

    /** Името на табелата со миграции (може да се смени во config/database.php). */
    private static function table(): string
    {
        $conf = config('database.migrations', 'migrations');

        return is_array($conf) ? ($conf['table'] ?? 'migrations') : (string) $conf;
    }

    /**
     * Миграциите запишани во базата.
     *
     * Забелешка за иднина: миграција што си објавува сопствена врска
     * (`protected $connection = 'druga'`) би го запишала својот ред во ТАА
     * база, а овде би изгледала како вечно неизвршена. Ако некогаш затреба
     * таква миграција, мора да се изземе и тука.
     *
     * @return array<string>
     */
    public static function applied(?string $connection = null): array
    {
        // Празна база (прво пуштање) нема ни табела `migrations` — тоа значи
        // „ништо не е извршено“, не грешка. Како грешка, споредбата паѓаше,
        // заостанувањето излегуваше празно и sistem.php пишуваше „базата е
        // ажурирана“ на база без ниедна табела (прво пуштање на Plesk, 0.3.0).
        if (! Schema::connection($connection)->hasTable(self::table())) {
            return [];
        }

        $rows = DB::connection($connection)->table(self::table())->pluck('migration')->all();
        $rows = array_map('strval', $rows);
        sort($rows);

        return $rows;
    }

    /**
     * Миграции што ги има кодот, а ги нема базата — базата ЗАОСТАНУВА.
     * Ова е случајот што го блокира запишувањето.
     *
     * @return array<string>
     */
    public static function pending(?string $connection = null): array
    {
        return self::compare($connection)['pending'];
    }

    /**
     * Миграции што ги има базата, а ги нема кодот — кодот е ВРАТЕН НАЗАД.
     * Ова само се пријавува; блокада тука би значела заклучување без излез.
     *
     * @return array<string>
     */
    public static function ahead(?string $connection = null): array
    {
        return self::compare($connection)['ahead'];
    }

    /** Дали базата е усогласена со кодот (заостанување = не). */
    public static function isCurrent(?string $connection = null): bool
    {
        return self::pending($connection) === [];
    }

    /**
     * Колку миграции заостануваат — безбедна верзија за приказ во менито.
     * НИКОГАШ не фрла: прекин во исцртувањето на страницата поради проверка
     * би бил полош од самиот проблем што го открива.
     */
    public static function behindCount(?string $connection = null): int
    {
        try {
            return count(self::pending($connection));
        } catch (\Throwable) {
            return 0;
        }
    }

    /** Последната миграција што ја носи кодот — читливо за приказ. */
    public static function head(): ?string
    {
        $all = self::expected();

        return $all === [] ? null : end($all);
    }

    /**
     * Споредбата се памти во текот на едно барање: истата проверка се повикува
     * и од стражарот и од лентата во менито, а нема потреба двапати да се оди
     * до базата.
     *
     * @return array{pending: array<string>, ahead: array<string>}
     */
    private static function compare(?string $connection): array
    {
        $key = $connection ?? '@default';
        if (isset(self::$memo[$key])) {
            return self::$memo[$key];
        }

        $expected = self::expected();
        $applied  = self::applied($connection);

        return self::$memo[$key] = [
            'pending' => array_values(array_diff($expected, $applied)),
            'ahead'   => array_values(array_diff($applied, $expected)),
        ];
    }

    /**
     * ── Привремено паузирање ────────────────────────────────────────────────
     *
     * Излез за случајот кога миграцијата паѓа на пола пат: MySQL не враќа назад
     * структурни промени, па повторното пуштање пак паѓа („колоната веќе
     * постои“) и запишувањето би останало сопрено додека некој не влезе во
     * .env или во phpMyAdmin. Со ова администраторот си купува време од самата
     * апликација, без пристап до серверот.
     */
    private const PAUSE_KEY = 'schema-guard-paused-until';

    public static function pause(int $minutes = 30): void
    {
        $until = now()->addMinutes($minutes);
        self::store()->put(self::PAUSE_KEY, $until->getTimestamp(), $until);
    }

    public static function resume(): void
    {
        self::store()->forget(self::PAUSE_KEY);
    }

    /** Дали стражарот е привремено паузиран (никогаш не фрла). */
    public static function isPaused(): bool
    {
        return self::pausedUntil() !== null;
    }

    /** До кога е паузиран, или null. */
    public static function pausedUntil(): ?Carbon
    {
        try {
            $ts = self::store()->get(self::PAUSE_KEY);

            return $ts ? Carbon::createFromTimestamp((int) $ts) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Паузата и заклучувањето на миграцијата НЕ смеат да живеат во базата:
     * кешот на апликацијата е во базата (CACHE_STORE=database), а токму
     * кога базата е празна или на пола миграција тие најмногу требаат.
     * Првото пуштање на Plesk паѓаше на „cache_locks doesn't exist“.
     * Стандардно датотека; тестовите — array (config/schema.php).
     */
    public static function store(): \Illuminate\Contracts\Cache\Repository
    {
        return Cache::store(config('schema.store', 'file'));
    }

    /** Само за тестови — да се потроши запаметеното меѓу два случаја. */
    public static function flushMemo(): void
    {
        self::$files = null;
        self::$memo  = [];
    }
}
