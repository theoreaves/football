<?php

namespace App\Models;

use App\Models\Concerns\BelongsToWorld;
use Illuminate\Database\Eloquent\Model;

class Season extends Model
{
    use BelongsToWorld;

    protected $guarded = [];

    protected $casts = ['settings' => 'array'];

    public function fixtures(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(SeasonFixture::class);
    }

    public function league(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(League::class);
    }
}
