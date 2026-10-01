<?php

namespace App\Projekt;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** ПРИВРЕМЕНО — ставка на работната табла (прашање, одлука, белешка). */
class ProjectItem extends Model
{
    public const SIDES = ['owner' => 'Сопственик', 'accountant' => 'Сметководител'];

    public const KINDS = ['question' => 'Прашање', 'decision' => 'Одлука', 'note' => 'Белешка'];

    protected $fillable = [
        'key', 'side', 'kind', 'phase', 'title', 'body', 'status', 'decision',
        'decided_by', 'decided_by_name', 'decided_at', 'created_by', 'sort',
    ];

    protected $casts = ['decided_at' => 'datetime'];

    public function comments(): HasMany
    {
        return $this->hasMany(ProjectComment::class)->orderBy('created_at')->orderBy('id');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isDecided(): bool
    {
        return $this->status === 'decided';
    }

    /** Кој одлучил — корисник од страницата или „во разговор“ (ставка од кодот). */
    public function deciderName(): ?string
    {
        return $this->decider?->name ?? $this->decided_by_name;
    }
}
