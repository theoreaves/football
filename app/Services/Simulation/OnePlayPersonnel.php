<?php

namespace App\Services\Simulation;

use Illuminate\Validation\ValidationException;

class OnePlayPersonnel
{
    public static function rules(): array
    {
        $roles = implode(',', array_keys(RosterBuilder::GROUPS));

        return ['one_play' => ['sometimes', 'array:home,away'],
            'one_play.home' => ['sometimes', 'array:'.$roles], 'one_play.away' => ['sometimes', 'array:'.$roles],
            'one_play.*.*' => ['nullable', 'integer', 'min:1']];
    }

    public function supported(array $state, string $call): bool
    {
        return ($state['phase'] ?? '') === 'scrimmage'
            && ! in_array($call, ['punt', 'field_goal', 'kickoff', 'onside_kick', 'extra_point', ...SpecialTeamsTricks::FAKES], true);
    }

    public function roles(array $roster, bool $offense, string $formation): array
    {
        if ($offense) {
            return $roster['offensive_roles'] ?? array_merge(PersonnelRotation::BASE, PersonnelRotation::LINE);
        }
        $roles = ['DE1', 'DT1', 'DT2', 'DE2', 'LB1', 'LB2', 'LB3', 'CB1', 'CB2', 'S1', 'S2'];
        $swaps = match ($formation) {
            'base_3_5' => ['DT2' => 'LB4', 'S2' => 'LB5'], 'base_3_4' => ['DT2' => 'LB4'],
            'nickel' => ['LB3' => 'CB3'], 'dime' => ['LB3' => 'CB3', 'LB2' => 'CB4'], default => [],
        };
        foreach ($swaps as $old => $new) {
            if (isset($roster['players'][$new])) {
                $roles[array_search($old, $roles, true)] = $new;
            }
        }

        return $roles;
    }

    public function unavailable(array $state, string $side, int $id): ?string
    {
        $hurt = $state['injuries'][$side][$id] ?? null;
        if ($hurt && ($hurt['return_snap'] === null || ($state['personnel_snaps'] ?? 0) < $hurt['return_snap'])) {
            return 'Injured';
        }

        return ($state['fatigue'][$side][$id] ?? 0) >= 65 ? 'Needs rest' : null;
    }

    public function apply(array $state, array $rosters, array $assignments, string $call, string $offenseFormation, string $defenseFormation): array
    {
        $assignments = array_map(fn ($roles) => array_map('intval', array_filter($roles, fn ($id) => $id !== null && $id !== '')), $assignments);
        $assignments = array_filter($assignments);
        if (! $assignments) {
            return $state;
        }
        $fail = fn ($message) => throw ValidationException::withMessages(['one_play' => $message]);
        if (! $this->supported($state, $call)) {
            $fail('One-play replacements are available for ordinary scrimmage plays.');
        }
        $units = app(GamePersonnel::class)->active($rosters, $state, $call, $offenseFormation);
        $controls = app(CpuCoach::class)->controls($state);
        foreach ($assignments as $side => $choices) {
            if (($controls[$side] ?? 'cpu') !== 'human') {
                $fail('You can replace players only on a human-controlled team.');
            }
            $roles = $this->roles($units[$side], $side === $state['possession'], $side === $state['possession'] ? $offenseFormation : $defenseFormation);
            if (array_diff(array_keys($choices), $roles)) {
                $fail('Choose positions used by this formation.');
            }
            if (count($choices) !== count(array_unique($choices))) {
                $fail('A player cannot fill two positions on the same play.');
            }
            foreach ($choices as $role => $id) {
                if (! collect($rosters[$side]['pool'] ?? [])->contains('id', $id)) {
                    $fail('Choose a player from this game’s captured roster.');
                }
                if ($reason = $this->unavailable($state, $side, $id)) {
                    $fail($reason.': choose an available replacement.');
                }
            }
        }
        $state['_snap_lineup'] = $assignments;
        $units = app(GamePersonnel::class)->active($rosters, $state, $call, $offenseFormation);
        foreach ($assignments as $side => $choices) {
            foreach ($choices as $role => $id) {
                if (($units[$side]['players'][$role]['id'] ?? null) !== $id) {
                    $fail('This combination cannot field the selected players. Choose another replacement.');
                }
            }
        }

        return $state;
    }
}
