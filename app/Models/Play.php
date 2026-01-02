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

    // In App\Models\Play.php

    public function qb()
    {
        return $this->belongsTo(TeamPlayer::class, 'qb_team_player_id');
    }

    public function ballcarrier()
    {
        return $this->belongsTo(TeamPlayer::class, 'ballcarrier_team_player_id');
    }

    public function receiver()
    {
        return $this->belongsTo(TeamPlayer::class, 'receiver_team_player_id');
    }

    public function tackler()
    {
        return $this->belongsTo(TeamPlayer::class, 'tackled_by_team_player_id');
    }

    public function interceptor()
    {
        return $this->belongsTo(TeamPlayer::class, 'intercepted_by_team_player_id');
    }

    public function fumbleRecoverer()
    {
        return $this->belongsTo(TeamPlayer::class, 'fumble_recovered_by_team_player_id');
    }

    protected function fmtPlayer(?TeamPlayer $tp): ?string
    {
        if (! $tp) return null;

        $p = $tp->player;
        return "#{$tp->jersey_number} {$p->firstname} {$p->lastname}";
    }

    public function possessionBeforeTeam()
    {
        return $this->possession_before === 'HOME'
            ? $this->game->homeTeam
            : $this->game->awayTeam;
    }

    public function possessionAfterTeam()
    {
        return $this->possession_after === 'HOME'
            ? $this->game->homeTeam
            : $this->game->awayTeam;
    }


    public function getSimpleSummaryAttribute(): string
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

    public function getSummaryAttribute(): string
    {
        $yards = $this->yards;
        $ydTxt = $yards === 0
            ? "no gain"
            : ($yards > 0 ? "+{$yards}" : (string)$yards);

        $note = $this->note ? " — {$this->note}" : "";

        $flags = [];
        if ($this->first_down) $flags[] = "1st down";
        if ($this->turnover)  $flags[] = "turnover";
        if ($this->touchdown) $flags[] = "TD";

        $flagTxt = $flags ? " (" . implode(", ", $flags) . ")" : "";

        if ($this->points > 0) {
            $flagTxt .= " [+{$this->points}]";
        }

        // ---- PLAYER CONTEXT ----

        $qb   = $this->fmtPlayer($this->qb);
        $bc   = $this->fmtPlayer($this->ballcarrier);
        $wr   = $this->fmtPlayer($this->receiver);
        $tkl  = $this->fmtPlayer($this->tackler);
        $int  = $this->fmtPlayer($this->interceptor);
        $fum  = $this->fmtPlayer($this->fumbleRecoverer);

        switch ($this->type) {

            case 'PASS':
                $txt = "PASS";
                if ($qb) $txt .= " by {$qb}";
                $txt .= " COMPLETE";
                if ($wr)  $txt .= " to {$wr}";
                if ($tkl) $txt .= " TACKLED by {$tkl}";
                $txt .= " ({$ydTxt})";
                break;

            case 'INCOMPLETE':
                $txt = "PASS";
                if ($qb) $txt .= " by {$qb}";
                $txt .= " INCOMPLETE";
                if ($wr) $txt .= " intended for {$wr}";
                break;

            case 'INT':
            case 'INTERCEPTION':
                $txt = "PASS";
                if ($qb) $txt .= " by {$qb}";
                $txt .= " INTERCEPTED";
                if ($int) $txt .= " by {$int}";
                if ($yards !== 0) $txt .= " return {$ydTxt}";
                break;

            case 'RUN':
                $txt = "RUN";
                if ($bc) $txt .= " by {$bc}";
                if ($tkl) $txt .= " TACKLED by {$tkl}";
                $txt .= " ({$ydTxt})";
                break;

            case 'SACK':
                $txt = "SACK";
                if ($qb)  $txt .= " of {$qb}";
                if ($tkl) $txt .= " by {$tkl}";
                $txt .= " ({$ydTxt})";
                break;

            case 'FUMBLE':
                $txt = "FUMBLE";
                if ($bc) $txt .= " by {$bc}";
                if ($fum) $txt .= " recovered by {$fum}";
                break;

            default:
                // Fallback to your original behavior
                $txt = "{$this->type} {$ydTxt}";
                break;
        }

        return "{$txt}{$note}{$flagTxt}";
    }

    // App\Models\Play.php


    public function qbTeamPlayer()
    {
        return $this->belongsTo(TeamPlayer::class, 'qb_team_player_id');
    }

    public function ballcarrierTeamPlayer()
    {
        return $this->belongsTo(TeamPlayer::class, 'ballcarrier_team_player_id');
    }

    public function receiverTeamPlayer()
    {
        return $this->belongsTo(TeamPlayer::class, 'receiver_team_player_id');
    }

    public function tackledByTeamPlayer()
    {
        return $this->belongsTo(TeamPlayer::class, 'tackled_by_team_player_id');
    }

    public function interceptedByTeamPlayer()
    {
        return $this->belongsTo(TeamPlayer::class, 'intercepted_by_team_player_id');
    }

    public function fumbleRecoveredByTeamPlayer()
    {
        return $this->belongsTo(TeamPlayer::class, 'fumble_recovered_by_team_player_id');
    }


}
