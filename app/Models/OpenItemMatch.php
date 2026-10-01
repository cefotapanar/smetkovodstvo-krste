<?php

namespace App\Models;

use App\Models\Concerns\BelongsToFirm;
use Illuminate\Database\Eloquent\Model;

class OpenItemMatch extends Model
{
    use BelongsToFirm;

    protected $fillable = ['firm_id', 'line_a_id', 'line_b_id', 'amount', 'kind', 'created_by'];
}
