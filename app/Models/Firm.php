<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Firm extends Model
{
    protected $fillable = [
        'name', 'short_name', 'tax_id', 'reg_no', 'activity_code',
        'size', 'vat_period', 'address', 'is_active',
    ];

    protected $casts = ['is_active' => 'boolean'];

    public const SIZES = ['micro', 'small', 'medium', 'large'];

    public const VAT_PERIODS = ['month', 'quarter'];

    /** Клуч под кој `ResolveFirm` ја става тековната фирма во контејнерот. */
    public const CONTAINER_KEY = 'firm.current';

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('role_id')->withTimestamps();
    }

    /**
     * Фирмата од `X-Firm` во тековното барање, или null.
     *
     * Намерно НЕМА резервна „прва фирма“: код што ќе ја побара надвор од рута
     * со `firm` треба да добие null и да падне гласно, а не тивко да запише
     * во туѓа фирма.
     */
    public static function current(): ?self
    {
        return app()->bound(self::CONTAINER_KEY) ? app(self::CONTAINER_KEY) : null;
    }
}
