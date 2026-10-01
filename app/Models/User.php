<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Корисник. Правата НЕ се на корисникот туку на паровот корисник–фирма:
 * `hasPermission()` и `canWrite()` секогаш прашуваат „во која фирма“.
 * Без фирма (рута без `X-Firm`) обичен корисник нема ниту едно право —
 * само главниот администратор.
 */
class User extends Authenticatable
{
    // Дозволите НЕ се пишуваат во токенот (Sanctum abilities): се читаат при
    // секој повик, за промена на улогата да важи веднаш.
    use HasApiTokens, Notifiable;

    protected $fillable = ['name', 'email', 'password', 'is_super', 'is_active'];

    protected $hidden = ['password', 'remember_token'];

    /** @var array<int, Role|null> Улогата по фирма, запаметена во текот на барањето. */
    private array $roleMemo = [];

    protected function casts(): array
    {
        return [
            'password'  => 'hashed',
            'is_super'  => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function firms(): BelongsToMany
    {
        return $this->belongsToMany(Firm::class)->withPivot('role_id')->withTimestamps();
    }

    public function isSuper(): bool
    {
        return (bool) $this->is_super;
    }

    /** Фирмите што корисникот смее да ги отвори (главниот администратор — сите). */
    public function accessibleFirms()
    {
        if ($this->isSuper()) {
            return Firm::orderBy('name')->get();
        }

        return $this->firms()->where('firms.is_active', true)->orderBy('name')->get();
    }

    public function canAccessFirm(Firm $firm): bool
    {
        if ($this->isSuper()) {
            return true;
        }

        return $firm->is_active && $this->firms()->whereKey($firm->id)->exists();
    }

    public function roleIn(Firm $firm): ?Role
    {
        if (! array_key_exists($firm->id, $this->roleMemo)) {
            $roleId = $this->firms()->whereKey($firm->id)->first()?->pivot?->role_id;
            $this->roleMemo[$firm->id] = $roleId ? Role::find($roleId) : null;
        }

        return $this->roleMemo[$firm->id];
    }

    /** @return array<string> */
    public function permissionsIn(Firm $firm): array
    {
        if ($this->isSuper()) {
            return Role::allKeys();
        }

        return $this->roleIn($firm)?->knownPermissions() ?? [];
    }

    public function roleLabelIn(Firm $firm): string
    {
        if ($this->isSuper()) {
            return 'Главен администратор';
        }

        return $this->roleIn($firm)?->name ?? 'Без улога';
    }

    /** Преглед на секција во фирма (стандардно — тековната од `X-Firm`). */
    public function hasPermission(string $key, ?Firm $firm = null): bool
    {
        if ($this->isSuper()) {
            return true;
        }

        $firm ??= Firm::current();

        return $firm !== null && in_array($key, $this->permissionsIn($firm), true);
    }

    /** Запишување во секција во фирма. */
    public function canWrite(string $key, ?Firm $firm = null): bool
    {
        return $this->hasPermission($key.'.write', $firm);
    }
}
