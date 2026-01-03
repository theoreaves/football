<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OffensePlay extends Model
{
    protected $fillable = [
        'code',
        'name',
    ];

    public function rolls(): HasMany
    {
        return $this->hasMany(OffensePlayRoll::class);
    }
}
