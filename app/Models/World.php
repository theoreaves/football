<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class World extends Model
{
    protected $fillable = ['name', 'owner_user_id'];

    public function users(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(User::class, 'world_user')->withPivot('role')->withTimestamps();
    }

    public function leagues(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(League::class);
    }

    public function seasons(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Season::class);
    }

    public function teams(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Team::class);
    }

    public function players(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(Player::class);
    }
}
