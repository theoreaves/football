<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorld;
use Illuminate\Database\Eloquent\Model;

class League extends Model
{
    use BelongsToWorld;

    protected $guarded = [];

    public function seasons(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Season::class);
    }
}
