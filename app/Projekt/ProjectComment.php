<?php

namespace App\Projekt;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** ПРИВРЕМЕНО — коментар под ставка на работната табла. */
class ProjectComment extends Model
{
    protected $fillable = ['project_item_id', 'user_id', 'body'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
