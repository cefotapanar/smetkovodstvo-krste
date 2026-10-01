<?php

namespace App\Models\Concerns;

use App\Models\Firm;
use Illuminate\Database\Eloquent\Builder;

/**
 * Записи што припаѓаат на фирма. `ofFirm()` без аргумент ја зема тековната
 * од `X-Firm`; без тековна фирма фрла — подобро гласно отколку тивко да
 * врати записи од сите фирми.
 */
trait BelongsToFirm
{
    public function scopeOfFirm(Builder $query, ?Firm $firm = null): Builder
    {
        $firm ??= Firm::current() ?? throw new \LogicException('Нема тековна фирма (рута без `firm`).');

        return $query->where($this->getTable().'.firm_id', $firm->id);
    }
}
