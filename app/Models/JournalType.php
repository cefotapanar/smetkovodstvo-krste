<?php

namespace App\Models;

use App\Models\Concerns\BelongsToFirm;
use Illuminate\Database\Eloquent\Model;

class JournalType extends Model
{
    use BelongsToFirm;

    protected $fillable = ['firm_id', 'code', 'name', 'is_opening'];

    protected $casts = ['is_opening' => 'boolean'];
}
