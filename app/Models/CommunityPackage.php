<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommunityPackage extends Model
{
    protected $fillable = ['user_id', 'title', 'description', 'kind', 'path', 'team_count', 'roster_count'];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
