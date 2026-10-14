<?php

namespace App\Services\Simulation;

use Illuminate\Validation\ValidationException;

class TeamPlays
{
    public const LIMIT = 40;

    public static function catalogue(): array
    {
        $offense = [];
        foreach (OffensiveOptions::FORMATIONS as $formation => $calls) {
            if (in_array($formation, ['punt', 'field_goal'], true)) {
                continue;
            }
            $offense[$formation] = ['label' => ExhibitionEngine::OFFENSE_FORMATIONS[$formation], 'calls' => array_merge($calls, ['kneel', 'spike']),
                'roles' => array_merge(PersonnelRotation::PACKAGES[$formation] ?? PersonnelRotation::BASE, PersonnelRotation::LINE)];
        }
        $defense = [];
        foreach (ExhibitionEngine::DEFENSE_FORMATIONS as $formation => $label) {
            $roster = ['players' => array_fill_keys(array_keys(RosterBuilder::GROUPS), [])];
            $defense[$formation] = ['label' => $label, 'calls' => ['man_to_man', 'zone'],
                'roles' => app(OnePlayPersonnel::class)->roles($roster, false, $formation)];
        }

        return ['offense' => $offense, 'defense' => $defense];
    }

    public static function rules(): array
    {
        return ['team_play' => ['sometimes', 'array:home,away'],
            'team_play.home' => ['nullable', 'uuid'], 'team_play.away' => ['nullable', 'uuid']];
    }

    /** Merge a named package and explicit one-play replacements without changing either. */
    public function prepare(array $state, array $rosters, array $selected, array $overrides, array $offense, array $defense): array
    {
        $assignments = $metadata = $notices = [];
        $units = app(GamePersonnel::class)->active($rosters, $state, $offense['call'], $offense['formation']);
        $service = app(OnePlayPersonnel::class);
        $controls = app(CpuCoach::class)->controls($state);
        foreach (array_filter($selected) as $side => $id) {
            $definition = $rosters[$side]['team_plays'][$id] ?? null;
            $kind = $side === $state['possession'] ? 'offense' : 'defense';
            $plan = $kind === 'offense' ? $offense : $defense;
            if (! $definition || ($controls[$side] ?? 'cpu') !== 'human' || $definition['kind'] !== $kind
                || $definition['formation'] !== $plan['formation'] || $definition['call'] !== $plan['call']
                || ($kind === 'defense' && (($definition['expect'] ?? 'balanced') !== ($plan['expect'] ?? 'balanced') || (bool) ($definition['blitz'] ?? false) !== (bool) ($plan['blitz'] ?? false)))
                || ! $service->supported($state, $offense['call'])) {
                throw ValidationException::withMessages(['team_play' => 'Choose a team play offered for this team, formation and call.']);
            }
            $roles = $service->roles($units[$side], $kind === 'offense', $plan['formation']);
            foreach ($definition['personnel'] ?? [] as $role => $playerId) {
                $player = collect($rosters[$side]['pool'] ?? [])->firstWhere('id', (int) $playerId);
                $reason = ! $player ? 'not in this game roster' : ($service->unavailable($state, $side, (int) $playerId) ?? (! in_array($role, $roles, true) ? 'not used by this captured formation' : null));
                if ($reason) {
                    $notices[] = $definition['name'].' · '.$role.': Automatic ('.$reason.')';
                } else {
                    $assignments[$side][$role] = (int) $playerId;
                }
            }
            $metadata[$side] = ['id' => $id, 'name' => $definition['name'], 'kind' => $kind,
                'formation' => $definition['formation'], 'call' => $definition['call']];
        }
        foreach ($overrides as $side => $roles) {
            $assignments[$side] = array_replace($assignments[$side] ?? [], array_filter($roles, fn ($id) => $id !== null && $id !== ''));
        }
        // An explicit replacement can move a player out of a named package's other role.
        foreach ($overrides as $side => $roles) {
            foreach (array_filter($roles) as $role => $id) {
                foreach ($assignments[$side] ?? [] as $other => $assigned) {
                    if ($other !== $role && (int) $assigned === (int) $id && empty($roles[$other])) {
                        unset($assignments[$side][$other]);
                    }
                }
            }
        }

        return ['state' => $service->apply($state, $rosters, $assignments, $offense['call'], $offense['formation'], $defense['formation']),
            'metadata' => $metadata, 'notices' => $notices];
    }

    public function annotate(array $result, array $prepared): array
    {
        if ($prepared['metadata']) {
            $result['play']['team_plays'] = $prepared['metadata'];
            $result['play']['summary'] = implode(' / ', array_column($prepared['metadata'], 'name')).' · '.$result['play']['summary'];
        }
        if ($prepared['notices']) {
            $result['play']['team_play_notices'] = $prepared['notices'];
        }
        foreach ($result['play']['penalty_options'] ?? [] as $key => $option) {
            $result['play']['penalty_options'][$key] = $this->annotate($option, $prepared);
        }

        return $result;
    }
}
