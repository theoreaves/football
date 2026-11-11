<?php

namespace App\Services\Arcade;

use App\Models\Exhibition;
use App\Services\Simulation\FieldOrientation;
use Illuminate\Http\Request;

/** Session-only presentation handoff; never writes football state or history. */
class FlowNotices
{
    public function remember(Request $request, Exhibition $game, array $result): void
    {
        if ($request->input('action') !== 'fast' || ! $request->hasSession() || ! $result['plays']
            || ($result['receipt']['version'] ?? null) !== $result['state']['version']) {
            return;
        }
        $notices = [];
        foreach ($result['plays'] as $play) {
            $before = $play['before'] ?? [];
            $after = $play['after'] ?? [];
            if (isset($before['quarter'], $after['quarter']) && $before['quarter'] !== $after['quarter']) {
                $label = match (true) {
                    $before['quarter'] === 2 && $after['quarter'] === 3 => 'Halftime completed. Quarter 3 begins.',
                    $before['quarter'] === 4 && $after['quarter'] >= 5 => 'Regulation ended. Overtime begins.',
                    default => "Quarter {$before['quarter']} → {$after['quarter']}.",
                };
                $notices[] = ['type' => 'quarter', 'message' => $label];
            }
            if (isset($before['possession'], $after['possession']) && $before['possession'] !== $after['possession']) {
                $notices[] = ['type' => 'possession', 'message' => ucfirst($before['possession']).' → '.ucfirst($after['possession']).' possession.'];
            }
            if (isset($before['quarter'], $before['possession'], $after['quarter'], $after['possession']) && FieldOrientation::direction($before) !== FieldOrientation::direction($after)) {
                $notices[] = ['type' => 'direction', 'message' => 'The offense changed field direction.'];
            }
            if (isset($play['penalty']) || isset($play['nullified_summary']) || ($play['no_snap'] ?? false)
                || ($before['home_score'] ?? 0) !== ($after['home_score'] ?? 0)
                || ($before['away_score'] ?? 0) !== ($after['away_score'] ?? 0)
                || ($after['status'] ?? '') === 'final') {
                $notices[] = ['type' => 'result', 'message' => $play['summary']];
            }
        }
        $request->session()->put($this->key($game), ['version' => $result['state']['version'], 'notices' => $notices]);
    }

    public function consume(Request $request, Exhibition $game, array $flow): array
    {
        if (! $request->hasSession()) {
            return $flow;
        }
        $saved = $request->session()->pull($this->key($game));
        if ($saved && $saved['version'] === $game->state['version']) {
            $flow['notices'] = array_merge($saved['notices'], $flow['notices']);
        }

        return $flow;
    }

    private function key(Exhibition $game): string
    {
        return 'arcade_flow_notices.'.$game->world_id.'.'.$game->id;
    }
}
