<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ArcadeFlowReceipt extends Model
{
    protected $guarded = [];

    protected $casts = ['result' => 'array'];
}
