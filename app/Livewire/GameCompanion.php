<?php

namespace App\Livewire;

use App\Models\Game;
use App\Models\Play;
use App\Models\TeamPlayer;
use App\Services\FootballRulesEngine;
use App\Services\GameStatService;
use Livewire\Component;

class GameCompanion extends Component
{
    public Game $game;

    public string $kick_side = 'OWN';
    public int $kick_yardline = 25;

    public int $kick_yards = 55;
    public int $return_yards = 0;
    public bool $kick_recorded = false;

    public int $punt_yards = 40;
    public int $punt_return_yards = 0;
    public bool $punt_recorded = false;

    public int $int_return_yards = 0;
    public bool $int_recorded = false;

    public string $fumble_recovered_by = 'OFFENSE'; // OFFENSE|DEFENSE
    public int $fumble_return_yards = 0;


    public int $edit_down = 1;
    public int $edit_to_go = 10;

    public int $edit_clock_min = 15;
    public int $edit_clock_sec = 0;



    public string $play_type = '';
    public int $play_yards = 0;
    public string $play_note = '';

    public ?string $kickoff_kicking_team = null; // HOME|AWAY

    // Participants (plays table FKs -> team_players.id)
    public ?int $qb_team_player_id = null;
    public ?int $ballcarrier_team_player_id = null;
    public ?int $receiver_team_player_id = null;
    public ?int $tackled_by_team_player_id = null;
    public ?int $intercepted_by_team_player_id = null;
    public ?int $fumble_recovered_by_team_player_id = null;

    public array $offensePlayers = [];
    public array $defensePlayers = [];

    public int $fieldScale = 100;

    protected $listeners = ['setDownAndDistance'];

    public function mount(?int $gameId = null): void
    {
//        $this->game = $gameId
//            ? \App\Models\Game::findOrFail($gameId)
//            : \App\Models\Game::create();

        $this->game = $gameId
            ? \App\Models\Game::with(['homeTeam','awayTeam'])->findOrFail($gameId)
            : \App\Models\Game::create();

        $this->loadPlayers();

        $this->kickoff_kicking_team = $this->game->kick_team; // likely null

        $this->edit_down  = (int) ($this->game->down ?? 1);
        $this->edit_to_go = (int) ($this->game->to_go ?? 10);

        $sec = (int)($this->game->clock ?? (15 * 60));
        $this->edit_clock_min = intdiv($sec, 60);
        $this->edit_clock_sec = $sec % 60;


        $engine = app(\App\Services\FootballRulesEngine::class);

        // Brand-new game defaults (scoreboard + clock)
        if (! $gameId) {
            // Initialize if not set yet
            $this->game->quarter = $this->game->quarter ?: 1;
            $this->game->clock   = $this->game->clock   ?: 15 * 60;

            $this->game->home_q = $this->game->home_q ?: [0,0,0,0,0];
            $this->game->away_q = $this->game->away_q ?: [0,0,0,0,0];

            // First kickoff team (used for halftime kickoff)
            if (! $this->game->first_kick_team) {
                $this->game->first_kick_team = 'HOME';
            }

            $this->game->save();
        }

        // Start new games with kickoff
//        if (! $gameId && ($this->game->phase ?? 'NORMAL') === 'NORMAL') {
//            $engine->startKickoff($this->game, 'HOME');
//            $this->game->refresh();
//        }

        if (! $gameId) {
            $this->game->quarter = $this->game->quarter ?: 1;
            $this->game->clock   = $this->game->clock   ?: 15 * 60;

            $this->game->home_q = $this->game->home_q ?: [0,0,0,0,0];
            $this->game->away_q = $this->game->away_q ?: [0,0,0,0,0];

            // Start in kickoff-selection mode
            $this->game->phase = 'KICKOFF';
            $this->game->kick_team = null; // user must choose
            $this->game->save();
        }


        $this->kick_recorded = false;
        $this->kick_yards = 55;
        $this->return_yards = 0;

        $this->kick_side = $this->game->pos_side ?? 'OWN';
        $this->kick_yardline = (int) ($this->game->pos_yardline ?? 25);
    }

    protected function loadPlayers(): void
    {
        $offenseTeamId = $this->game->possession === 'HOME'
            ? $this->game->home_team_id
            : $this->game->away_team_id;

        $defenseTeamId = $this->game->possession === 'HOME'
            ? $this->game->away_team_id
            : $this->game->home_team_id;

        $offensePositions = ['QB1', 'RB1', 'RB2', 'WR1', 'WR2', 'WR3', 'TE1', 'TE2'];
        $defensePositions = ['DL1', 'DL2', 'DL3', 'DL4', 'CB1', 'CB2', 'S1', 'S2', 'DB1', 'DB2'];

        $this->offensePlayers = TeamPlayer::where('team_id', $offenseTeamId)
            ->whereIn('depth_chart_position', $offensePositions)
            ->orderByRaw('CASE depth_chart_position ' .
                collect($offensePositions)->map(fn($pos, $i) => "WHEN '{$pos}' THEN {$i}")->implode(' ') .
                ' ELSE 999 END')
            ->get()
            ->toArray();



        $this->defensePlayers = TeamPlayer::where('team_id', $defenseTeamId)
            ->whereIn('depth_chart_position', $defensePositions)
            ->orderByRaw('CASE depth_chart_position ' .
                collect($defensePositions)->map(fn($pos, $i) => "WHEN '{$pos}' THEN {$i}")->implode(' ') .
                ' ELSE 999 END')
            ->get()
            ->toArray();
    }

    public function setKickoffSpot(FootballRulesEngine $engine): void
    {
        $this->validate([
            'kick_side' => 'required|in:OWN,OPP',
            'kick_yardline' => 'required|integer|min:1|max:50',
        ]);

        $engine->applyKickoffSpot($this->game, $this->kick_side, $this->kick_yardline);
        $this->game->refresh();
    }

    public function setDownAndDistance(): void
    {
        $this->validate([
            'edit_down'  => 'required|integer|min:1|max:4',
            'edit_to_go' => 'required|integer|min:1|max:99',
        ]);

        $this->game->down  = (int) $this->edit_down;
        $this->game->to_go = (int) $this->edit_to_go;

        $this->game->save();
        $this->game->refresh();
    }


    public function submitPlay(FootballRulesEngine $engine): void
    {
        $this->validate([
            'play_type' => 'required|string|max:20',
            'play_yards' => 'required|integer|min:-99|max:99',
            'play_note' => 'nullable|string|max:200',
        ]);


        $type = strtoupper(trim($this->play_type));

        if ($type === 'INCOMPLETE') {
            $this->play_yards = 0;
        }
        if ($type === 'INTERCEPTION' || $type === 'INT') {
            $engine->startInterception($this->game);
            $this->game->refresh();
            $this->int_return_yards = 0;
            $this->play_yards = 0;
            $this->play_note = '';
            return;
        }

        if ($type === 'FUMBLE' || $type === 'FUM') {
            $engine->startFumble($this->game);
            $this->game->refresh();
            $this->fumble_recovered_by = 'OFFENSE';
            $this->fumble_return_yards = 0;
            $this->play_yards = 0;
            $this->play_note = '';
            return;
        }

        // ✅ If user selected FIELDGOAL, switch to field goal  phase instead of applyPlay()
        if ($type === 'FIELDGOAL') {
            // Start punt phase (punting team = current possession)
            $engine->startFieldGoal($this->game, $this->game->possession);
            $this->game->refresh();

            // Reset punt UI defaults
            $this->punt_recorded = false;
            $this->punt_yards = 40;
            $this->punt_return_yards = 0;

            // Clear normal input
            $this->play_yards = 0;
            $this->play_note = '';

            return;
        }

        // ✅ If user selected PUNT, switch to Punt phase instead of applyPlay()
        if ($type === 'PUNT') {
            // Start punt phase (punting team = current possession)
            $engine->startPunt($this->game, $this->game->possession);
            $this->game->refresh();

            // Reset punt UI defaults
            $this->punt_recorded = false;
            $this->punt_yards = 40;
            $this->punt_return_yards = 0;

            // Clear normal input
            $this->play_yards = 0;
            $this->play_note = '';

            return;
        }


        $result = $engine->applyPlay($this->game, $type, $this->play_yards);
        $this->handleEngineResult($result);


        // Decide which participant columns apply for this play type
        $pt = strtoupper((string) $this->play_type);

        $qb  = in_array($pt, ['PASS','INCOMPLETE','INT','SACK'], true) ? $this->qb_team_player_id : null;
        $rcv = in_array($pt, ['PASS','INCOMPLETE','INT'], true) ? $this->receiver_team_player_id : null;

        $bc  = in_array($pt, ['RUN','FUMBLE'], true) ? $this->ballcarrier_team_player_id : null;

        $tkl = in_array($pt, ['RUN','PASS','INCOMPLETE','FUMBLE','SACK'], true) ? $this->tackled_by_team_player_id : null;

        $intBy = ($pt === 'INT') ? $this->intercepted_by_team_player_id : null;

// NOTE: “fumble recovered by” only on FUMBLE (per your note)
        $fumRec = ($pt === 'FUMBLE') ? $this->fumble_recovered_by_team_player_id : null;


        Play::create([
            'game_id' => $this->game->id,
            'seq' => $this->game->play_seq,
            'type' => strtoupper($this->play_type),
            'yards' => $this->play_yards,
            'note' => trim($this->play_note) ?: null,

            'possession_before' => $result['before']['possession'],
            'side_before' => $result['before']['side'],
            'yardline_before' => $result['before']['yardline'],
            'down_before' => $result['before']['down'],
            'togo_before' => $result['before']['to_go'],

            'possession_after' => $result['after']['possession'],
            'side_after' => $result['after']['side'],
            'yardline_after' => $result['after']['yardline'],
            'down_after' => $result['after']['down'],
            'togo_after' => $result['after']['to_go'],

            'first_down' => (bool)$result['first_down'],
            'turnover' => (bool)$result['turnover'],
            'touchdown' => (bool)$result['touchdown'],

            'qb_team_player_id' => $qb,
            'ballcarrier_team_player_id' => $bc,
            'receiver_team_player_id' => $rcv,
            'tackled_by_team_player_id' => $tkl,
            'intercepted_by_team_player_id' => $intBy,
            'fumble_recovered_by_team_player_id' => $fumRec,
        ]);

        $this->play_type = '';
        $this->play_yards = 0;
        $this->play_note = '';

        //keep this out of the reset above so that user can quickly enter another play with same participants
//        $this->qb_team_player_id = null;
//        $this->ballcarrier_team_player_id = null;
//        $this->receiver_team_player_id = null;
//        $this->tackled_by_team_player_id = null;
//        $this->intercepted_by_team_player_id = null;
//        $this->fumble_recovered_by_team_player_id = null;



        $this->game->refresh();

        $this->dispatch('play-recorded');

    }

    public function recordTry(string $type, bool $good, FootballRulesEngine $engine): void
    {
        // Only allow during TRY phase
        if ($this->game->phase !== 'TRY') {
            return;
        }

        // Normalize input FIRST
        $typeNorm = strtoupper($type);
        $typeNorm = $typeNorm === '2PT' ? '2PT' : 'XP';

        $before = [
            'possession' => $this->game->possession,
            'side' => $this->game->pos_side,
            'yardline' => (int) $this->game->pos_yardline,
            'down' => (int) $this->game->down,
            'to_go' => (int) $this->game->to_go,
        ];

        // Call engine with normalized type
        $res = $engine->recordTry($this->game, $typeNorm, $good);
        $this->handleEngineResult($res);
        $this->game->refresh();


        \App\Models\Play::create([
            'game_id' => $this->game->id,
            'seq' => $this->game->play_seq,

            'type' => $typeNorm === '2PT' ? 'TRY_2PT' : 'TRY_XP',
            'yards' => 0,
            'note' => null,

            // Use the method arg, not $res keys that may not exist
            'result' => $good ? 'GOOD' : 'FAIL',
            'points' => (int) ($res['points'] ?? 0),

            // Use normalized type here as well
            'meta' => [
                'try_type' => $typeNorm, // 'XP' or '2PT'
            ],

            'possession_before' => $before['possession'],
            'side_before' => $before['side'],
            'yardline_before' => $before['yardline'],
            'down_before' => $before['down'],
            'togo_before' => $before['to_go'],

            'possession_after' => $this->game->possession,
            'side_after' => $this->game->pos_side,
            'yardline_after' => (int) $this->game->pos_yardline,
            'down_after' => (int) $this->game->down,
            'togo_after' => (int) $this->game->to_go,

            'first_down' => false,
            'turnover' => false,
            'touchdown' => false,
        ]);
    }

    public function recordFieldGoal(bool $good, FootballRulesEngine $engine): void
    {
        // Don’t allow during TRY phase
        if ($this->game->phase === 'TRY') {
            return;
        }

        $before = [
            'possession' => $this->game->possession,
            'side' => $this->game->pos_side,
            'yardline' => (int) $this->game->pos_yardline,
            'down' => (int) $this->game->down,
            'to_go' => (int) $this->game->to_go,
        ];

        $res = $engine->recordFieldGoal($this->game, $good);
        $this->handleEngineResult($res);
        $this->game->refresh();

        \App\Models\Play::create([
            'game_id' => $this->game->id,
            'seq' => $this->game->play_seq,

            'type' => 'FIELD_GOAL',
            'yards' => 0,
            'note' => null,

            'result' => $good ? 'GOOD' : 'MISS',
            'points' => (int) ($res['points'] ?? 0),
            'meta' => [
                'kicking_team' => $res['kicking_team'] ?? $before['possession'],
            ],

            'possession_before' => $before['possession'],
            'side_before' => $before['side'],
            'yardline_before' => $before['yardline'],
            'down_before' => $before['down'],
            'togo_before' => $before['to_go'],

            'possession_after' => $this->game->possession,
            'side_after' => $this->game->pos_side,
            'yardline_after' => (int) $this->game->pos_yardline,
            'down_after' => (int) $this->game->down,
            'togo_after' => (int) $this->game->to_go,

            'first_down' => false,
            'turnover' => ! $good, // missed FG treated as turnover in v1
            'touchdown' => false,
        ]);
    }

    public function recordKickoff(\App\Services\FootballRulesEngine $engine): void
    {
        if ($this->game->phase !== 'KICKOFF' || ! $this->game->kick_team) {
            return;
        }

        $this->validate([
            'kick_yards' => 'required|integer|min:0|max:99',
        ]);

        $before = [
            'possession' => $this->game->possession,
            'side' => $this->game->pos_side,
            'yardline' => (int) $this->game->pos_yardline,
            'down' => (int) $this->game->down,
            'to_go' => (int) $this->game->to_go,
        ];

        $res = $engine->recordKickoff($this->game, $this->kick_yards);
        $this->handleEngineResult($res);
        $this->game->refresh();

        \App\Models\Play::create([
            'game_id' => $this->game->id,
            'seq' => $this->game->play_seq, // optional; if you don’t bump seq in engine for kickoff, keep as-is
            'type' => 'KICKOFF',
            'yards' => (int) $this->kick_yards,
            'note' => null,

            'result' => 'KICK',
            'points' => 0,
            'meta' => [
                'kick_yards' => (int) $this->kick_yards,
                'kicking_team' => $res['kicking_team'] ?? ($this->game->kick_team ?? null),
                'receiving_team' => $res['receiving_team'] ?? $this->game->possession,
            ],

            'possession_before' => $before['possession'],
            'side_before' => $before['side'],
            'yardline_before' => $before['yardline'],
            'down_before' => $before['down'],
            'togo_before' => $before['to_go'],

            'possession_after' => $this->game->possession,
            'side_after' => $this->game->pos_side,
            'yardline_after' => (int) $this->game->pos_yardline,
            'down_after' => (int) $this->game->down,
            'togo_after' => (int) $this->game->to_go,

            'first_down' => false,
            'turnover' => false,
            'touchdown' => false,
        ]);

        $this->kick_recorded = true;
    }

    public function recordKickReturn(\App\Services\FootballRulesEngine $engine): void
    {
        if ($this->game->phase !== 'KICKOFF' || ! $this->game->kick_team) {
            return;
        }

        $this->validate([
            'return_yards' => 'required|integer|min:-20|max:99',
        ]);

        $before = [
            'possession' => $this->game->possession,
            'side' => $this->game->pos_side,
            'yardline' => (int) $this->game->pos_yardline,
            'down' => (int) $this->game->down,
            'to_go' => (int) $this->game->to_go,
        ];

        $res = $engine->recordKickReturn($this->game, $this->return_yards);
        $this->handleEngineResult($res);
        $this->game->refresh();

        \App\Models\Play::create([
            'game_id' => $this->game->id,
            'seq' => $this->game->play_seq,
            'type' => 'KICK_RETURN',
            'yards' => (int) $this->return_yards,
            'note' => null,

            'result' => 'RETURN',
            'points' => 0,
            'meta' => [
                'return_yards' => (int) $this->return_yards,
            ],

            'possession_before' => $before['possession'],
            'side_before' => $before['side'],
            'yardline_before' => $before['yardline'],
            'down_before' => $before['down'],
            'togo_before' => $before['to_go'],

            'possession_after' => $this->game->possession,
            'side_after' => $this->game->pos_side,
            'yardline_after' => (int) $this->game->pos_yardline,
            'down_after' => (int) $this->game->down,
            'togo_after' => (int) $this->game->to_go,

            'first_down' => false,
            'turnover' => false,
            'touchdown' => false,
        ]);

        // reset UI
        $this->kick_recorded = false;
        $this->kick_yards = 55;
        $this->return_yards = 0;
        $this->loadPlayers();
    }

    public function startPunt(\App\Services\FootballRulesEngine $engine): void
    {
        if ($this->game->phase !== 'NORMAL') {
            return;
        }

        $engine->startPunt($this->game, $this->game->possession);
        $this->game->refresh();

        $this->punt_recorded = false;
        $this->punt_yards = 40;
        $this->punt_return_yards = 0;
    }

    public function recordPunt(\App\Services\FootballRulesEngine $engine): void
    {
        if ($this->game->phase !== 'PUNT') {
            return;
        }

        $this->validate([
            'punt_yards' => 'required|integer|min:0|max:99',
        ]);

        $before = [
            'possession' => $this->game->possession,
            'side' => $this->game->pos_side,
            'yardline' => (int) $this->game->pos_yardline,
            'down' => (int) $this->game->down,
            'to_go' => (int) $this->game->to_go,
        ];

        $res = $engine->recordPunt($this->game, $this->punt_yards);
        $this->game->refresh();

        \App\Models\Play::create([
            'game_id' => $this->game->id,
            'seq' => $this->game->play_seq,
            'type' => 'PUNT',
            'yards' => (int) $this->punt_yards,
            'note' => null,
            'result' => 'PUNT',
            'points' => 0,
            'meta' => [
                'punting_team' => $res['punting_team'] ?? null,
                'receiving_team' => $res['receiving_team'] ?? null,
            ],
            'possession_before' => $before['possession'],
            'side_before' => $before['side'],
            'yardline_before' => $before['yardline'],
            'down_before' => $before['down'],
            'togo_before' => $before['to_go'],
            'possession_after' => $this->game->possession,
            'side_after' => $this->game->pos_side,
            'yardline_after' => (int) $this->game->pos_yardline,
            'down_after' => (int) $this->game->down,
            'togo_after' => (int) $this->game->to_go,
            'first_down' => false,
            'turnover' => true,  // punts are possession changes
            'touchdown' => false,
        ]);

        $this->punt_recorded = true;
    }

    public function recordPuntReturn(\App\Services\FootballRulesEngine $engine): void
    {
        if ($this->game->phase !== 'PUNT') {
            return;
        }

        $this->validate([
            'punt_return_yards' => 'required|integer|min:-20|max:99',
        ]);

        $before = [
            'possession' => $this->game->possession,
            'side' => $this->game->pos_side,
            'yardline' => (int) $this->game->pos_yardline,
            'down' => (int) $this->game->down,
            'to_go' => (int) $this->game->to_go,
        ];

        $res = $engine->recordPuntReturn($this->game, $this->punt_return_yards);
        $this->handleEngineResult($res);
        $this->game->refresh();

        \App\Models\Play::create([
            'game_id' => $this->game->id,
            'seq' => $this->game->play_seq,
            'type' => 'PUNT_RETURN',
            'yards' => (int) $this->punt_return_yards,
            'note' => null,
            'result' => 'RETURN',
            'points' => 0,
            'meta' => [
                'return_yards' => (int) $this->punt_return_yards,
            ],
            'possession_before' => $before['possession'],
            'side_before' => $before['side'],
            'yardline_before' => $before['yardline'],
            'down_before' => $before['down'],
            'togo_before' => $before['to_go'],
            'possession_after' => $this->game->possession,
            'side_after' => $this->game->pos_side,
            'yardline_after' => (int) $this->game->pos_yardline,
            'down_after' => (int) $this->game->down,
            'togo_after' => (int) $this->game->to_go,
            'first_down' => true, // new drive
            'turnover' => false,
            'touchdown' => false,
        ]);

        $this->punt_recorded = false;
        $this->punt_yards = 40;
        $this->punt_return_yards = 0;
        $this->loadPlayers();
    }

    public function kickoffNoReturn(\App\Services\FootballRulesEngine $engine): void
    {
        // Treat as return = 0
        $this->return_yards = 0;
        $this->kick_recorded = true; // allow return step even if user didn't toggle UI state
        $this->recordKickReturn($engine);
        $this->loadPlayers();
    }

    public function puntNoReturn(\App\Services\FootballRulesEngine $engine): void
    {
        // Treat as return = 0
        $this->punt_return_yards = 0;
        $this->punt_recorded = true;
        $this->recordPuntReturn($engine);
        $this->loadPlayers();
    }

    public function kickoffFairCatch(\App\Services\FootballRulesEngine $engine): void
    {
        $this->kick_recorded = true;
        $this->return_yards = 0;
        $this->recordKickReturnWithReason($engine, 'FAIR_CATCH');
        $this->loadPlayers();
    }

    public function kickoffTouchback(\App\Services\FootballRulesEngine $engine): void
    {
        $this->kick_recorded = true;
        $this->recordKickReturnWithReason($engine, 'TOUCHBACK');
        $this->loadPlayers();
    }

    public function puntFairCatch(\App\Services\FootballRulesEngine $engine): void
    {
        $this->punt_recorded = true;
        $this->punt_return_yards = 0;
        $this->recordPuntReturnWithReason($engine, 'FAIR_CATCH');
        $this->loadPlayers();
    }

    public function puntDowned(\App\Services\FootballRulesEngine $engine): void
    {
        $this->punt_recorded = true;
        $this->punt_return_yards = 0;
        $this->recordPuntReturnWithReason($engine, 'DOWNED');
        $this->loadPlayers();
    }

    public function puntTouchback(\App\Services\FootballRulesEngine $engine): void
    {
        $this->punt_recorded = true;
        $this->recordPuntReturnWithReason($engine, 'TOUCHBACK');
        $this->loadPlayers();
    }


    public function recordKickReturnWithReason(\App\Services\FootballRulesEngine $engine, ?string $reason = null): void
    {
        if ($this->game->phase !== 'KICKOFF') return;

        $before = [
            'possession' => $this->game->possession,
            'side' => $this->game->pos_side,
            'yardline' => (int) $this->game->pos_yardline,
            'down' => (int) $this->game->down,
            'to_go' => (int) $this->game->to_go,
        ];

        $yards = ($reason === 'TOUCHBACK') ? 0 : (int) $this->return_yards;

        $res = $engine->recordKickReturn($this->game, $yards, $reason);
        $this->handleEngineResult($res);
        $this->game->refresh();

        \App\Models\Play::create([
            'game_id' => $this->game->id,
            'seq' => $this->game->play_seq,
            'type' => 'KICK_RETURN',
            'yards' => $yards,
            'note' => null,

            'result' => ($res['touchdown'] ?? false) ? 'TD' : (($reason === 'TOUCHBACK') ? 'TOUCHBACK' : 'RETURN'),
            'points' => (int) ($res['points'] ?? 0),
            'meta' => [
                'reason' => $reason,
            ],

            'possession_before' => $before['possession'],
            'side_before' => $before['side'],
            'yardline_before' => $before['yardline'],
            'down_before' => $before['down'],
            'togo_before' => $before['to_go'],

            'possession_after' => $this->game->possession,
            'side_after' => $this->game->pos_side,
            'yardline_after' => (int) $this->game->pos_yardline,
            'down_after' => (int) $this->game->down,
            'togo_after' => (int) $this->game->to_go,

            'first_down' => $this->game->phase === 'NORMAL',
            'turnover' => false,
            'touchdown' => (bool) ($res['touchdown'] ?? false),
        ]);

        // reset kickoff UI bits
        $this->kick_recorded = false;
        $this->kick_yards = 55;
        $this->return_yards = 0;
        $this->loadPlayers();
    }

    public function recordPuntReturnWithReason(\App\Services\FootballRulesEngine $engine, ?string $reason = null): void
    {
        if ($this->game->phase !== 'PUNT') return;

        $before = [
            'possession' => $this->game->possession,
            'side' => $this->game->pos_side,
            'yardline' => (int) $this->game->pos_yardline,
            'down' => (int) $this->game->down,
            'to_go' => (int) $this->game->to_go,
        ];

        $yards = ($reason === 'TOUCHBACK') ? 0 : (int) $this->punt_return_yards;

        $res = $engine->recordPuntReturn($this->game, $yards, $reason);
        $this->handleEngineResult($res);
        $this->game->refresh();

        \App\Models\Play::create([
            'game_id' => $this->game->id,
            'seq' => $this->game->play_seq,
            'type' => 'PUNT_RETURN',
            'yards' => $yards,
            'note' => null,

            'result' => ($res['touchdown'] ?? false) ? 'TD' : (($reason === 'TOUCHBACK') ? 'TOUCHBACK' : (($reason === 'DOWNED') ? 'DOWNED' : 'RETURN')),
            'points' => (int) ($res['points'] ?? 0),
            'meta' => [
                'reason' => $reason,
            ],

            'possession_before' => $before['possession'],
            'side_before' => $before['side'],
            'yardline_before' => $before['yardline'],
            'down_before' => $before['down'],
            'togo_before' => $before['to_go'],

            'possession_after' => $this->game->possession,
            'side_after' => $this->game->pos_side,
            'yardline_after' => (int) $this->game->pos_yardline,
            'down_after' => (int) $this->game->down,
            'togo_after' => (int) $this->game->to_go,

            'first_down' => $this->game->phase === 'NORMAL',
            'turnover' => false,
            'touchdown' => (bool) ($res['touchdown'] ?? false),
        ]);

        $this->punt_recorded = false;
        $this->punt_yards = 40;
        $this->punt_return_yards = 0;
        $this->loadPlayers();
    }

    public function startInterception(\App\Services\FootballRulesEngine $engine): void
    {
        if ($this->game->phase !== 'NORMAL') return;

        $engine->startInterception($this->game);
        $this->game->refresh();

        $this->int_return_yards = 0;
        $this->int_recorded = true;
    }

    public function recordInterceptionReturn(\App\Services\FootballRulesEngine $engine): void
    {
        if ($this->game->phase !== 'INT') return;

        $this->validate([
            'int_return_yards' => 'required|integer|min:-20|max:99',
        ]);

        $before = [
            'possession' => $this->game->possession,
            'side' => $this->game->pos_side,
            'yardline' => (int)$this->game->pos_yardline,
            'down' => (int)$this->game->down,
            'to_go' => (int)$this->game->to_go,
        ];

        $res = $engine->recordInterceptionReturn($this->game, (int)$this->int_return_yards);
        $this->handleEngineResult($res);
        $this->game->refresh();

        \App\Models\Play::create([
            'game_id' => $this->game->id,
            'seq' => $this->game->play_seq,
            'type' => 'INTERCEPTION',
            'yards' => (int) $this->int_return_yards,
            'note' => null,
            'result' => ($res['touchdown'] ?? false) ? 'TD' : 'INT',
            'points' => (int) ($res['points'] ?? 0),
            'meta' => [
                'return_yards' => (int) $this->int_return_yards,
            ],
            'possession_before' => $before['possession'],
            'side_before' => $before['side'],
            'yardline_before' => $before['yardline'],
            'down_before' => $before['down'],
            'togo_before' => $before['to_go'],
            'possession_after' => $this->game->possession,
            'side_after' => $this->game->pos_side,
            'yardline_after' => (int)$this->game->pos_yardline,
            'down_after' => (int)$this->game->down,
            'togo_after' => (int)$this->game->to_go,
            'first_down' => true,
            'turnover' => true,
            'touchdown' => (bool) ($res['touchdown'] ?? false),
            'qb_team_player_id' => $this->qb_team_player_id,
            'receiver_team_player_id' => $this->receiver_team_player_id,
            'intercepted_by_team_player_id' => $this->intercepted_by_team_player_id,
            'tackled_by_team_player_id' => $this->tackled_by_team_player_id,


        ]);

        $this->int_recorded = false;
        $this->int_return_yards = 0;
        $this->loadPlayers();
    }

    public function startFumble(\App\Services\FootballRulesEngine $engine): void
    {
        if ($this->game->phase !== 'NORMAL') return;

        $engine->startFumble($this->game);
        $this->game->refresh();

        $this->fumble_recovered_by = 'OFFENSE';
        $this->fumble_return_yards = 0;
    }

    public function resolveFumble(\App\Services\FootballRulesEngine $engine): void
    {
        if ($this->game->phase !== 'FUMBLE') return;

        $this->validate([
            'fumble_recovered_by' => 'required|in:OFFENSE,DEFENSE',
            'fumble_return_yards' => 'required|integer|min:-20|max:99',
        ]);

        $before = [
            'possession' => $this->game->possession,
            'side' => $this->game->pos_side,
            'yardline' => (int)$this->game->pos_yardline,
            'down' => (int)$this->game->down,
            'to_go' => (int)$this->game->to_go,
        ];

//        $yards = $this->fumble_recovered_by === 'DEFENSE'
//            ? (int) $this->fumble_return_yards
//            : 0;
        $yards = (int) $this->fumble_return_yards;

        $res = $engine->resolveFumble($this->game, $this->fumble_recovered_by, $yards);
        $this->handleEngineResult($res);
        $this->game->refresh();

        \App\Models\Play::create([
            'game_id' => $this->game->id,
            'seq' => $this->game->play_seq,
            'type' => 'FUMBLE',
            'yards' => $yards,
            'note' => null,
            'result' => ($res['touchdown'] ?? false) ? 'TD' : 'FUM',
            'points' => (int) ($res['points'] ?? 0),
            'meta' => [
                'recovered_by' => $this->fumble_recovered_by,
                'return_yards' => $yards,
            ],
            'possession_before' => $before['possession'],
            'side_before' => $before['side'],
            'yardline_before' => $before['yardline'],
            'down_before' => $before['down'],
            'togo_before' => $before['to_go'],
            'possession_after' => $this->game->possession,
            'side_after' => $this->game->pos_side,
            'yardline_after' => (int)$this->game->pos_yardline,
            'down_after' => (int)$this->game->down,
            'togo_after' => (int)$this->game->to_go,
            'first_down' => (bool) ($res['turnover'] ?? false),
            'turnover' => (bool) ($res['turnover'] ?? false),
            'touchdown' => (bool) ($res['touchdown'] ?? false),
            'ballcarrier_team_player_id' => $this->ballcarrier_team_player_id,
            'tackled_by_team_player_id' => $this->tackled_by_team_player_id,
            'fumble_recovered_by_team_player_id' => $this->fumble_recovered_by_team_player_id,

        ]);

        $this->fumble_recovered_by = 'OFFENSE';
        $this->fumble_return_yards = 0;
        $this->loadPlayers();
    }

    public function getAbsFromHomeProperty(): int
    {
        // This converts offense-relative (pos_side/pos_yardline) to absolute from the CURRENT offense.
        // You already have toAbs/fromAbs logic in the engine; we can replicate minimal here.

        $side = $this->game->pos_side;
        $yl   = (int) $this->game->pos_yardline;

        // offense-relative abs (0..100) from the current offense goal line
        $absFromOffense = ($side === 'OWN')
            ? $yl
            : 100 - $yl;

        // Convert to absolute from HOME goal line
        // If offense is HOME, same direction.
        // If offense is AWAY, reverse.
        $absFromHome = ($this->game->possession === 'HOME')
            ? $absFromOffense
            : 100 - $absFromOffense;

        return max(0, min(100, $absFromHome));
    }

    public function getBallLeftPercentProperty(): float
    {
        return ($this->absFromHome / 100) * 100;
    }

    public function getEndZonePctProperty(): float
    {
        return (10 / 120) * 100; // 8.3333%
    }

    public function getFieldPctProperty(): float
    {
        return (100 / 120) * 100; // 83.3333%
    }

    /**
     * Ball position as % across the full 120-yard graphic
     */
    public function getBallLeft120Property(): float
    {
        $endZonePct = $this->endZonePct;
        $fieldPct   = $this->fieldPct;

        // absFromHome is 0..100 (field of play)
        return $endZonePct + ($this->absFromHome / 100) * $fieldPct;
    }

    /**
     * Line to gain as abs-from-home (0..100) then mapped to 120-yard %
     */
    public function getLineToGainAbsFromHomeProperty(): int
    {
        // Only meaningful during NORMAL play
        if (($this->game->phase ?? 'NORMAL') !== 'NORMAL') {
            return $this->absFromHome;
        }

        $toGo = (int)($this->game->to_go ?? 10);

        // HOME drives right (increase abs), AWAY drives left (decrease abs)
        $target = $this->game->possession === 'HOME'
            ? $this->absFromHome + $toGo
            : $this->absFromHome - $toGo;

        return max(0, min(100, (int)$target));
    }

    public function getLineToGainLeft120Property(): float
    {
        $endZonePct = $this->endZonePct;
        $fieldPct   = $this->fieldPct;

        return $endZonePct + ($this->lineToGainAbsFromHome / 100) * $fieldPct;
    }

    public function getShowLineToGainProperty(): bool
    {
        return ($this->game->phase ?? 'NORMAL') === 'NORMAL';
    }

    public function getSeriesAbsFromHomeProperty(): ?int
    {
        if (! $this->game->series_side || ! $this->game->series_yardline) {
            return null;
        }

        $side = $this->game->series_side;
        $yl   = (int) $this->game->series_yardline;

        $absFromOffense = ($side === 'OWN') ? $yl : 100 - $yl;

        // Convert to absolute from HOME goal line using current possession direction
        $absFromHome = ($this->game->possession === 'HOME')
            ? $absFromOffense
            : 100 - $absFromOffense;

        return max(0, min(100, (int)$absFromHome));
    }

    public function old_getSeriesLeft120Property(): ?float
    {
        $abs = $this->seriesAbsFromHome;
        if ($abs === null) return null;

        return $this->endZonePct + ($abs / 100) * $this->fieldPct;
    }

    public function getSeriesLeft120Property(): ?float
    {
        $abs = $this->game->series_abs_home;
        if ($abs === null) return null;

        return $this->endZonePct + ((int)$abs / 100) * $this->fieldPct;
    }


    public function getBallSideMarkerLeft120Property(): float
    {
        return $this->ballLeft120;
    }

    public function getDownLabelProperty(): string
    {
        $d = (int) ($this->game->down ?? 1);
        return in_array($d, [1,2,3,4], true) ? (string)$d : '1';
    }

    public function getClockDisplayProperty(): string
    {
        $sec = max(0, (int)($this->game->clock ?? 0));
        $m = intdiv($sec, 60);
        $s = $sec % 60;
        return sprintf('%d:%02d', $m, $s);
    }

    private function notifyPeriodEnd(?string $event): void
    {
        if (! $event) return;

        $msg = match ($event) {
            'QUARTER_END' => 'End of Quarter',
            'OVERTIME'    => 'Overtime',
            'HALFTIME'    => 'Halftime',
            'GAME_END'    => 'End of Game',
            default       => null,
        };

        if ($msg) {
            $this->dispatch('period-ended', message: $msg);
        }
    }

    private function handleEngineResult(array $result): void
    {
        $this->edit_down  = (int) ($this->game->down ?? 1);
        $this->edit_to_go = (int) ($this->game->to_go ?? 10);

        $this->notifyPeriodEnd($result['period_event'] ?? null);
    }

    public function cancelPunt(): void
    {
        // Only meaningful if we are currently in punt flow
        if ($this->game->phase !== 'PUNT') {
            return;
        }

        // Return to normal play entry
        $this->game->phase = 'NORMAL';
        $this->game->punt_team = null; // if you store this
        $this->game->save();
        $this->game->refresh();

        // Reset punt UI bits
        $this->punt_recorded = false;
        $this->punt_yards = 40;
        $this->punt_return_yards = 0;

        // Tell Alpine to refocus the Result dropdown
        $this->dispatch('focus-result');
    }

    public function setClock(): void
    {
        $this->validate([
            'edit_clock_min' => 'required|integer|min:0|max:15',
            'edit_clock_sec' => 'required|integer|min:0|max:59',
        ]);

        $this->game->clock = ((int)$this->edit_clock_min * 60) + (int)$this->edit_clock_sec;

        $this->game->save();
        $this->game->refresh();
    }

    public function endOvertimeNow(): void
    {
        if ((int)$this->game->quarter !== 5) {
            return;
        }

        $this->game->phase = 'FINAL';
        $this->game->clock = 0;
        $this->game->save();
        $this->game->refresh();

        $this->dispatch('period-ended', message: 'End of Game');
    }

    public function chooseKickoffTeam(FootballRulesEngine $engine): void
    {
        if (($this->game->phase ?? null) !== 'KICKOFF') {
            return;
        }

        $this->validate([
            'kickoff_kicking_team' => 'required|in:HOME,AWAY',
        ]);

        // Persist who is kicking this kickoff
        $this->game->kick_team = $this->kickoff_kicking_team;
        $this->game->save();

        // Now let engine set any kickoff-state defaults it needs
        $engine->startKickoff($this->game, $this->kickoff_kicking_team);

        $this->game->refresh();

        // Optional: focus return to result panel later, etc.
    }


    public function getStatsProperty(): array
    {
        return app(GameStatService::class)->forGame($this->game);
    }


    public function setFieldScale(int $scale): void
    {
        $allowed = [100, 75, 50, 25];
        $this->fieldScale = in_array($scale, $allowed, true) ? $scale : 100;
    }


    public function render()
    {
        $plays = $this->game->plays()->latest('seq')->take(30)->get()->reverse();
        $plays = $this->game->plays()->latest('seq')->get()->reverse();
        return view('livewire.game-companion', compact('plays'));
    }
}
