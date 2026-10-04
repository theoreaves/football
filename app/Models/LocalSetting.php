<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LocalSetting extends Model
{
    protected $fillable = ['id', 'current_world_id'];
}
