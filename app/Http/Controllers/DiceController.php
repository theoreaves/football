<?php

namespace App\Http\Controllers;

use App\Models\DefensePlay;
use App\Models\Game;
use App\Models\OffensePlay;
use App\Models\Player;
use App\Services\GamePlayEngine;
use Illuminate\Http\Request;
use Log;

class DiceController extends Controller
{
    public function index(Game $game)
    {
        $diceOnly = false;

        $possessionTeam = $game->possessionTeam();
//        $offensePlays = OffensePlay::where('code', '!=', 'PRESSURE')->get();
        $offensePlays = OffensePlay::all();
        $defensePlays = DefensePlay::all();

        return view('dice.index', [
            'game' => $game,
            'possessionTeam' => $possessionTeam,
            'offensePlays' => $offensePlays,
            'defensePlays' => $defensePlays,
            'diceOnly' => $diceOnly,
        ]);
    }

    public function resolve(Request $request, Game $game, GamePlayEngine $engine)
    {
        $data = $request->validate([
            'offense_play_id' => ['nullable', 'integer', 'exists:offense_plays,id'],
            'defense_play_id' => ['nullable', 'integer', 'exists:defense_plays,id'],

            // result roll pieces
            'red'   => ['required', 'integer', 'min:1', 'max:6'],
            'white' => ['required', 'integer', 'min:0', 'max:9'], // tens digit

            // rolls (recommend 1..10, see Blade section below)
            'blue'   => ['required', 'integer', 'min:1', 'max:10'],
            'green'  => ['required', 'integer', 'min:1', 'max:20'],
            'orange' => ['required', 'integer', 'min:1', 'max:20'],
            'purple' => ['required', 'integer', 'min:1', 'max:10'],

            // optional flags if you want to pass them; otherwise derive from game state
            'redzone' => ['sometimes', 'boolean'],
            'play_type'   => 'required'
        ]);

        $offensePlay = OffensePlay::find($data['offense_play_id']);
        $defensePlay = DefensePlay::find($data['defense_play_id']);

        // Derive offense/defense team from game possession
        $offenseTeam = $game->possessionTeam();

        // You need a consistent way to determine defense team.
        // If you have homeTeam/awayTeam relations like shown in your Blade:
        $defenseTeam = ($offenseTeam->id === $game->home_team_id)
            ? $game->awayTeam
            : $game->homeTeam;

        $resultRoll = (int) ((string)$data['red'] . (string)$data['white']); // e.g. 5 + 7 => "57"
        $skillRoll  = (int) $data['blue'];

        $redzone = (bool)($data['redzone'] ?? false);

        $offenseIsHome = ($offenseTeam->id === $game->home_team_id);

        Log::debug('Resolving play with data: ', $data);

        switch ($data['play_type']) {
            case 'NORMAL':
                $resolved = $engine->resolvePlayResult(
                    offenseCode: $offensePlay->code,
                    defenseCode: $defensePlay->code,
                    resultRoll: $resultRoll,
                    skillRoll: $skillRoll,
                    offenseTeamId: $offenseTeam->id,
                    defenseTeamId: $defenseTeam->id,
                    playerDie: (int)$data['green'],
                    tacklerDie: (int)$data['purple'],
                    disrupterDie: (int)$data['orange'],
                    redzone: $redzone,
                    offenseIsHome: $offenseIsHome
                );



                $resolvedYards = $resolved['yards'] ?? 0;
                if (stripos($resolvedYards, 'B!') !== false) {
                    $speed = Player::find( $resolved['offense_player_id'])->speed ?? 0;
                    $red = rand(1,6);
                    $white = rand(1,10);
                    $blue = rand(1,10);
                    $roll = $red + $white + $blue;
                    $breakAway = $engine->breakaway($roll, $blue, $speed);
                    $number = (int)filter_var($resolvedYards, FILTER_SANITIZE_NUMBER_INT);
                    $resolved['breakAwayData'] = $breakAway;
                    $resolved['breakAwayDies'] = [
                        'red' => $red,
                        'white' => $white,
                        'blue' => $blue,
                        'roll' => $roll
                    ];
                    $resolvedYards = $breakAway['yards'] + $number;
                }

                try{
                    $playResults = [
                        'Play Type' => $resolved['play_type'],
                        'Yards Gained' => $resolvedYards,
//                        'Result' => $resolved['result'],
                    ];
                    if (stripos($resolved['yards'], 'B!') !== false) {
                        $playResults['Alert!'] = 'It a Break Away!';
                    }
                    if ($resolved['play_type'] === 'PASS' or $resolved['play_type'] === 'INT' or $resolved['play_type'] === 'SACK') {
                        $playResults['Quarter Back'] = $resolved['quarterback_jersey_number'] . ':' . $resolved['quarterback_name'];
                        $playResults['Receiver'] = $resolved['offense_player_jersey_number'] . ':' . $resolved['offense_player_name'];
                    } else {
                        $playResults['Ball Carrier'] = $resolved['offense_player_jersey_number'] . ':' . $resolved['offense_player_name'];
                    }
                    $playResults['Defense/Tackler'] = $resolved['tackler_jersey_number'] . ':' . $resolved['tackler_name'];
                } catch (\Throwable $th) {
                    $playResults = $resolved;
                }
                break;
            case 'KICKOFF':
                $kickReturner = $defenseTeam->players->first(function ($p) {
                    return $p->pivot->depth_chart_position === 'KR1';
                });
                $kickReturner_id = isset($kickReturner) ? $kickReturner->id : null;
                $kickReturner_name = isset($kickReturner) ? $kickReturner->firstname . ' ' . $kickReturner->lastname : null;
                $kickReturner_jersey_number = isset($kickReturner) ? $kickReturner->current_jersey_number : null;
                $resolved = $engine->kickoff(
                    kickReturner: $kickReturner,
                    redDie: (int)$data['red'],
                    whiteDie: (int)$data['white'],
                    blueDie: (int)$data['blue'],
                );
                $resolved['kickReturner_id'] = $kickReturner_id;
                $resolved['kickReturner_name'] = $kickReturner_name;
                $resolved['kickReturner_jersey_number'] = $kickReturner_jersey_number;
                $kickToRaw = $resolved['kick'];

                $kickOffset = 0;
                if (preg_match('/^(\d+)(?:\s*EZ)?$/', $kickToRaw, $m)) {
                    $yards = (int) $m[1];
                    if (str_contains($kickToRaw, 'EZ')) {
                        $kickOffset = +$yards;
                    } else {
                        $kickOffset = -$yards;
                    }
                }

                $kickYards = 100 - 35 + $kickOffset;
                $returnYards = $resolved['return'];

                if ($resolved['breakaway']){
                    $speed = Player::find($kickReturner_id)->speed ?? 0;
                    $red = rand(1,6);
                    $white = rand(1,10);
                    $blue = rand(1,10);
                    $roll = $red + $white + $blue;
                    $breakAway = $engine->breakaway($roll, $blue, $speed);
                    $number = (int)filter_var($returnYards, FILTER_SANITIZE_NUMBER_INT);
                    $resolved['breakAwayData'] = $breakAway;
                    $resolved['breakAwayDies'] = [
                        'red' => $red,
                        'white' => $white,
                        'blue' => $blue,
                        'roll' => $roll
                    ];
                    $returnYards = $breakAway['yards'] + $number;
                }

                $playResults = [
                    'Type' => 'KICKOFF',
                    'Kick To' => $resolved['kick'],
                    'Kick Yards' => $kickYards,
                    'Return Yards' => $returnYards,
                    'Kick Returner' => $resolved['kickReturner_jersey_number'] . ':' . $resolved['kickReturner_name']
                ];
                if ($resolved['breakaway']){
                    $playResults['Alert!'] = 'It a Break Away!';
                }
                break;
            case "PUNT-START":
                $resolved = $engine->punt($resultRoll);
                $type = $resolved['type'];
                switch ($type) {
                    case 'C':
                        $description = 'Caught';
                        break;
                    case 'FC':
                        $description = 'Fair Catch';
                        break;
                    case 'TB':
                        $description = 'Touchback';
                        break;
                    case 'OOB':
                        $description = 'Out of Bounds';
                        break;
                    default:
                        $description = 'Unknown: ' . $type;
                        break;
                }
                $playResults = [
                    'Yards' => $resolved['distance'],
                    'CAN RETURN' => $description
                ];
                break;
            case "PUNT":
                $puntReturner = $defenseTeam->players->first(function ($p) {
                    return $p->pivot->depth_chart_position === 'KR1';
                });
                $puntReturner_id = isset($puntReturner) ? $puntReturner->id : null;
                $puntReturner_name = isset($puntReturner) ? $puntReturner->firstname . ' ' . $puntReturner->lastname : null;
                $puntReturner_jersey_number = isset($puntReturner) ? $puntReturner->current_jersey_number : null;

                $resolved = $engine->punt_return( $puntReturner, resultRoll: $resultRoll, skillRoll: $skillRoll);
                $resolved['puntReturner_id'] = $puntReturner_id;
                $resolved['puntReturner_name'] = $puntReturner_name;
                $resolved['puntReturner_jersey_number'] = $puntReturner_jersey_number;


                $resolvedYards = $resolved['yards'] ?? 0;
                if (stripos($resolvedYards, 'B!') !== false) {
                    $speed = Player::find( $resolved['puntReturner_id'])->speed ?? 0;
                    $red = rand(1,6);
                    $white = rand(1,10);
                    $blue = rand(1,10);
                    $roll = $red + $white + $blue;
                    $breakAway = $engine->breakaway($roll, $blue, $speed);
                    $number = (int)filter_var($resolvedYards, FILTER_SANITIZE_NUMBER_INT);
                    $resolved['breakAwayData'] = $breakAway;
                    $resolved['breakAwayDies'] = [
                        'red' => $red,
                        'white' => $white,
                        'blue' => $blue,
                        'roll' => $roll
                    ];
                    $resolvedYards = $breakAway['yards'] + $number;
                }

                $playResults = [
                    'Yards' => $resolvedYards,
                    'Punt Returner' => $resolved['puntReturner_jersey_number'] . ':' . $resolved['puntReturner_name']
                ];

                if (stripos($resolved['yards'], 'B!') !== false) {
                    $playResults['Alert!'] = 'It a Break Away!';
                }
                break;
            case 'TRY';
                $yards = $game->pos_yardline;
                $posSide = $game->pos_side;
                if ($posSide == 'OWN'){
                    $yards = 117 - $yards;
                }


                $kicker = $defenseTeam->players->first(function ($p) {
                    return $p->pivot->depth_chart_position === 'KR1';
                });
                if (!$kicker) {
                    throw new \RuntimeException('No kicker found (KR1)');
                }

                $kicker_id = isset($kicker) ? $kicker->id : null;
                $kicker_name = isset($kicker) ? $kicker->firstname . ' ' . $kicker->lastname : null;
                $kicker_jersey_number = isset($kicker) ? $kicker->current_jersey_number : null;

                // Determine attribute based on distance
                if ($yards <= 30) {
                    $ratingField = 'kick30';
                } elseif ($yards <= 39) {
                    $ratingField = 'kick39';
                } elseif ($yards <= 49) {
                    $ratingField = 'kick49';
                } else {
                    $ratingField = 'kick50';
                }

                $kickRating = (int) $kicker->{$ratingField};

                $enhancedRoll = $resultRoll + $kickRating;


                $result = $engine->field_goal_attempt($yards, $enhancedRoll);
                $resolved['kicker_id'] = $kicker_id;
                $resolved['kicker_name'] = $kicker_name;
                $resolved['kicker_jersey_number'] = $kicker_jersey_number;
                $resolved['kicker_rating_used'] = $kickRating;
                $resolved['enhancedRoll'] = $enhancedRoll;
                $resolved['yards'] = $yards;
                $resolved['result'] = $result;

                $playResults = [
                    'Attempt' => $yards,
                    'Result' => $result ? 'GOOD' : 'NO GOOD',
                    'Kicker' => $resolved['kicker_jersey_number'] . ':' . $resolved['kicker_name']
                ];

                break;
            case 'FUMBLE-HAPPENED':
                $resolved = $engine->fumble_result( $resultRoll, $data['blue'], $offenseTeam->id, $defenseTeam->id);
                $result = $resolved['side'];
                $fumbleResult = 'RECOVERED BY DEFENSE';
                if ($result === 'O') {
                    $fumbleResult = 'RECOVERED BY OFFENSE';
                }
                if ($result === 'OOB') {
                    $fumbleResult = 'OUT OF BOUNDS - OFFENSE KEEPS THE BALL';
                    $recoveredBy = 'N/A';
                } else {
                    $recoveredBy = $resolved['recoveredBy_jersey_number'] . ':' . $resolved['recoveredBy_name'];
                }
                $playResults = [
                    'Fumble Result' => $fumbleResult,
                    'Recovered By' => $recoveredBy,
                    'Returned' => $resolved['return_allowed'] ? 'YES' : 'NO',
                ];
                break;
            case 'FUMBLE':
                $resolved = $engine->returns($resultRoll, $data['blue']);


                $resolvedYards = $resolved['yards'] ?? 0;
                if (stripos($resolvedYards, 'B!') !== false) {
                    $speed = 0; // Player::find( $resolved['puntReturner_id'])->speed ?? 0;
                    $red = rand(1,6);
                    $white = rand(1,10);
                    $blue = rand(1,10);
                    $roll = $red + $white + $blue;
                    $breakAway = $engine->breakaway($roll, $blue, $speed);
                    $number = (int)filter_var($resolvedYards, FILTER_SANITIZE_NUMBER_INT);
                    $resolved['breakAwayData'] = $breakAway;
                    $resolved['breakAwayDies'] = [
                        'red' => $red,
                        'white' => $white,
                        'blue' => $blue,
                        'roll' => $roll
                    ];
                    $resolvedYards = $breakAway['yards'] + $number;
                }

                $playResults = ['Yards' => $resolvedYards];
                if (stripos($resolvedYards = $resolved['yards'] ?? 0, 'B!') !== false) {
                    $playResults['Alert!'] = 'It a Break Away!';
                }
                break;
            case 'BREAKAWAY':
                $resolved = $engine->breakaway(
                    resultRoll: $resultRoll,
                    blueDie:  $data['blue'],
                    speed: $data['speed'],
                );
                break;
            default:
                $resolved = ['error' => 'Unknown play type'];
                $playResults = [
                    'resolved' => $resolved
                ];
        }


        $resolved['data'] = $data; // include input data for debugging

        return response()->json([
            'ok' => true,
            'inputs' => [
                'offense_code' => $offensePlay->code ?? 'NONE',
                'defense_code' => $defensePlay->code ?? 'NONE',
                'result_roll' => $resultRoll,
                'skill_roll' => $skillRoll,
                'offense_team_id' => $offenseTeam->id,
                'defense_team_id' => $defenseTeam->id,
            ],
            'play_results' => $playResults,
            'resolved' => $resolved,
        ]);
    }
}
