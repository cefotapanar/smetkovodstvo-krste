<?php

namespace App\Models;

use App\Models\Concerns\BelongsToFirm;
use Illuminate\Database\Eloquent\Model;

class CostCenter extends Model
{
    use BelongsToFirm;

    protected $fillable = ['firm_id', 'code', 'name', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function isUsed(): bool
    {
        return JournalLine::where('cost_center_id', $this->id)->exists();
    }
}
