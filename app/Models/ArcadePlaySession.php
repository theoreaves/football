<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArcadePlaySession extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = ['binding' => 'array', 'snapshot' => 'array', 'setup' => 'array', 'receipt' => 'array', 'recording' => 'array', 'expires_at' => 'immutable_datetime'];

    public function exhibition(): BelongsTo
    {
        return $this->belongsTo(Exhibition::class);
    }
}
