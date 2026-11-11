<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ArcadeGameFlow extends Model
{
    protected $guarded = [];

    protected $casts = ['revision' => 'integer', 'intro_seen' => 'boolean', 'opening_enabled' => 'boolean'];
}
