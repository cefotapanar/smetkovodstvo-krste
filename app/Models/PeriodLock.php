<?php

namespace App\Models;

use App\Models\Concerns\BelongsToFirm;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PeriodLock extends Model
{
    use BelongsToFirm;

    protected $fillable = ['firm_id', 'year', 'month', 'locked_by'];

    public function locker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'locked_by');
    }

    /** Дали месецот на датумот е заклучен во фирмата. */
    public static function covers(Firm $firm, \DateTimeInterface|string $date): bool
    {
        $d = $date instanceof \DateTimeInterface ? $date : new \DateTimeImmutable($date);

        return self::where('firm_id', $firm->id)
            ->where('year', (int) $d->format('Y'))
            ->where('month', (int) $d->format('n'))
            ->exists();
    }
}
