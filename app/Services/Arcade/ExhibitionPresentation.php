<?php

namespace App\Services\Arcade;

use App\Http\Controllers\PracticeController;
use App\Models\Exhibition;
use App\Services\Simulation\CpuCoach;
use App\Services\Simulation\ExhibitionEngine;
use App\Services\Simulation\GameClock;

/** Display-only assembly shared by Coach HTML and the persistent Play read API. */
class ExhibitionPresentation
{
    public function viewData(Exhibition $saved, ?int $replayNumber = null, bool $lineupChanged = false, bool $historicalContext = false): array
    {
        // Normalization and historical display state must not dirty the caller's model.
        $exhibition = clone $saved;
        $exhibition->load(['homeTeam', 'awayTeam', 'seasonFixture.season']);
        $appearance = $exhibition->state['team_appearance'] ?? ['home' => app(PracticeController::class)->appearance($exhibition->homeTeam, 'home'),
            'away' => app(PracticeController::class)->appearance($exhibition->awayTeam, 'away')];
        // Older appearance snapshots predate mascots; add presentation only.
        foreach (['home' => $exhibition->homeTeam, 'away' => $exhibition->awayTeam] as $side => $team) {
            foreach (['mascot', 'cheerleaders', 'stadium'] as $profile) {
                if (! array_key_exists($profile, $appearance[$side])) {
                    $appearance[$side][$profile] = $team->team_settings[$profile] ?? [];
                }
            }
        }
        // STAD1 snapshots retain their original concrete and field-wall colors.
        foreach (['home', 'away'] as $side) {
            if (! isset($appearance[$side]['stadium']['presentation_version'])) {
                foreach (['field_wall_art', 'home_tunnel_art', 'away_tunnel_art', 'home_tunnel_color', 'away_tunnel_color'] as $key) {
                    unset($appearance[$side]['stadium'][$key]);
                }
                $appearance[$side]['stadium']['exterior_color'] = $appearance[$side]['stadium_wall_color'] ?? '#657587';
                $appearance[$side]['stadium']['wordmark_background'] = $appearance[$side]['primary_color'] ?? $appearance[$side]['stadium_seat_color'] ?? '#315b85';
                $appearance[$side]['stadium']['field_wall_color'] = $appearance[$side]['stadium_wall_color'] ?? '#657587';
            }
        }
        $exhibition->state = app(GameClock::class)->normalize($exhibition->state);
        $history = $exhibition->history;
        $last = $history ? $history[array_key_last($history)] : null;
        $replayOnly = $replayNumber !== null;
        if ($replayOnly) {
            $last = collect($history)->firstWhere('number', $replayNumber);
            abort_unless($last && isset($last['animation']), 404, 'This saved replay is unavailable.');
        }
        $highlights = array_values(array_filter($history, \App\Support\PlayHighlights::saved(...)));
        $preview = $last ?? ['before' => $exhibition->state, 'call' => 'inside_run', 'outcome' => 'tackle', 'carrier' => 'RB', 'gain' => 0, 'target' => 0, 'summary' => 'Ready for the snap'];
        $animation = $last['animation'] ?? \App\Services\Simulation\FieldOrientation::animation(app(\App\Services\Simulation\PlayTimeline::class)->build($preview, $exhibition->rosters), $preview['before']);

        if ($replayOnly && $historicalContext) {
            $exhibition->state = $last['after'] ?? $last['before'];
        }

        $calls = ExhibitionEngine::callsForState($exhibition->state);
        $defenseOptions = array_combine(ExhibitionEngine::OFFENSE, array_map(ExhibitionEngine::defensesForCall(...), ExhibitionEngine::OFFENSE));

        $coach = app(CpuCoach::class);
        $controls = $coach->controls($exhibition->state);
        $offenseSide = $exhibition->state['possession'];
        $defenseSide = $offenseSide === 'home' ? 'away' : 'home';
        $cpuOffense = $controls[$offenseSide] === 'cpu';
        $cpuDefense = $controls[$defenseSide] === 'cpu';
        $personnel = app(\App\Services\Simulation\GamePersonnel::class)->active($exhibition->rosters, $exhibition->state);
        $cpuPlan = $cpuOffense ? $coach->offense($exhibition->state, $personnel) : null;
        $coachSuggestion = $coach->offense($exhibition->state, $personnel);
        $coachDefense = $coach->defense($exhibition->state, $coachSuggestion['call']);
        $plannedCall = $cpuPlan['call'] ?? $calls[0];
        $formationPersonnel = [];
        foreach (array_keys(ExhibitionEngine::OFFENSE_FORMATIONS) as $formation) {
            $kickCall = in_array($formation, ['punt', 'field_goal'], true) ? $formation : null;
            $unit = app(\App\Services\Simulation\GamePersonnel::class)->active($exhibition->rosters, $exhibition->state, $kickCall, $formation)[$offenseSide];
            $formationPersonnel[$formation] = array_map(fn ($p) => $p['name'], array_intersect_key($unit['players'], array_flip($unit['offensive_roles'])));
        }
        $humanDefenseOptions = ExhibitionEngine::defensesForCall($plannedCall);

        // Display-only personnel: no resolution, random rolls, clock or state writes.
        $sidelineRoster = [];
        foreach ($exhibition->rosters as $side => $roster) {
            $people = collect($roster['pool'] ?? $roster['players'])->unique('id')->sortBy('id');
            $sidelineRoster[$side] = $people->map(fn ($player) => array_intersect_key($player, array_flip([
                'id', 'name', 'number', 'position', 'depth', 'height_inches', 'weight_pounds', 'skin_tone', 'appearance',
            ])))->values()->all();
        }
        $nextAnimation = null;
        $substitutionChange = false;
        $unitChange = ! $replayOnly && $last && ! ($exhibition->state['penalty_pending'] ?? false)
            && ($last['before']['possession'] !== $exhibition->state['possession']
                || ($last['before']['phase'] ?? 'scrimmage') !== ($exhibition->state['phase'] ?? 'scrimmage')
                || ($last['before']['quarter'] === 2 && $exhibition->state['quarter'] === 3)
                || $exhibition->state['status'] === 'final');
        if (! $replayOnly && ! ($exhibition->state['penalty_pending'] ?? false) && $exhibition->state['status'] !== 'final') {
            $previewFormation = $cpuPlan['formation'] ?? ($last['offense_formation'] ?? 'shotgun');
            if (in_array($previewFormation, ['punt', 'field_goal'], true) && ! in_array($plannedCall, ['punt', 'field_goal', ...\App\Services\Simulation\SpecialTeamsTricks::FAKES], true)) {
                $previewFormation = 'shotgun';
            }
            $nextPreview = ['before' => $exhibition->state, 'call' => $plannedCall, 'outcome' => 'tackle',
                'carrier' => in_array($plannedCall, ['field_goal', 'extra_point', 'kickoff'], true) ? 'K' : ($plannedCall === 'punt' ? 'P' : 'RB'),
                'gain' => 0, 'target' => 0, 'summary' => 'Incoming unit',
                'offense_formation' => $previewFormation,
                'defense_formation' => $coachDefense['formation'] ?? 'base_4_3'];
            $previewState = $exhibition->state;
            if (\App\Services\Simulation\HurryUp::available($previewState) && (($previewState['no_huddle'] ?? false) || ($cpuOffense && $coach->management($previewState)['tempo'] === 'hurry')) && ! in_array($plannedCall, ['punt', 'field_goal', ...\App\Services\Simulation\SpecialTeamsTricks::FAKES], true)) {
                $previewState['_hold_personnel'] = true;
                $previewFormation = $previewState['on_field']['offense_formation'];
                $nextPreview['offense_formation'] = $previewFormation;
                $nextPreview['defense_formation'] = $previewState['on_field']['defense_formation'];
            }
            $nextPersonnel = app(\App\Services\Simulation\GamePersonnel::class)->active($exhibition->rosters, $previewState, $plannedCall, $previewFormation);
            $fake = in_array($plannedCall, \App\Services\Simulation\SpecialTeamsTricks::FAKES, true);
            if ($fake) {
                $nextPersonnel = \App\Services\Simulation\SpecialTeamsTricks::personnel($nextPersonnel, $exhibition->state['possession'], $plannedCall);
                $nextPreview['design'] = $plannedCall;
                $nextPreview['fake_kick'] = true;
                $nextPreview['qb_designed_run'] = str_ends_with($plannedCall, '_run');
                $nextPreview['call'] = \App\Services\Simulation\OffensiveOptions::canonical($plannedCall);
                $nextPreview['carrier'] = str_ends_with($plannedCall, '_run') ? 'QB' : 'TE';
                $nextPreview['receiver_role'] = str_ends_with($plannedCall, '_pass') ? 'TE' : null;
            }
            $nextTimeline = app(\App\Services\Simulation\PlayTimeline::class)->build($nextPreview, $nextPersonnel);
            if (in_array($plannedCall, ['punt', 'field_goal', 'extra_point', 'kickoff', 'onside_kick'], true)) {
                $nextTimeline = app(\App\Services\Simulation\SpecialTeamsTimeline::class)->build($nextPreview + ['landing' => $plannedCall === 'onside_kick' ? min(99, $exhibition->state['spot'] + 12) : $exhibition->state['spot'], 'return_yards' => 0, 'recovery_side' => $defenseSide, 'recovery_role' => 'CB1'], $nextPersonnel);
            }
            $nextAnimation = \App\Services\Simulation\FieldOrientation::animation($nextTimeline, $exhibition->state);
            $keys = fn ($tracks) => collect($tracks)->map(fn ($p) => $p['side'].':'.$p['id'])->sort()->values()->all();
            $substitutionChange = ! $unitChange && ($keys($animation['players']) !== $keys($nextAnimation['players']) || $lineupChanged);
            if (! $unitChange && ! $substitutionChange && ! isset($exhibition->state['on_field'])) {
                $nextAnimation = null;
            }
        }

        $boxScore = app(\App\Services\Simulation\ExhibitionBoxScore::class)->build($historicalContext ? $saved : $exhibition);

        if ($replayOnly) {
            $exhibition->state = $last['after'] ?? $last['before'];
        }

        return compact('replayOnly', 'highlights', 'exhibition', 'appearance', 'animation', 'last', 'calls', 'defenseOptions', 'controls', 'offenseSide', 'defenseSide', 'cpuOffense', 'cpuDefense', 'cpuPlan', 'humanDefenseOptions', 'boxScore', 'personnel', 'coachSuggestion', 'coachDefense', 'sidelineRoster', 'nextAnimation', 'unitChange', 'substitutionChange', 'formationPersonnel');
    }
}
