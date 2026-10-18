<?php

namespace App\Services\Simulation;

class PlayResultPlayers
{
    /** Cards follow actual saved participants and the same roles used by the play summary. */
    public function forPlay(array $rosters, array $play): array
    {
        if ($play['no_snap'] ?? false) {
            return [];
        }
        $rosters = app(SavedPlayPersonnel::class)->rosters($rosters, $play);
        $side = $play['before']['possession'] ?? 'home';
        $other = $side === 'home' ? 'away' : 'home';
        $call = $play['call'] ?? '';
        $outcome = $play['outcome'] ?? '';
        $carrier = $play['carrier'] ?? 'RB';
        $target = $play['receiver_role'] ?? null;
        $cards = [];
        $add = function ($side, $role, $label) use (&$cards, $rosters) {
            $person = $rosters[$side]['players'][$role] ?? null;
            if (! $person) {
                return;
            }
            foreach ($cards as $card) {
                if ($card['side'] === $side && ($card['player']['id'] ?? $card['role']) === ($person['id'] ?? $role)) {
                    return;
                }
            }
            $cards[] = ['player' => $person, 'side' => $side, 'role' => $role, 'label' => $label];
        };
        if (in_array($call, ['field_goal', 'extra_point', 'punt', 'kickoff', 'onside_kick'], true) && empty($play['fake_kick'])) {
            $add($side, $call === 'punt' ? 'P' : 'K', $call === 'punt' ? 'Punter' : 'Kicker');
        } elseif ($outcome === 'incomplete') {
            $add($side, 'QB', 'Quarterback');
            if ($target !== null && empty($play['throwaway'])) {
                $add($side, $target, 'Intended receiver');
            }
        } elseif ($outcome === 'sack' || ! empty($play['scramble']) || ($carrier === 'QB' && $outcome === 'fumble')) {
            $add($side, 'QB', 'Quarterback');
            $add($other, 'LB2', $outcome === 'sack' ? 'Sack' : ($outcome === 'fumble' ? 'Recovery' : 'Tackler'));
        } elseif ($target !== null && $carrier !== 'QB') {
            $add($side, 'QB', 'Quarterback');
            if (empty($play['throwaway'])) {
                $add($side, $target, $outcome === 'incomplete' || $outcome === 'interception' ? 'Intended receiver' : 'Receiver');
            }
            if ($outcome === 'interception') {
                $add($other, 'CB1', 'Interception');
            } elseif ($outcome === 'fumble') {
                $add($other, 'CB1', 'Recovery');
            } elseif (! in_array($outcome, ['incomplete', 'touchdown'], true)) {
                $add($other, 'CB1', 'Tackler');
            }
        } else {
            $add($side, $carrier, 'Ball carrier');
            if (! in_array($outcome, ['touchdown', 'kneel', 'spike'], true)) {
                $add($other, 'LB2', $outcome === 'fumble' ? 'Recovery' : 'Tackler');
            }
        }

        return $cards;
    }
}
