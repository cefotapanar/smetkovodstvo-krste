<?php

namespace App\Models;

use App\Exceptions\DocumentLocked;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JournalLine extends Model
{
    protected $fillable = [
        'journal_entry_id', 'line_no', 'account_id', 'partner_id', 'cost_center_id',
        'doc_number', 'doc_date', 'due_date', 'debit', 'credit', 'description',
    ];

    protected $casts = ['doc_date' => 'date', 'due_date' => 'date'];

    protected static function booted(): void
    {
        $guard = function (self $line) {
            $ids = array_filter([$line->journal_entry_id, $line->getOriginal('journal_entry_id')]);

            if ($ids && JournalEntry::whereIn('id', $ids)->where('status', JournalEntry::POSTED)->exists()) {
                throw new DocumentLocked('Ставка на прокнижен налог не се менува — само сторно.');
            }
        };

        static::creating($guard);
        static::updating($guard);
        static::deleting($guard);
    }

    /** Чист `Y-m-d` — види JournalEntry::setDateAttribute. */
    public function setDocDateAttribute($value): void
    {
        $this->attributes['doc_date'] = $value ? \Carbon\Carbon::parse($value)->toDateString() : null;
    }

    public function setDueDateAttribute($value): void
    {
        $this->attributes['due_date'] = $value ? \Carbon\Carbon::parse($value)->toDateString() : null;
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(CostCenter::class);
    }
}
