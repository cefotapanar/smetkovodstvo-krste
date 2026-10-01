<?php

namespace App\Models;

use App\Models\Concerns\BelongsToFirm;
use Illuminate\Database\Eloquent\Model;

class Account extends Model
{
    use BelongsToFirm;

    protected $fillable = ['firm_id', 'code', 'name', 'is_statutory', 'needs_partner', 'needs_cost_center', 'is_active'];

    protected $casts = [
        'is_statutory'      => 'boolean',
        'needs_partner'     => 'boolean',
        'needs_cost_center' => 'boolean',
        'is_active'         => 'boolean',
    ];

    public static function levelOf(string $code): string
    {
        return match (strlen($code)) {
            1       => 'class',
            2       => 'group',
            3       => 'synthetic',
            default => 'analytic',
        };
    }

    public function level(): string
    {
        return self::levelOf($this->code);
    }

    public function hasChildren(): bool
    {
        return self::where('firm_id', $this->firm_id)
            ->where('code', 'like', $this->code.'%')
            ->where('code', '!=', $this->code)
            ->exists();
    }

    /** Се книжи само на 3+ цифри без подконта — инаку збирот на родителот лаже. */
    public function isPostable(): bool
    {
        return strlen($this->code) >= 3 && ! $this->hasChildren();
    }

    public function isUsed(): bool
    {
        return JournalLine::where('account_id', $this->id)->exists();
    }
}
