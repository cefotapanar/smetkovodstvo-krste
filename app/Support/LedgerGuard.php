<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Тригери што ги чуваат прокнижените налози во самата база.
 *
 * Постојат и за MySQL (продукција) и за SQLite (тестовите) — така тестовите
 * докажуваат дека базата одбива, а не само дека кодот не пробува.
 *
 * Пораката носи „[книги]“: по тоа `bootstrap/app.php` ја препознава грешката
 * од базата и враќа 409 наместо 500.
 */
final class LedgerGuard
{
    public const TAG = '[книги]';

    private const MSG_ENTRY = self::TAG.' Прокнижен налог не смее да се менува ниту брише.';

    private const MSG_LINE = self::TAG.' Ставка на прокнижен налог не смее да се менува.';

    public const NAMES = [
        'ledger_entries_no_update', 'ledger_entries_no_delete',
        'ledger_lines_no_insert', 'ledger_lines_no_update', 'ledger_lines_no_delete',
    ];

    public static function install(): void
    {
        self::uninstall();

        foreach (self::statements() as $name => $sql) {
            try {
                DB::unprepared($sql);
            } catch (\Throwable $e) {
                // Види ја миграцијата: без SUPER на Plesk не смее да ја заглави апликацијата.
                Log::warning('LedgerGuard: тригерот '.$name.' не е создаден: '.$e->getMessage());
            }
        }
    }

    public static function uninstall(): void
    {
        foreach (self::NAMES as $name) {
            try {
                DB::unprepared('DROP TRIGGER IF EXISTS '.$name);
            } catch (\Throwable) {
                // Нема што да се брише.
            }
        }
    }

    /** Дали СИТЕ тригери се на место. Никогаш не фрла. */
    public static function isInstalled(): bool
    {
        try {
            $driver = DB::getDriverName();

            if ($driver === 'mysql' || $driver === 'mariadb') {
                $n = DB::table('information_schema.TRIGGERS')
                    ->whereRaw('TRIGGER_SCHEMA = DATABASE()')
                    ->whereIn('TRIGGER_NAME', self::NAMES)
                    ->count();
            } elseif ($driver === 'sqlite') {
                $n = DB::table('sqlite_master')->where('type', 'trigger')->whereIn('name', self::NAMES)->count();
            } else {
                return false;
            }

            return $n === count(self::NAMES);
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return array<string, string> */
    private static function statements(): array
    {
        $driver = DB::getDriverName();
        $e = self::MSG_ENTRY;
        $l = self::MSG_LINE;
        $posted = "(SELECT status FROM journal_entries WHERE id = %s) = 'posted'";

        if ($driver === 'sqlite') {
            $t = fn (string $name, string $when, string $table, string $cond, string $msg) => "CREATE TRIGGER {$name} BEFORE {$when} ON {$table} FOR EACH ROW WHEN {$cond} BEGIN SELECT RAISE(ABORT, '{$msg}'); END";

            return [
                'ledger_entries_no_update' => $t('ledger_entries_no_update', 'UPDATE', 'journal_entries', "OLD.status = 'posted'", $e),
                'ledger_entries_no_delete' => $t('ledger_entries_no_delete', 'DELETE', 'journal_entries', "OLD.status = 'posted'", $e),
                'ledger_lines_no_insert'   => $t('ledger_lines_no_insert', 'INSERT', 'journal_lines', sprintf($posted, 'NEW.journal_entry_id'), $l),
                'ledger_lines_no_update'   => $t('ledger_lines_no_update', 'UPDATE', 'journal_lines', sprintf($posted, 'OLD.journal_entry_id').' OR '.sprintf($posted, 'NEW.journal_entry_id'), $l),
                'ledger_lines_no_delete'   => $t('ledger_lines_no_delete', 'DELETE', 'journal_lines', sprintf($posted, 'OLD.journal_entry_id'), $l),
            ];
        }

        if ($driver !== 'mysql' && $driver !== 'mariadb') {
            return [];
        }

        $t = fn (string $name, string $when, string $table, string $cond, string $msg) => "CREATE TRIGGER {$name} BEFORE {$when} ON {$table} FOR EACH ROW BEGIN IF {$cond} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$msg}'; END IF; END";

        return [
            'ledger_entries_no_update' => $t('ledger_entries_no_update', 'UPDATE', 'journal_entries', "OLD.status = 'posted'", $e),
            'ledger_entries_no_delete' => $t('ledger_entries_no_delete', 'DELETE', 'journal_entries', "OLD.status = 'posted'", $e),
            'ledger_lines_no_insert'   => $t('ledger_lines_no_insert', 'INSERT', 'journal_lines', sprintf($posted, 'NEW.journal_entry_id'), $l),
            'ledger_lines_no_update'   => $t('ledger_lines_no_update', 'UPDATE', 'journal_lines', sprintf($posted, 'OLD.journal_entry_id').' OR '.sprintf($posted, 'NEW.journal_entry_id'), $l),
            'ledger_lines_no_delete'   => $t('ledger_lines_no_delete', 'DELETE', 'journal_lines', sprintf($posted, 'OLD.journal_entry_id'), $l),
        ];
    }
}
