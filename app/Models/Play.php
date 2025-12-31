<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Play extends Model
{
    protected $guarded = [];
    protected $casts = [
        'meta' => 'array',
    ];


    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    public function getSummaryAttribute(): string
    {
        $yards = $this->yards;
        $ydTxt = $yards === 0 ? "no gain" : ($yards > 0 ? "+{$yards}" : (string)$yards);

        $note = $this->note ? " — {$this->note}" : "";

        $flags = [];
        if ($this->first_down) $flags[] = "1st down";
        if ($this->turnover) $flags[] = "turnover";
        if ($this->touchdown) $flags[] = "TD";
        $flagTxt = $flags ? " (" . implode(", ", $flags) . ")" : "";

        if ($this->points > 0) {
            $flagTxt .= " [+{$this->points}]";
        }


        return "{$this->type} {$ydTxt}{$note}{$flagTxt}";
    }
}
