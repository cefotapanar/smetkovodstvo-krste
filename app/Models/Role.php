<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Улога = шаблон на дозволи. Се доделува по фирма (`firm_user.role_id`).
 *
 * Каталогот е ЕДЕН (GROUPS) — од него се гради и екранот за улоги и менито
 * во апликацијата. Втор список би значел мени што покажува екран до кој
 * серверот потоа не пушта (или обратно).
 */
class Role extends Model
{
    protected $fillable = ['name', 'permissions'];

    protected $casts = ['permissions' => 'array'];

    /** Клучеви што немаат „Запишува“ — само преглед. */
    public const READONLY_KEYS = ['cards', 'reports'];

    public const GROUPS = [
        'Книговодство' => [
            'journal'  => 'Налози за книжење',
            'inbox'    => 'Сандаче (документи од ЕРП)',
            'accounts' => 'Контен план',
            'partners' => 'Партнери',
        ],
        'Прегледи' => [
            'cards'   => 'Картички (конто, партнер)',
            'reports' => 'Бруто биланс, дневник, главна книга',
        ],
        'ДДВ' => [
            'vat' => 'КИФ, КПФ, ДДВ-04',
        ],
        'Средства' => [
            'fixed_assets' => 'Основни средства',
        ],
        'Затворање' => [
            'closing' => 'Заклучување на период и година',
        ],
    ];

    public static function supportsWrite(string $key): bool
    {
        return ! in_array($key, self::READONLY_KEYS, true);
    }

    /** Сите валидни клучеви (преглед + `.write`). */
    public static function allKeys(): array
    {
        $view = array_merge(...array_map('array_keys', array_values(self::GROUPS)));
        $write = array_map(
            fn ($k) => $k.'.write',
            array_filter($view, fn ($k) => self::supportsWrite($k)),
        );

        return array_values(array_merge($view, $write));
    }

    /**
     * Дозволите на улогата исчистени од непознати клучеви.
     *
     * Клуч што исчезнал од каталогот (преименуван екран) инаку би останал
     * засекогаш во базата и некој ден, со ново значење на истото име, би дал
     * право што никој не го доделил.
     *
     * @return array<string>
     */
    public function knownPermissions(): array
    {
        return array_values(array_intersect((array) $this->permissions, self::allKeys()));
    }
}
