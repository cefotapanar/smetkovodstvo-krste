<?php

namespace App\Models;

use App\Models\Concerns\BelongsToFirm;
use Illuminate\Database\Eloquent\Model;

class Partner extends Model
{
    use BelongsToFirm;

    protected $fillable = ['firm_id', 'name', 'tax_id', 'address', 'city', 'country', 'erp_customer_id', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function isUsed(): bool
    {
        return JournalLine::where('partner_id', $this->id)->exists();
    }
}
