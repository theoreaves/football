<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OffensePlay extends Model
{
    protected $fillable = [
        'code',
        'name',
        'play_type'
    ];

    public function rolls(): HasMany
    {
        return $this->hasMany(OffensePlayRoll::class);
    }
}
