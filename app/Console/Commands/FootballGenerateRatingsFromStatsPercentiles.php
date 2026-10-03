<?php

namespace App\Console\Commands;

use App\Models\Player;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class FootballGenerateRatingsFromStatsPercentiles extends Command
{
    protected $signature = 'football:ratings-percentiles
        {--year=2022 : season_year in player_season_stats}
        {--dry-run : do not write to database}
        {--only-missing : only update players whose current ratings are all zero}
        {--limit=0 : limit number of players processed (0 = no limit)}
        {--min-gp=4 : minimum games played to be eligible}
        {--min-pass-att=100 : minimum pass attempts for QB metrics}
        {--min-rush-att=50 : minimum rush attempts for RB/WR/TE rushing metrics}
        {--min-rec=20 : minimum receptions for WR/TE receiving metrics}
        {--min-fg-att=5 : minimum FG attempts for kicker FG% metric}
        {--min-punts=20 : minimum punts for punter avg metric}
    ';

    protected $description = 'Generate player ratings (0..10) from season stats using percentiles (ST Football style)';

    public function handle(): int
    {
        $year = (int) $this->option('year');
        $dryRun = (bool) $this->option('dry-run');
        $onlyMissing = (bool) $this->option('only-missing');
        $limit = (int) $this->option('limit');

        $minGp = (int) $this->option('min-gp');
        $minPassAtt = (int) $this->option('min-pass-att');
        $minRushAtt = (int) $this->option('min-rush-att');
        $minRec = (int) $this->option('min-rec');
        $minFgAtt = (int) $this->option('min-fg-att');
        $minPunts = (int) $this->option('min-punts');

        $this->info("Building percentile ratings for season {$year}" . ($dryRun ? " (dry-run)" : ""));

        $rows = DB::table('player_season_stats')
            ->where('season_year', $year)
            ->get();

        if ($rows->isEmpty()) {
            $this->warn("No player_season_stats rows found for season_year={$year}");
            return self::SUCCESS;
        }

        // Sum rows per player (traded players -> multiple rows)
        $byPlayer = $rows->groupBy('player_id');
        $aggByPlayer = $byPlayer->map(fn($r) => $this->sumRows($r));

        $playerIds = $aggByPlayer->keys()->map(fn($v) => (int) $v)->values();

        /** @var \Illuminate\Support\Collection<int, Player> $players */
        $players = Player::query()
            ->whereIn('id', $playerIds)
            ->get()
            ->keyBy('id');

        // Build metric distributions by position-group
        // Each distribution = array of numeric values, sorted
        $dist = [
            'QB' => [
                'compPct' => [],
                'ypa' => [],
                'intRate' => [],
                'sackRate' => [],
                'rushYpg' => [],
                'rushTdPg' => [],
                'fmbPerTouch' => [],
            ],
            'SKILL' => [
                'ypc' => [],
                'rushYpg' => [],
                'rushTdPg' => [],
                'recPg' => [],
                'ypr' => [],
                'recYpg' => [],
                'returnYpg' => [],
                'fmbPerTouch' => [],
            ],
            'DEF' => [
                'tklPg' => [],
                'sackPg' => [],
                'pdPg' => [],
                'intPg' => [],
                'ffPg' => [],
            ],
            'KICK' => [
                'fgPct' => [],
                'puntAvg' => [],
            ],
        ];

        // 1) Fill distributions
        foreach ($aggByPlayer as $playerId => $s) {
            $player = $players->get((int)$playerId);
            if (!$player) continue;

            $pos = strtoupper(trim((string) ($player->position ?? '')));
            $gp = max(0, (int) ($s->games ?? 0));
            if ($gp < $minGp) continue;

            if ($pos === 'QB') {
                $att = (int) ($s->pass_attempts ?? 0);
                if ($att >= $minPassAtt) {
                    $cmp = (int) ($s->pass_completions ?? 0);
                    $yds = (int) ($s->pass_yards ?? 0);
                    $ints = (int) ($s->interceptions_thrown ?? 0);
                    $sacks = (float) ($s->sacks_taken ?? 0);

                    $compPct = $att > 0 ? ($cmp / $att) : 0.0;
                    $ypa = $att > 0 ? ($yds / $att) : 0.0;
                    $intRate = $att > 0 ? ($ints / $att) : 0.0;

                    // sack rate: sacks / (attempts + sacks) approximates dropbacks
                    $dropbacks = $att + $sacks;
                    $sackRate = $dropbacks > 0 ? ($sacks / $dropbacks) : 0.0;

                    $rushYpg = ((int) ($s->rush_yards ?? 0)) / max(1, $gp);
                    $rushTdPg = ((int) ($s->rush_tds ?? 0)) / max(1, $gp);

                    $touches = (int) ($s->rush_attempts ?? 0) + (int) ($s->receptions ?? 0);
                    $fmb = (int) ($s->fumbles ?? 0);
                    $fmbPerTouch = $touches > 0 ? ($fmb / $touches) : 0.0;

                    $dist['QB']['compPct'][] = $compPct;
                    $dist['QB']['ypa'][] = $ypa;
                    $dist['QB']['intRate'][] = $intRate;
                    $dist['QB']['sackRate'][] = $sackRate;
                    $dist['QB']['rushYpg'][] = $rushYpg;
                    $dist['QB']['rushTdPg'][] = $rushTdPg;
                    $dist['QB']['fmbPerTouch'][] = $fmbPerTouch;
                }
                continue;
            }

            if (in_array($pos, ['RB','WR','TE'], true)) {
                $rushAtt = (int) ($s->rush_attempts ?? 0);
                $rushYds = (int) ($s->rush_yards ?? 0);
                $rushTds = (int) ($s->rush_tds ?? 0);

                $recs = (int) ($s->receptions ?? 0);
                $recYds = (int) ($s->receiving_yards ?? 0);
                $recTds = (int) ($s->receiving_tds ?? 0);

                $krYds = (int) ($s->kick_return_yards ?? 0);
                $prYds = (int) ($s->punt_return_yards ?? 0);

                $rushYpg = $rushYds / max(1, $gp);
                $rushTdPg = $rushTds / max(1, $gp);
                $ypc = $rushAtt > 0 ? ($rushYds / $rushAtt) : 0.0;

                $recPg = $recs / max(1, $gp);
                $recYpg = $recYds / max(1, $gp);
                $ypr = $recs > 0 ? ($recYds / $recs) : 0.0;

                $returnYpg = ($krYds + $prYds) / max(1, $gp);

                $touches = $rushAtt + $recs;
                $fmb = (int) ($s->fumbles ?? 0);
                $fmbPerTouch = $touches > 0 ? ($fmb / $touches) : 0.0;

                // Eligibility filters (keeps distributions sane)
                if ($rushAtt >= $minRushAtt) {
                    $dist['SKILL']['ypc'][] = $ypc;
                    $dist['SKILL']['rushYpg'][] = $rushYpg;
                    $dist['SKILL']['rushTdPg'][] = $rushTdPg;
                }

                if ($recs >= $minRec) {
                    $dist['SKILL']['recPg'][] = $recPg;
                    $dist['SKILL']['ypr'][] = $ypr;
                    $dist['SKILL']['recYpg'][] = $recYpg;
                }

                // returns help speed/return ratings (no strict min here)
                $dist['SKILL']['returnYpg'][] = $returnYpg;
                $dist['SKILL']['fmbPerTouch'][] = $fmbPerTouch;

                continue;
            }

            if (in_array($pos, ['DE','DL','LB','DB','CB','S'], true)) {
                $tkl = (int) ($s->tackles_total ?? 0);
                $sacks = (float) ($s->sacks ?? 0);
                $pd = (int) ($s->passes_defended ?? 0);
                $ints = (int) ($s->def_interceptions ?? 0);
                $ff = (int) ($s->forced_fumbles ?? 0);

                $dist['DEF']['tklPg'][] = $tkl / max(1, $gp);
                $dist['DEF']['sackPg'][] = $sacks / max(1, $gp);
                $dist['DEF']['pdPg'][] = $pd / max(1, $gp);
                $dist['DEF']['intPg'][] = $ints / max(1, $gp);
                $dist['DEF']['ffPg'][] = $ff / max(1, $gp);

                continue;
            }

            if (in_array($pos, ['K','P'], true)) {
                $fgAtt = (int) ($s->fg_attempts ?? 0);
                $fgMade = (int) ($s->fg_made ?? 0);

                $punts = (int) ($s->punts ?? 0);
                $puntYds = (int) ($s->punt_yards ?? 0);

                if ($fgAtt >= $minFgAtt) {
                    $dist['KICK']['fgPct'][] = $fgAtt > 0 ? ($fgMade / $fgAtt) : 0.0;
                }

                if ($punts >= $minPunts) {
                    $dist['KICK']['puntAvg'][] = $punts > 0 ? ($puntYds / $punts) : 0.0;
                }

                continue;
            }
        }

        // Sort all distributions
        foreach ($dist as $group => $metrics) {
            foreach ($metrics as $k => $values) {
                sort($values);
                $dist[$group][$k] = $values;
            }
        }

        // 2) Apply ratings
        $processed = $updated = $skipped = $failed = 0;

        foreach ($aggByPlayer as $playerId => $s) {
            if ($limit > 0 && $processed >= $limit) break;

            $player = $players->get((int)$playerId);
            if (!$player) { $skipped++; continue; }

            if ($onlyMissing && !$this->ratingsAllZero($player)) {
                $skipped++;
                continue;
            }

            $pos = strtoupper(trim((string) ($player->position ?? '')));
            $gp = (int) ($s->games ?? 0);
            if ($gp < $minGp) { $skipped++; continue; }

            try {
                $ratings = $this->buildRatingsForPlayer(
                    $player,
                    $s,
                    $dist,
                    $minPassAtt,
                    $minRushAtt,
                    $minRec,
                    $minFgAtt,
                    $minPunts
                );

                if (empty($ratings)) {
                    $skipped++;
                    continue;
                }

                if (!$dryRun) {
                    $player->fill($ratings);
                    $player->save();
                }

                $updated++;
            } catch (\Throwable $e) {
                $failed++;
                $this->warn("Failed #{$player->id} {$player->firstname} {$player->lastname} ({$pos}): {$e->getMessage()}");
            }

            $processed++;
        }

        $this->info("Done. processed={$processed} updated={$updated} skipped={$skipped} failed={$failed}");

        return self::SUCCESS;
    }

    private function buildRatingsForPlayer(
        Player $player,
        object $s,
        array $dist,
        int $minPassAtt,
        int $minRushAtt,
        int $minRec,
        int $minFgAtt,
        int $minPunts
    ): array {
        $pos = strtoupper(trim((string) ($player->position ?? '')));
        $gp = max(1, (int) ($s->games ?? 0));

        $pTo10 = function (float $p): int {
            $p = max(0.0, min(1.0, $p));
            // curve keeps “middle” ratings more common (feels like ST Football)
            $v = 10 * pow($p, 0.80);
            return max(0, min(10, (int) round($v)));
        };

        $percentile = function (array $sortedValues, float $value): float {
            $n = count($sortedValues);
            if ($n <= 1) return 0.5; // neutral
            // upper-bound index (how many are <= value)
            $lo = 0; $hi = $n;
            while ($lo < $hi) {
                $mid = intdiv($lo + $hi, 2);
                if ($sortedValues[$mid] <= $value) $lo = $mid + 1;
                else $hi = $mid;
            }
            $rank = $lo - 1; // last index <= value
            if ($rank < 0) return 0.0;
            return $rank / ($n - 1);
        };

        // QB
        if ($pos === 'QB') {
            $att = (int) ($s->pass_attempts ?? 0);
            if ($att < $minPassAtt) return [];

            $cmp = (int) ($s->pass_completions ?? 0);
            $yds = (int) ($s->pass_yards ?? 0);
            $td  = (int) ($s->pass_tds ?? 0);
            $int = (int) ($s->interceptions_thrown ?? 0);
            $sacks = (float) ($s->sacks_taken ?? 0);

            $compPct = $att > 0 ? ($cmp / $att) : 0.0;
            $ypa = $att > 0 ? ($yds / $att) : 0.0;
            $intRate = $att > 0 ? ($int / $att) : 0.0;

            $dropbacks = $att + $sacks;
            $sackRate = $dropbacks > 0 ? ($sacks / $dropbacks) : 0.0;

            $rushYpg = ((int) ($s->rush_yards ?? 0)) / $gp;
            $rushTdPg = ((int) ($s->rush_tds ?? 0)) / $gp;

            $touches = (int) ($s->rush_attempts ?? 0) + (int) ($s->receptions ?? 0);
            $fmb = (int) ($s->fumbles ?? 0);
            $fmbPerTouch = $touches > 0 ? ($fmb / $touches) : 0.0;

            $pAccy = $percentile($dist['QB']['compPct'], $compPct);
            $pDeep = $percentile($dist['QB']['ypa'], $ypa);

            // lower is better -> invert percentile
            $pCtrl = 1.0 - $percentile($dist['QB']['intRate'], $intRate);
            $pSack = 1.0 - $percentile($dist['QB']['sackRate'], $sackRate);
            $pRush = $percentile($dist['QB']['rushYpg'], $rushYpg);

            // pass_evade blends sack avoidance and mobility
            $pEvade = (0.70 * $pSack) + (0.30 * $pRush);

            $pPow = $percentile($dist['QB']['rushTdPg'], $rushTdPg);
            $pFmb = 1.0 - $percentile($dist['QB']['fmbPerTouch'], $fmbPerTouch);

            return [
                'pass_accuracy' => $pTo10($pAccy),
                'pass_deep'     => $pTo10($pDeep),
                'pass_control'  => $pTo10($pCtrl),
                'pass_evade'    => $pTo10($pEvade),

                'rush'          => $pTo10($pRush),
                'speed'         => $pTo10($pRush),
                'rush_power'    => $pTo10($pPow),

                'fumble'        => $pTo10($pFmb),
            ];
        }

        // Skill (RB/WR/TE)
        if (in_array($pos, ['RB','WR','TE'], true)) {
            $rushAtt = (int) ($s->rush_attempts ?? 0);
            $rushYds = (int) ($s->rush_yards ?? 0);
            $rushTds = (int) ($s->rush_tds ?? 0);

            $recs = (int) ($s->receptions ?? 0);
            $recYds = (int) ($s->receiving_yards ?? 0);
            $recTds = (int) ($s->receiving_tds ?? 0);

            $krYds = (int) ($s->kick_return_yards ?? 0);
            $prYds = (int) ($s->punt_return_yards ?? 0);

            $rushYpg = $rushYds / $gp;
            $rushTdPg = $rushTds / $gp;
            $ypc = $rushAtt > 0 ? ($rushYds / $rushAtt) : 0.0;

            $recPg = $recs / $gp;
            $recYpg = $recYds / $gp;
            $ypr = $recs > 0 ? ($recYds / $recs) : 0.0;

            $returnYpg = ($krYds + $prYds) / $gp;

            $touches = $rushAtt + $recs;
            $fmb = (int) ($s->fumbles ?? 0);
            $fmbPerTouch = $touches > 0 ? ($fmb / $touches) : 0.0;

            // Percentiles with eligibility safeguards
            $pRushEff = ($rushAtt >= $minRushAtt && count($dist['SKILL']['ypc']) > 0)
                ? $percentile($dist['SKILL']['ypc'], $ypc)
                : 0.5;

            $pRushVol = ($rushAtt >= $minRushAtt && count($dist['SKILL']['rushYpg']) > 0)
                ? $percentile($dist['SKILL']['rushYpg'], $rushYpg)
                : 0.5;

            // combine eff + volume for rush attribute
            $pRush = (0.55 * $pRushEff) + (0.45 * $pRushVol);

            $pPow = ($rushAtt >= $minRushAtt && count($dist['SKILL']['rushTdPg']) > 0)
                ? $percentile($dist['SKILL']['rushTdPg'], $rushTdPg)
                : 0.5;

            $pRec = ($recs >= $minRec && count($dist['SKILL']['recPg']) > 0)
                ? $percentile($dist['SKILL']['recPg'], $recPg)
                : 0.5;

            $pDeep = ($recs >= $minRec && count($dist['SKILL']['ypr']) > 0)
                ? $percentile($dist['SKILL']['ypr'], $ypr)
                : 0.5;

            // speed: best of rush/rec/return per-game
            $pSpeed = max(
                count($dist['SKILL']['rushYpg']) ? $percentile($dist['SKILL']['rushYpg'], $rushYpg) : 0.5,
                count($dist['SKILL']['recYpg'])  ? $percentile($dist['SKILL']['recYpg'], $recYpg) : 0.5,
                count($dist['SKILL']['returnYpg']) ? $percentile($dist['SKILL']['returnYpg'], $returnYpg) : 0.5
            );

            $pReturn = count($dist['SKILL']['returnYpg'])
                ? $percentile($dist['SKILL']['returnYpg'], $returnYpg)
                : 0.5;

            $pFmb = 1.0 - (count($dist['SKILL']['fmbPerTouch'])
                    ? $percentile($dist['SKILL']['fmbPerTouch'], $fmbPerTouch)
                    : 0.5);

            return [
                'rush'          => $pTo10($pRush),
                'rush_power'    => $pTo10($pPow),

                'receive'       => $pTo10($pRec),
                'receive_deep'  => $pTo10($pDeep),

                'speed'         => $pTo10($pSpeed),
                'fumble'        => $pTo10($pFmb),

                'return_yards'  => $pTo10($pReturn),
                'return_speed'  => $pTo10($pReturn),
                'return_fumble' => $pTo10($pFmb),
            ];
        }

        // Defense
        if (in_array($pos, ['DE','DL','LB','DB','CB','S'], true)) {
            $tklPg = ((int) ($s->tackles_total ?? 0)) / $gp;
            $sackPg = ((float) ($s->sacks ?? 0)) / $gp;
            $pdPg = ((int) ($s->passes_defended ?? 0)) / $gp;
            $intPg = ((int) ($s->def_interceptions ?? 0)) / $gp;
            $ffPg = ((int) ($s->forced_fumbles ?? 0)) / $gp;

            $pT = count($dist['DEF']['tklPg']) ? $percentile($dist['DEF']['tklPg'], $tklPg) : 0.5;
            $pS = count($dist['DEF']['sackPg']) ? $percentile($dist['DEF']['sackPg'], $sackPg) : 0.5;
            $pC = count($dist['DEF']['pdPg']) ? $percentile($dist['DEF']['pdPg'], $pdPg) : 0.5;
            $pI = count($dist['DEF']['intPg']) ? $percentile($dist['DEF']['intPg'], $intPg) : 0.5;
            $pF = count($dist['DEF']['ffPg']) ? $percentile($dist['DEF']['ffPg'], $ffPg) : 0.5;

            return [
                'tackle'       => $pTo10($pT),
                'sack'         => $pTo10($pS),
                'cover'        => $pTo10($pC),
                'interception' => $pTo10($pI),
                'strip'        => $pTo10($pF),
            ];
        }

        // K/P
        if (in_array($pos, ['K','P'], true)) {
            $out = [];

            $fgAtt = (int) ($s->fg_attempts ?? 0);
            $fgMade = (int) ($s->fg_made ?? 0);
            if ($fgAtt >= $minFgAtt && count($dist['KICK']['fgPct'])) {
                $fgPct = $fgMade / max(1, $fgAtt);
                $p = $percentile($dist['KICK']['fgPct'], $fgPct);

                // distance tiers approximate (without distance splits)
                $out['kick30'] = $pTo10($p);
                $out['kick39'] = $pTo10($p * 0.97);
                $out['kick49'] = $pTo10($p * 0.92);
                $out['kick50'] = $pTo10($p * 0.85);
            }

            $punts = (int) ($s->punts ?? 0);
            $puntYds = (int) ($s->punt_yards ?? 0);
            if ($punts >= $minPunts && count($dist['KICK']['puntAvg'])) {
                $avg = $puntYds / max(1, $punts);
                $p = $percentile($dist['KICK']['puntAvg'], $avg);
                $out['punt_distance'] = $pTo10($p);
            }

            return $out;
        }

        return [];
    }

    /**
     * Sum per-team rows into one combined stat object.
     */
    private function sumRows(Collection $rows): object
    {
        $sumFields = [
            'games','games_started',

            'pass_completions','pass_attempts','pass_yards','pass_tds',
            'interceptions_thrown','sacks_taken','sack_yards_lost',

            'rush_attempts','rush_yards','rush_tds',
            'receptions','targets','receiving_yards','receiving_tds',

            'tackles_total','tackles_solo','tackles_assist',
            'sacks','tfl','qb_hits','def_interceptions','passes_defended',
            'forced_fumbles','fumble_recoveries','def_tds',

            'fg_made','fg_attempts','xp_made','xp_attempts',
            'punts','punt_yards','punts_inside_20','punt_touchbacks','punt_blocked',

            'kick_returns','kick_return_yards','kick_return_tds',
            'punt_returns','punt_return_yards','punt_return_tds',
            'fumbles','fumbles_lost',
        ];

        $agg = [];
        foreach ($sumFields as $f) $agg[$f] = 0;

        foreach ($rows as $r) {
            foreach ($sumFields as $f) {
                $val = $r->{$f} ?? 0;
                if (in_array($f, ['sacks','sacks_taken'], true)) $agg[$f] += (float) $val;
                else $agg[$f] += (int) $val;
            }
        }

        $agg['player_id'] = (int) ($rows->first()->player_id ?? 0);
        $agg['season_year'] = (int) ($rows->first()->season_year ?? 0);

        return (object) $agg;
    }

    private function ratingsAllZero(Player $p): bool
    {
        $fields = [
            'pass_evade','pass_accuracy','pass_deep','pass_control',
            'rush','rush_power','receive','receive_deep',
            'fumble','speed',
            'tackle','sack','cover','interception','strip',
            'kick30','kick39','kick49','kick50',
            'punt_distance','punt_pooch_yard','punt_pooch','punt_block',
            'return_yards','return_speed','return_fumble',
        ];

        foreach ($fields as $f) {
            if ((int) ($p->{$f} ?? 0) !== 0) return false;
        }

        return true;
    }
}
