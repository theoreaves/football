<?php

namespace App\Http\Controllers;

use App\Models\Player;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TeamPlayerRatingsController extends Controller
{
    public function fromSeason(Request $request, Team $team, Player $player, int $seasonYear): RedirectResponse
    {
        $year = (string) $request->query('year', $seasonYear); // keep your editor year param if needed

        $stat = DB::table('player_season_stats')
            ->where('player_id', $player->id)
            ->where('season_year', $seasonYear)
            ->first();

        if (!$stat) {
            return back()->with('status', "No stats found for {$player->firstname} {$player->lastname} in {$seasonYear}.");
        }

        // Build ratings from the season totals
        $ratings = $this->ratingsFromSeasonStat($stat, strtoupper(trim($player->position ?? '')));

        // Persist on players table
        // Make sure these columns exist and are fillable if using $player->fill()
        $player->fill($ratings);
        $player->save();

        return redirect()
            ->route('teams.editor.teams.players.edit', [$team, $player, 'year' => $year])
            ->with('status', "Generated ratings from {$seasonYear} season stats.");
    }

    /**
     * Convert season totals -> ratings (0..10, tweak as you like)
     * $stat is stdClass from DB::table()->first()
     */
    private function ratingsFromSeasonStat(object $s, string $pos): array
    {
        // Helpers
        $clamp10 = fn($v) => max(0, min(10, (int) round($v)));

        $perGame = function ($total) use ($s) {
            $gp = max(1, (int) ($s->games ?? 0));
            return $total / $gp;
        };

        // --- QB ---
        if ($pos === 'QB') {
            $att = (int) ($s->pass_attempts ?? 0);
            $cmp = (int) ($s->pass_completions ?? 0);
            $yds = (int) ($s->pass_yards ?? 0);
            $td  = (int) ($s->pass_tds ?? 0);
            $int = (int) ($s->interceptions_thrown ?? 0);

            $compPct = $att > 0 ? ($cmp / $att) : 0.0;
            $ydsPerAtt = $att > 0 ? ($yds / $att) : 0.0;
            $tdRate = $att > 0 ? ($td / $att) : 0.0;
            $intRate = $att > 0 ? ($int / $att) : 0.0;

            // Scale heuristics (tweak to taste)
            $pass_accuracy = $clamp10( ($compPct - 0.50) / 0.02 );      // 50%->0, 70%->10
            $pass_deep     = $clamp10( ($ydsPerAtt - 5.0) / 0.35 );     // 5.0->0, 8.5->10
            $pass_control  = $clamp10( (0.04 - $intRate) / 0.004 );     // 4% INT ->0, 0%->10
            $pass_evade    = $clamp10( (int) ($s->sacks_taken ?? 0) > 0
                ? 10 - (($s->sacks_taken / max(1, $att)) / 0.01)  // ~1% sacks =10
                : 10 );

            // QB rushing influences rush/speed
            $rushYpg = $perGame((int) ($s->rush_yards ?? 0));
            $rushTds = (int) ($s->rush_tds ?? 0);

            $rush = $clamp10( $rushYpg / 7.0 );                         // 70 ypg => 10
            $speed = $clamp10( $rushYpg / 8.0 );                        // similar
            $rush_power = $clamp10( $rushTds / 2.0 );                   // 20 TD => 10 (rare)

            // fumble rating: fewer is better. If you store fumbles on stats table, use it.
            $fumbles = (int) ($s->fumbles ?? 0);
            $fumble = $clamp10( 10 - ($fumbles / 1.5) );                // 0 fmb=10, 15 fmb~0

            return compact(
                'pass_evade','pass_accuracy','pass_deep','pass_control',
                'rush','rush_power','speed','fumble'
            );
        }

        // --- RB/WR/TE (skill) ---
        if (in_array($pos, ['RB','WR','TE'], true)) {
            $rushYpg = $perGame((int) ($s->rush_yards ?? 0));
            $rushTds = $perGame((int) ($s->rush_tds ?? 0));
            $recYpg  = $perGame((int) ($s->receiving_yards ?? 0));
            $recTds  = $perGame((int) ($s->receiving_tds ?? 0));
            $recs    = $perGame((int) ($s->receptions ?? 0));

            $krYpg   = $perGame((int) ($s->kick_return_yards ?? 0));
            $prYpg   = $perGame((int) ($s->punt_return_yards ?? 0));

            $rush = $clamp10($rushYpg / 10.0);                          // 100 ypg => 10
            $rush_power = $clamp10(($rushTds * 16) / 2.0);              // per-game -> season-ish scale
            $receive = $clamp10($recs / 0.5);                           // 5 rec/g =>10
            $receive_deep = $clamp10($recYpg / 12.0);                   // 120 ypg =>10
            $speed = $clamp10( max($rushYpg, $recYpg, $krYpg, $prYpg) / 12.0 );

            $fumbles = (int) ($s->fumbles ?? 0);
            $return_fumble = $clamp10(10 - ($fumbles / 1.5));

            // Return-yards influences return_yards and return_speed
            $return_yards = $clamp10( max($krYpg, $prYpg) / 8.0 );
            $return_speed = $clamp10( max($krYpg, $prYpg) / 10.0 );

            return compact(
                'rush','rush_power','receive','receive_deep','speed',
                'return_yards','return_speed','return_fumble'
            );
        }

        // --- Defense (DE/DL/LB/DB/CB/S) ---
        if (in_array($pos, ['DE','DL','LB','DB','CB','S'], true)) {
            $tkl = $perGame((int) ($s->tackles_total ?? 0));
            $sack = $perGame((float) ($s->sacks ?? 0));
            $int = $perGame((int) ($s->def_interceptions ?? 0));
            $ff  = $perGame((int) ($s->forced_fumbles ?? 0));
            $pd  = $perGame((int) ($s->passes_defended ?? 0));

            $tackle = $clamp10($tkl / 0.9);                              // ~9 tackles/g =>10
            $sackR  = $clamp10(($sack * 16) / 1.2);                      // ~1.2 sacks/game pace =>10
            $cover  = $clamp10(($pd * 16) / 1.0);                        // ~16 PD season =>10
            $interception = $clamp10(($int * 16) / 0.35);                // ~6 INT season =>10
            $strip = $clamp10(($ff * 16) / 0.35);                        // ~6 FF season =>10

            return [
                'tackle' => $tackle,
                'sack' => $sackR,
                'cover' => $cover,
                'interception' => $interception,
                'strip' => $strip,
            ];
        }

        // --- K/P ---
        if (in_array($pos, ['K','P'], true)) {
            // If you have kick distance splits, use those. Otherwise keep simple:
            $fgAtt = (int) ($s->fg_attempts ?? 0);
            $fgMade = (int) ($s->fg_made ?? 0);
            $xpAtt = (int) ($s->xp_attempts ?? 0);
            $xpMade = (int) ($s->xp_made ?? 0);

            $fgPct = $fgAtt > 0 ? ($fgMade / $fgAtt) : 0.0;
            $xpPct = $xpAtt > 0 ? ($xpMade / $xpAtt) : 0.0;

            $kick30 = $clamp10(($fgPct - 0.70) / 0.03);                  // 70%->0, 100%->10
            $kick39 = $clamp10(($fgPct - 0.65) / 0.035);
            $kick49 = $clamp10(($fgPct - 0.55) / 0.04);
            $kick50 = $clamp10(($fgPct - 0.40) / 0.05);

            // Punting
            $punts = (int) ($s->punts ?? 0);
            $puntYds = (int) ($s->punt_yards ?? 0);
            $avgPunt = $punts > 0 ? ($puntYds / $punts) : 0.0;

            $punt_distance = $clamp10(($avgPunt - 35) / 2.0);            // 35->0, 55->10

            return compact('kick30','kick39','kick49','kick50','punt_distance');
        }

        // Unknown position: no changes
        return [];
    }
}
