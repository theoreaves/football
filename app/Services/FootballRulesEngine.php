<?php

namespace App\Services;

use App\Models\Game;
use App\Models\Play;

class FootballRulesEngine
{
    private int $kickTouchbackSpot = 25; // offense OWN 25
    private int $puntTouchbackSpot = 20; // offense OWN 20 (set to 25 if your tabletop uses 25)

    public function applyKickoffSpot(Game $game, string $side, int $yardline): void
    {
        $yardline = max(1, min(50, $yardline));
        $side = $side === 'OPP' ? 'OPP' : 'OWN';

        $game->pos_side = $side;
        $game->pos_yardline = $yardline;
        $game->down = 1;
        $game->to_go = 10;

        $this->normalizeGoalToGo($game);
        $game->save();
    }

    public function applyPlay(Game $game, string $type, int $yards): array
    {
        if ($game->phase === 'TRY') {
            // During TRY phase, only allow recordTry() to proceed
            return [
                'before' => $this->snapshot($game),
                'after' => $this->snapshot($game),
                'first_down' => false,
                'turnover' => false,
                'touchdown' => false,
            ];
        }

        $before = $this->snapshot($game);

        $absBefore = $this->toAbs($game->pos_side, (int)$game->pos_yardline);
        $absAfter = $absBefore + $yards;

        // Clamp for safety; TD logic handled below
        $absAfterClamped = max(0, min(100, $absAfter));

        $touchdown = $absAfter >= 100;
        $turnover = false;
        $firstDown = false;

        // Update position
        [$sideAfter, $ylAfter] = $this->fromAbs($absAfterClamped);
        $game->pos_side = $sideAfter;
        $game->pos_yardline = $ylAfter;

        $event = null;
        $pt = strtoupper($type);

        if ($pt === 'RUN') $event = 'RUN';
        if ($pt === 'PASS') $event = 'PASS_COMPLETE';
        if ($pt === 'INCOMPLETE') $event = 'INCOMPLETE';

        if ($event) {
//            $this->applyClockRunoff($game, $this->clockRunoffForEvent($event));
        }
        $periodEvent = null;

        switch ($type) {
            case 'RUN':
            case 'PASS': // completed pass
                $periodEvent = $this->applyClockRunoff($game, $this->randSeconds(20, 40));
                break;

            case 'INCOMPLETE':
                $periodEvent = $this->applyClockRunoff($game, $this->randSeconds(10, 20));
                break;

            case 'SACK':
                $periodEvent = $this->applyClockRunoff($game, $this->randSeconds(20, 30));
                break;

            case 'PENALTY':
                $periodEvent = $this->applyClockRunoff($game, $this->randSeconds(5, 15));
                break;
        }



        if ($type === 'INCOMPLETE') {
            // No yards gained, down advances
            $game->down = (int) $game->down + 1;

            // Turnover on downs handled elsewhere if you already do that
            $this->normalizeGoalToGo($game);

            $after = $this->snapshot($game);
            $game->play_seq = (int) $game->play_seq + 1;
            $game->save();

            return [
                'before' => $before,
                'after' => $after,
                'first_down' => false,
                'turnover' => false,
                'touchdown' => false,
                'period_event' => $periodEvent,
            ];
        }

        if ($touchdown) {
            // 1) award the 6 points
            $this->awardTouchdown($game);

            // 2) enter TRY phase for the scoring team
            $game->phase = 'TRY';
            $game->try_team = $game->possession; // scoring team

            // 3) Do NOT change possession or spot yet.
            // The try handler will do that after XP/2PT is recorded.

            $after = $this->snapshot($game);

            // bump play sequence, save, and return immediately
            $game->play_seq = (int) $game->play_seq + 1;
            $game->save();

            return [
                'before' => $before,
                'after' => $after,
                'first_down' => false,
                'turnover' => false,
                'touchdown' => true,
                'period_event' => $periodEvent,
            ];

//
//        if ($touchdown) {
//            $this->awardTouchdown($game);
//            $game->down = 1;
//            $game->to_go = 10;
//            // after score, set a “reasonable” kickoff spot for next drive (v1 default)
//            $game->pos_side = 'OWN';
//            $game->pos_yardline = 25;
        } else {
// Determine down/to-go (signed yards)
            $beforeDown = (int) $before['down'];
            $beforeToGo = (int) $before['to_go'];

            if ($yards >= $beforeToGo) {
                // First down achieved
                $firstDown = true;
                $turnover = false;

                $game->down = 1;
                $game->to_go = 10;
                $this->normalizeGoalToGo($game);
            } else {
                // Not a first down
                $firstDown = false;

                // If it was 4th down and you didn't convert, turnover on downs
                if ($beforeDown === 4) {
                    $turnover = true;

                    // Flip possession at the current physical spot
                    $this->flipPossession($game);

                    // Convert current spot to new offense-relative (since flipPossession likely flips pos_side)
                    $absNow = $this->toAbs($game->pos_side, (int) $game->pos_yardline);
                    $absForNewOffense = 100 - $absNow;
                    [$newSide, $newYL] = $this->fromAbs(max(0, min(100, $absForNewOffense)));
                    $game->pos_side = $newSide;
                    $game->pos_yardline = $newYL;

                    $game->down = 1;
                    $game->to_go = 10;
                    $this->normalizeGoalToGo($game);
                } else {
                    // Advance down; update to_go using signed yards
                    $game->down = min(4, $beforeDown + 1);

                    // Key fix: subtract signed yards so losses increase to-go
                    $game->to_go = max(1, $beforeToGo - (int) $yards);

                    $this->normalizeGoalToGo($game);
                }
            }

        }

        $after = $this->snapshot($game);

        // Bump sequence
        $game->play_seq = (int)$game->play_seq + 1;
        $game->save();

        return [
            'before' => $before,
            'after' => $after,
            'first_down' => $firstDown,
            'turnover' => $turnover,
            'touchdown' => $touchdown,
            'period_event' => $periodEvent,
        ];
    }

    private function normalizeGoalToGo(Game $game): void
    {
        $abs = $this->toAbs($game->pos_side, (int)$game->pos_yardline);
        $yardsToGoal = max(1, 100 - $abs);
        if ($game->down == 1){
            $this->startSeries($game);
        }

        if ((int)$game->to_go > $yardsToGoal) {
            $game->to_go = $yardsToGoal;
        }
    }

    private function awardTouchdown(Game $game): void
    {
        // Award to the team currently with possession
        if ($game->possession === 'HOME') {
            $game->home_score = (int)$game->home_score + 6;
        } else {
            $game->away_score = (int)$game->away_score + 6;
        }

        $this->addPointsToQuarter($game, $game->possession, 6);


        // After scoring, possession flips for kickoff (v1)
//        $this->flipPossession($game);
    }

    private function flipPossession(Game $game): void
    {
        $game->possession = $game->possession === 'HOME' ? 'AWAY' : 'HOME';
    }

    private function toAbs(string $side, int $yardline): int
    {
        $yardline = max(1, min(50, $yardline));
        return $side === 'OWN' ? $yardline : (100 - $yardline);
    }

    private function fromAbs(int $abs): array
    {
        $abs = max(0, min(100, $abs));

        if ($abs <= 50) {
            return ['OWN', max(1, (int)$abs)];
        }

        return ['OPP', max(1, (int)(100 - $abs))];
    }

    private function snapshot(Game $game): array
    {
        return [
            'possession' => $game->possession,
            'side' => $game->pos_side,
            'yardline' => (int)$game->pos_yardline,
            'down' => (int)$game->down,
            'to_go' => (int)$game->to_go,
            'home_score' => (int)$game->home_score,
            'away_score' => (int)$game->away_score,
        ];
    }

    public function recordTry(Game $game, string $tryType, bool $good): array
    {
        // tryType: 'XP' or '2PT'
        if ($game->phase !== 'TRY' || !$game->try_team) {
            // keep it safe/generic: allow anyway, but don't crash
            $game->phase = 'TRY';
            $game->try_team = $game->possession;
        }

        $scoringTeam = $game->try_team ?? $game->possession;

        $points = 0;
        if ($good) {
            $points = $tryType === '2PT' ? 2 : 1;
            $this->awardPoints($game, $game->try_team, $points);
        }

        // After try, receiving team gets ball (kickoff). For v1 we auto-set to OWN 25.
//        $receiving = $game->try_team === 'HOME' ? 'AWAY' : 'HOME';

//        $game->possession = $receiving;
//        $game->pos_side = 'OWN';
//        $game->pos_yardline = 25;
//        $game->down = 1;
//        $game->to_go = 10;
//
//        $game->phase = 'NORMAL';
//        $game->try_team = null;

        // After try, scoring team kicks off
        $game->phase = 'NORMAL';
        $game->try_team = null;

// IMPORTANT: kickoff replaces manual possession/spot logic
        $this->startKickoff($game, $scoringTeam);


        $game->play_seq = (int)$game->play_seq + 1;
        $game->save();

        return [
            'points' => $points,
            'possession_after' => $game->possession,
        ];
    }

    public function recordFieldGoal(Game $game, bool $good): array
    {
        $points = 0;
        if ($good) {
            $points = 3;
            $this->awardPoints($game, $game->possession, 3);


            // After a FG, other team receives (kickoff) – v1 auto spot
//            $receiving = $game->possession === 'HOME' ? 'AWAY' : 'HOME';

//            $game->possession = $receiving;
//            $game->pos_side = 'OWN';
//            $game->pos_yardline = 25;
//            $game->down = 1;
//            $game->to_go = 10;

            $this->startKickoff($game, $game->possession);


        } else {
            // Generic: missed FG -> turnover at spot (you can refine later)
            $this->flipPossessionAtSameSpot($game);
            $game->down = 1;
            $game->to_go = 10;
            $this->normalizeGoalToGo($game);
        }

        $game->play_seq = (int)$game->play_seq + 1;
        $game->save();

        return ['points' => $points, 'good' => $good];
    }

    private function awardPoints(Game $game, string $team, int $points): void
    {
        if ($team === 'HOME') $game->home_score = (int)$game->home_score + $points;
        else $game->away_score = (int)$game->away_score + $points;

        $this->addPointsToQuarter($game, $team, $points);

    }

    private function flipPossessionAtSameSpot(Game $game): void
    {
        // Convert spot relative to new offense:
        $abs = $this->toAbs($game->pos_side, (int)$game->pos_yardline);
        $game->possession = $game->possession === 'HOME' ? 'AWAY' : 'HOME';

        $absForNewOffense = 100 - $abs;
        [$newSide, $newYL] = $this->fromAbs(max(0, min(100, $absForNewOffense)));
        $game->pos_side = $newSide;
        $game->pos_yardline = $newYL;
    }

    public function startKickoff(Game $game, string $kickingTeam): void
    {
        $kickingTeam = $kickingTeam === 'AWAY' ? 'AWAY' : 'HOME';

        $game->phase = 'KICKOFF';
        $game->kick_team = $kickingTeam;

        // Put ball at kicking team's OWN 35 (for display while kicking)
        $game->possession = $kickingTeam;
        $game->pos_side = 'OWN';
        $game->pos_yardline = 35;

        // Down/distance irrelevant during kickoff, but keep sane values
        $game->down = 1;
        $game->to_go = 10;

        $game->save();
    }

    public function recordKickoff(Game $game, int $kickYards): array
    {
        if ($game->phase !== 'KICKOFF' || ! $game->kick_team) {
            // If someone calls it out of order, force it
            $this->startKickoff($game, $game->possession);
        }

        $kickYards = max(0, min(99, $kickYards));

        $kickingTeam = $game->kick_team;

        // Ball starts at kicking team's OWN 35
        $absLandingFromKicking = 35 + $kickYards;
        $absLandingFromKicking = max(0, min(100, $absLandingFromKicking));

        // Receiving team becomes offense for the return
        $receivingTeam = $kickingTeam === 'HOME' ? 'AWAY' : 'HOME';
        $game->possession = $receivingTeam;

        // Convert physical landing spot to receiving team's offense-relative abs
        $absFromReceiving = 100 - $absLandingFromKicking;
        $absFromReceiving = max(0, min(100, $absFromReceiving));

        [$side, $yl] = $this->fromAbs($absFromReceiving);
        $game->pos_side = $side;
        $game->pos_yardline = $yl;

        // Stay in KICKOFF phase until return is recorded
        $game->save();

        return [
            'kick_yards' => $kickYards,
            'landing_abs_from_kicking' => $absLandingFromKicking,
            'spot_after_kick' => [$game->pos_side, (int)$game->pos_yardline],
            'receiving_team' => $receivingTeam,
        ];
    }

    public function recordKickReturn(\App\Models\Game $game, int $returnYards, ?string $reason = null): array
    {
        if ($game->phase !== 'KICKOFF') {
            return ['return_yards' => 0, 'touchdown' => false];
        }

        $reason = $reason ? strtoupper($reason) : null;

        // Touchback overrides yards and spot
        if ($reason === 'TOUCHBACK') {
            $absAfter = $this->kickTouchbackSpot;
            [$side, $yl] = $this->fromAbs($absAfter);

            $game->pos_side = $side;
            $game->pos_yardline = $yl;

            $game->phase = 'NORMAL';
            $game->kick_team = null;
            $game->down = 1;
            $game->to_go = 10;
            $this->normalizeGoalToGo($game);
            $game->save();

            return [
                'return_yards' => 0,
                'reason' => 'TOUCHBACK',
                'touchdown' => false,
                'points' => 0,
            ];
        }

        $this->applyClockRunoff($game, $this->clockRunoffForEvent('KICKOFF'));


        $returnYards = max(-20, min(99, $returnYards));

        $abs = $this->toAbs($game->pos_side, (int) $game->pos_yardline);
        $raw = $abs + $returnYards;

        // TD detection
        if ($raw >= 100) {
            $scoringTeam = $game->possession; // return team is current possession
            $this->awardTouchdownAndEnterTry($game, $scoringTeam);

            // kickoff is over
            $game->kick_team = null;

            return [
                'return_yards' => $returnYards,
                'reason' => $reason, // FAIR_CATCH / NO_RETURN (optional)
                'touchdown' => true,
                'points' => 6,
            ];
        }

        $absAfter = max(0, min(100, $raw));
        [$side, $yl] = $this->fromAbs($absAfter);

        $game->pos_side = $side;
        $game->pos_yardline = $yl;

        // kickoff complete -> normal offense
        $game->phase = 'NORMAL';
        $game->kick_team = null;
        $game->down = 1;
        $game->to_go = 10;
        $this->normalizeGoalToGo($game);

        $game->save();

        return [
            'return_yards' => $returnYards,
            'reason' => $reason,
            'touchdown' => false,
            'points' => 0,
        ];
    }


    public function startPunt(Game $game, ?string $puntingTeam = null): void
    {
        $puntingTeam = strtoupper($puntingTeam ?? $game->possession ?? 'HOME');
        $puntingTeam = $puntingTeam === 'AWAY' ? 'AWAY' : 'HOME';

        $game->phase = 'PUNT';
        $game->punt_team = $puntingTeam;

        // possession stays as punting team until punt is recorded
        $game->possession = $puntingTeam;

        $game->save();
    }

    public function recordPunt(Game $game, int $puntYards): array
    {
        $puntYards = max(0, min(99, $puntYards));

        $puntingTeam = $game->punt_team ?: $game->possession;

        // Current spot is offense-relative for punting team
        $absFromPunting = $this->toAbs($game->pos_side, (int) $game->pos_yardline);

        // Punt goes downfield (away from punting goal line)
        $absLandingFromPunting = max(0, min(100, $absFromPunting + $puntYards));

        // Receiving team becomes offense for the return
        $receivingTeam = $puntingTeam === 'HOME' ? 'AWAY' : 'HOME';
        $game->possession = $receivingTeam;

        // Convert physical landing spot to receiving team offense-relative abs
        $absFromReceiving = 100 - $absLandingFromPunting;
        $absFromReceiving = max(0, min(100, $absFromReceiving));

        [$side, $yl] = $this->fromAbs($absFromReceiving);
        $game->pos_side = $side;
        $game->pos_yardline = $yl;

        // Still in PUNT phase until return is recorded
        $game->save();

        return [
            'punting_team' => $puntingTeam,
            'receiving_team' => $receivingTeam,
            'punt_yards' => $puntYards,
        ];
    }

    public function recordPuntReturn(\App\Models\Game $game, int $returnYards, ?string $reason = null): array
    {
        if ($game->phase !== 'PUNT') {
            return ['return_yards' => 0, 'touchdown' => false];
        }

        $reason = $reason ? strtoupper($reason) : null;

        $this->applyClockRunoff($game, $this->clockRunoffForEvent('PUNT'));


        // Touchback overrides yards and spot
        if ($reason === 'TOUCHBACK') {
            $absAfter = $this->puntTouchbackSpot;
            [$side, $yl] = $this->fromAbs($absAfter);

            $game->pos_side = $side;
            $game->pos_yardline = $yl;

            $game->phase = 'NORMAL';
            $game->punt_team = null;

            $game->down = 1;
            $game->to_go = 10;
            $this->normalizeGoalToGo($game);

            $game->save();

            return [
                'return_yards' => 0,
                'reason' => 'TOUCHBACK',
                'touchdown' => false,
                'points' => 0,
            ];
        }

        $returnYards = max(-20, min(99, $returnYards));

        $abs = $this->toAbs($game->pos_side, (int) $game->pos_yardline);
        $raw = $abs + $returnYards;

        // TD detection
        if ($raw >= 100) {
            $scoringTeam = $game->possession; // return team is current possession
            $this->awardTouchdownAndEnterTry($game, $scoringTeam);

            // punt is over
            $game->punt_team = null;

            return [
                'return_yards' => $returnYards,
                'reason' => $reason, // FAIR_CATCH / DOWNED
                'touchdown' => true,
                'points' => 6,
            ];
        }

        $absAfter = max(0, min(100, $raw));
        [$side, $yl] = $this->fromAbs($absAfter);

        $game->pos_side = $side;
        $game->pos_yardline = $yl;

        // punt complete -> normal offense
        $game->phase = 'NORMAL';
        $game->punt_team = null;

        $game->down = 1;
        $game->to_go = 10;
        $this->normalizeGoalToGo($game);

        $game->save();

        return [
            'return_yards' => $returnYards,
            'reason' => $reason,
            'touchdown' => false,
            'points' => 0,
        ];
    }


    private function awardTouchdownAndEnterTry(\App\Models\Game $game, string $scoringTeam): void
    {
        $scoringTeam = $scoringTeam === 'AWAY' ? 'AWAY' : 'HOME';

        if ($scoringTeam === 'HOME') {
            $game->home_score = (int) $game->home_score + 6;
        } else {
            $game->away_score = (int) $game->away_score + 6;
        }
        $this->addPointsToQuarter($game, $scoringTeam, 6);


        // Do NOT flip possession here
        $game->phase = 'TRY';
        $game->try_team = $scoringTeam;

        // For display purposes, set ball at OPP 3 (generic PAT spot-ish)
        // (Optional; keep if you want)
        $game->possession = $scoringTeam;
        $game->pos_side = 'OPP';
        $game->pos_yardline = 3;

        $game->down = 1;
        $game->to_go = 10;

        $game->save();
    }

    public function startInterception(Game $game): void
    {
        // Defense is opposite current possession
        $defense = $game->possession === 'HOME' ? 'AWAY' : 'HOME';

        $game->phase = 'INT';
        $game->turnover_type = 'INT';
        $game->turnover_by = $defense; // new possession team

        $game->save();
    }

    public function recordInterceptionReturn(Game $game, int $returnYards): array
    {
        if ($game->phase !== 'INT' || $game->turnover_type !== 'INT' || ! $game->turnover_by) {
            return ['return_yards' => 0];
        }

        $returnYards = max(-20, min(99, $returnYards));

        // Flip possession at same physical spot FIRST
        $this->flipPossessionAtSameSpot($game);

        // Ensure possession is the intercepting team (defense)
        $game->possession = $game->turnover_by;

        // Apply return yards from the intercepting team's perspective
        $abs = $this->toAbs($game->pos_side, (int) $game->pos_yardline);
        $raw = $abs + $returnYards;

        // Pick-six detection
        if ($raw >= 100) {
            $this->awardTouchdownAndEnterTry($game, $game->possession);

            // clear turnover markers
            $game->turnover_type = null;
            $game->turnover_by = null;

            return [
                'return_yards' => $returnYards,
                'touchdown' => true,
                'points' => 6,
            ];
        }

        $absAfter = max(0, min(100, $raw));
        [$side, $yl] = $this->fromAbs($absAfter);

        $game->pos_side = $side;
        $game->pos_yardline = $yl;

        // End INT phase
        $game->phase = 'NORMAL';
        $game->turnover_type = null;
        $game->turnover_by = null;

        $game->down = 1;
        $game->to_go = 10;
        $this->normalizeGoalToGo($game);

        $yards = max(0, (int)$returnYards); // only count positive return yards for time
        $seconds = 10 + $yards;

        $periodEvent = $this->applyClockRunoff($game, $seconds);

        $game->save();


        return [
            'return_yards' => $returnYards,
            'touchdown' => false,
            'points' => 0,
            'period_event' => $periodEvent,
        ];
    }



    public function startFieldGoal(Game $game): void
    {
        // We don’t know recovery yet; enter fumble phase
        $game->phase = 'FIELDGOAL';
        $game->save();
    }
    public function startFumble(Game $game): void
    {
        // We don’t know recovery yet; enter fumble phase
        $game->phase = 'FUMBLE';
        $game->turnover_type = 'FUM';
        $game->turnover_by = null; // will be set when recovered
        $game->save();
    }

    public function resolveFumble(Game $game, string $recoveredBy, int $returnYards): array
    {
        if ($game->phase !== 'FUMBLE' || $game->turnover_type !== 'FUM') {
            return ['turnover' => false, 'return_yards' => 0];
        }

        $beforeDown = (int) $game->down;
        $beforeToGo = (int) $game->to_go;


        $recoveredBy = strtoupper($recoveredBy);
        $recoveredBy = in_array($recoveredBy, ['OFFENSE', 'DEFENSE'], true) ? $recoveredBy : 'OFFENSE';

        $offense = $game->possession;
        $defense = $offense === 'HOME' ? 'AWAY' : 'HOME';

// If offense recovers: keep possession, but yards still apply (signed)
        if ($recoveredBy === 'OFFENSE') {

            $returnYards = max(-20, min(99, (int)$returnYards));

            // Capture "before" down/to-go like applyPlay()
            $beforeDown = (int) $game->down;
            $beforeToGo = (int) $game->to_go;

            // Move the ball in offense direction (offense-relative coordinates)
            $absBefore = $this->toAbs($game->pos_side, (int)$game->pos_yardline);
            $raw = $absBefore + $returnYards;

            // Offense fumble advance TD (optional, but consistent)
            if ($raw >= 100) {
                $this->awardTouchdownAndEnterTry($game, $offense);

                $game->phase = 'TRY';
                $game->try_team = $offense;

                $game->turnover_type = null;
                $game->turnover_by = null;

                $yards = max(0, $returnYards);
                $seconds = 10 + $yards;
                $periodEvent = $this->applyClockRunoff($game, $seconds);

                $game->save();

                return [
                    'turnover' => false,
                    'recovered_by' => 'OFFENSE',
                    'return_yards' => $returnYards,
                    'touchdown' => true,
                    'points' => 6,
                    'period_event' => $periodEvent,
                ];
            }

            // Clamp and write spot
            $absAfter = max(0, min(100, $raw));
            [$side, $yl] = $this->fromAbs($absAfter);
            $game->pos_side = $side;
            $game->pos_yardline = $yl;

            $game->phase = 'NORMAL';
            $game->turnover_type = null;
            $game->turnover_by = null;

            $firstDown = false;
            $turnover = false;

            // === DOWN / TO-GO LOGIC (mirrors applyPlay) ===
            if ($returnYards >= $beforeToGo) {
                // First down achieved
                $firstDown = true;
                $turnover = false;

                $game->down = 1;
                $game->to_go = 10;
                $this->normalizeGoalToGo($game);
            } else {
                $firstDown = false;

                // If it was 4th down and you didn't convert, turnover on downs
                if ($beforeDown === 4) {
                    $turnover = true;

                    // Flip possession at the current physical spot
                    $this->flipPossessionAtSameSpot($game);

                    $game->down = 1;
                    $game->to_go = 10;
                    $this->normalizeGoalToGo($game);
                } else {
                    // Advance down; update to_go using signed yards (loss increases to-go)
                    $game->down = min(4, $beforeDown + 1);
                    $game->to_go = max(1, $beforeToGo - (int)$returnYards);
                    $this->normalizeGoalToGo($game);
                }
            }
            // === END DOWN / TO-GO LOGIC ===

            $yards = max(0, $returnYards);
            $seconds = 10 + $yards;
            $periodEvent = $this->applyClockRunoff($game, $seconds);

            $game->save();

            return [
                'turnover' => $turnover,
                'recovered_by' => 'OFFENSE',
                'return_yards' => $returnYards,
                'first_down' => $firstDown,
                'touchdown' => false,
                'points' => 0,
                'period_event' => $periodEvent,
            ];
        }


        // Defense recovers: flip possession at same spot
        $this->flipPossessionAtSameSpot($game);
        $game->possession = $defense;

        $returnYards = max(-20, min(99, $returnYards));

        $abs = $this->toAbs($game->pos_side, (int)$game->pos_yardline);
        $raw = $abs + $returnYards;

        // Return TD
        if ($raw >= 100) {
            $this->awardTouchdownAndEnterTry($game, $defense);

            $game->turnover_type = null;
            $game->turnover_by = null;


            $yards = max(0, (int)$returnYards); // only count positive
            $seconds = 10 + $yards;

            $periodEvent = $this->applyClockRunoff($game, $seconds);

            return [
                'turnover' => true,
                'recovered_by' => 'DEFENSE',
                'return_yards' => $returnYards,
                'touchdown' => true,
                'points' => 6,
                'period_event' => $periodEvent,
            ];
        }

        $absAfter = max(0, min(100, $raw));
        [$side, $yl] = $this->fromAbs($absAfter);
        $game->pos_side = $side;
        $game->pos_yardline = $yl;

        $game->phase = 'NORMAL';
        $game->turnover_type = null;
        $game->turnover_by = null;

        $game->down = 1;
        $game->to_go = 10;
        $this->normalizeGoalToGo($game);

        $yards = max(0, (int)$returnYards); // only count positive
        $seconds = 10 + $yards;

        $periodEvent = $this->applyClockRunoff($game, $seconds);


        $game->save();

        return [
            'turnover' => true,
            'recovered_by' => 'DEFENSE',
            'return_yards' => $returnYards,
            'touchdown' => false,
            'points' => 0,
            'period_event' => $periodEvent,
        ];
    }

    private function startSeries(\App\Models\Game $game): void
    {
        // Compute absolute from HOME goal line using CURRENT spot + CURRENT possession
        $side = $game->pos_side;
        $yl   = (int) $game->pos_yardline;

        // abs from current offense goal line (0..100)
        $absFromOffense = ($side === 'OWN') ? $yl : 100 - $yl;

        // convert to abs from HOME goal line
        $absFromHome = ($game->possession === 'HOME')
            ? $absFromOffense
            : 100 - $absFromOffense;

        $game->series_abs_home = max(0, min(100, (int) $absFromHome));
    }

    public function ensureGameDefaults(\App\Models\Game $game): void
    {
        $game->home_q = $game->home_q ?? [0,0,0,0,0];
        $game->away_q = $game->away_q ?? [0,0,0,0,0];

        $game->quarter = $game->quarter ?: 1;
        $game->clock   = $game->clock   ?: 15 * 60;

        if (! $game->first_kick_team) {
            $game->first_kick_team = $game->kick_team ?: 'HOME';
        }
    }

    private function clockRunoffForEvent(string $event): int
    {
        return match ($event) {
            'RUN', 'PASS_COMPLETE' => random_int(20, 40),
            'INCOMPLETE'           => random_int(10, 20),
            'KICKOFF'              => random_int(10, 20),
            'PUNT'                 => random_int(20, 30),
            default                => 0,
        };
    }

    private function old_applyClockRunoff(\App\Models\Game $game, int $seconds): ?string
    {
        if ($seconds <= 0) return null;

        $this->ensureGameDefaults($game);

        $game->clock = max(0, (int)$game->clock - $seconds);

        if ((int)$game->clock === 0) {
            return $this->endQuarterAndAdvance($game);
        }

        $game->save();
        return null;
    }


    private function applyClockRunoff(Game $game, int $seconds): ?string
    {
        $seconds = max(0, (int)$seconds);

        $game->clock = max(0, (int)$game->clock - $seconds);

        if ($game->clock > 0) {
            $game->save();
            return null;
        }

        // clock hit 0:00 -> period boundary
        // OT end (quarter 5) => game ends (tie if still tied)
        if ((int)$game->quarter >= 5) {
            $game->phase = 'FINAL'; // or whatever you use to indicate final
            $game->save();
            return 'GAME_END';
        }

        // End of Q1–Q3
        if ((int)$game->quarter < 4) {
            $game->quarter = (int)$game->quarter + 1;
            $game->clock = 15 * 60;
            $game->save();
            return 'QUARTER_END';
        }

        // End of regulation (Q4)
        if ((int)$game->quarter === 4) {
            if ((int)$game->home_score === (int)$game->away_score) {
                $game->quarter = 5;
                $game->clock   = 10 * 60;

                // OT begins with kickoff, but user must pick kick team
                $game->phase = 'KICKOFF';
                $game->kick_team = null;

                $game->save();
                return 'OVERTIME'; // your toast
            }

            $game->phase = 'FINAL';
            $game->save();
            return 'GAME_END';
        }


        $game->save();
        return null;
    }





    private function endQuarterAndAdvance(\App\Models\Game $game): ?string
    {
        $endedQuarter = (int) $game->quarter;

        if ($endedQuarter >= 4) {
            $game->clock = 0;
            $game->save();
            return 'GAME_END';
        }

        $game->quarter = $endedQuarter + 1;
        $game->clock = 15 * 60;

        if ((int)$game->quarter === 3) {
            $kickingTeam = ($game->first_kick_team === 'HOME') ? 'AWAY' : 'HOME';
            $this->startKickoff($game, $kickingTeam);
        }

        $game->save();

        if ($endedQuarter === 2) return 'HALFTIME';
        return 'QUARTER_END';
    }



    private function addPoints(\App\Models\Game $game, string $team, int $points): void
    {
        if ($points <= 0) return;

        $this->ensureGameDefaults($game);

//        $qIndex = max(0, min(3, ((int)$game->quarter) - 1));
        $qIndex = min(max((int)$game->quarter, 1), 5) - 1; // 0..4

        $home = $game->home_q ?? [0,0,0,0,0];
        $away = $game->away_q ?? [0,0,0,0,0];


        if ($team === 'HOME') {
            $home[$qIndex] = ($home[$qIndex] ?? 0) + $points;
            $game->home_q = $home;
        } else {
            $away[$qIndex] = ($away[$qIndex] ?? 0) + $points;
            $game->away_q = $away;
        }

        $game->save();
    }

    private function addPointsToQuarter(\App\Models\Game $game, string $team, int $points): void
    {
        if ($points <= 0) return;

        $this->ensureGameDefaults($game);

        $qIndex = max(0, min(3, ((int)$game->quarter) - 1));
        $idx = min(max((int)$game->quarter, 1), 5) - 1; // 0..4

        $home = $game->home_q ?? [0,0,0,0,0];
        $away = $game->away_q ?? [0,0,0,0,0];

        if ($team === 'HOME') {
            $home[$qIndex] = (int)($home[$qIndex] ?? 0) + $points;
            $game->home_q = $home;
        } else {
            $away[$qIndex] = (int)($away[$qIndex] ?? 0) + $points;
            $game->away_q = $away;
        }

        // Do NOT save here if your caller saves later, but it’s safe if you do:
        $game->save();
    }


    private function randSeconds(int $min, int $max): int
    {
        return random_int($min, $max);
    }

}
