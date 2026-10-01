<?php

namespace App\Models;

use App\Exceptions\DocumentLocked;
use App\Models\Concerns\BelongsToFirm;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Налог за книжење (темелница).
 *
 * Прокнижен налог е непроменлив на три нивоа: тука (модел), во базата
 * (`LedgerGuard` тригери) и во хешот (`Integrity` би го фатил секој рачен
 * зафат). Единствената дозволена промена на прокнижен налог е — никаква.
 */
class JournalEntry extends Model
{
    use BelongsToFirm;

    public const DRAFT = 'draft';

    public const POSTED = 'posted';

    protected $fillable = ['firm_id', 'journal_type_id', 'year', 'date', 'description', 'status', 'storno_of_id', 'created_by'];

    protected $casts = ['date' => 'date', 'posted_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(function (self $e) {
            if ($e->getOriginal('status') === self::POSTED) {
                throw new DocumentLocked('Прокнижен налог не се менува — само сторно.');
            }
        });

        static::deleting(function (self $e) {
            if ($e->getOriginal('status') === self::POSTED) {
                throw new DocumentLocked('Прокнижен налог не се брише — само сторно.');
            }
        });
    }

    /**
     * Датумот се запишува како чист `Y-m-d`. Кастот `date` би запишал
     * `Y-m-d 00:00:00`: MySQL го сече, но SQLite (тестовите) не — и тогаш
     * „до 31.01“ тивко го испушта 31.01, па тестовите лажат.
     */
    public function setDateAttribute($value): void
    {
        $this->attributes['date'] = $value === null ? null : \Carbon\Carbon::parse($value)->toDateString();
    }

    public function isPosted(): bool
    {
        return $this->status === self::POSTED;
    }

    public function firm(): BelongsTo
    {
        return $this->belongsTo(Firm::class);
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(JournalType::class, 'journal_type_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class)->orderBy('line_no');
    }

    public function stornoOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'storno_of_id');
    }

    public function stornoedBy(): HasOne
    {
        return $this->hasOne(self::class, 'storno_of_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function poster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    /** „ИФ-12/2027“ за прокнижен, „Нацрт #41“ за нацрт. */
    public function label(): string
    {
        if (! $this->isPosted()) {
            return 'Нацрт #'.$this->id;
        }

        return ($this->type?->code ?? '?').'-'.$this->number.'/'.$this->year;
    }
}
