<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DefensePlay extends Model
{
    protected $guarded = [];

    public function rolls(): HasMany
    {
        return $this->hasMany(DefensePlayRoll::class);
    }
}
