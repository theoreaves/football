<?php

namespace App\Services\Seasons;

class ScheduleGenerator
{
    public function generate(array $members, int $games, bool $bye): array
    {
        $ids = array_map('intval', array_keys($members));
        $ring = $ids;
        $rounds = [];
        for ($r = 0; $r < count($ids) - 1; $r++) {
            $pairs = [];
            for ($i = 0; $i < count($ids) / 2; $i++) {
                $pairs[] = [$ring[$i], $ring[count($ids) - 1 - $i]];
            }
            $rounds[] = $pairs;
            $last = array_pop($ring);
            array_splice($ring, 1, 0, [$last]);
        }
        // Prefer division/conference matchups without repeating an opponent until a cycle completes.
        usort($rounds, function ($a, $b) use ($members) {
            $score = fn ($round) => array_sum(array_map(fn ($pair) => ($members[$pair[0]]['group'] === $members[$pair[1]]['group'] ? 10 : 0)
                + ($members[$pair[0]]['conference'] === $members[$pair[1]]['conference'] ? 1 : 0), $round));

            return $score($b) <=> $score($a);
        });
        $fixtures = [];
        $byeRound = (int) floor($games / 2);
        for ($r = 0; $r < $games; $r++) {
            foreach ($rounds[$r % count($rounds)] as $i => [$a, $b]) {
                $week = $r + 1 + ($bye && $r > $byeRound ? 1 : 0);
                if ($bye && $r === $byeRound && $i >= count($ids) / 4) {
                    $week++;
                }
                $fixtures[] = ['week' => $week, 'home_team_id' => $a, 'away_team_id' => $b];
            }
        }
        // Orient an Euler circuit, using a dummy vertex for odd degrees, to balance home/away.
        $edges = $fixtures;
        if ($games % 2) {
            foreach ($ids as $id) {
                $edges[] = ['home_team_id' => $id, 'away_team_id' => 0];
            }
        }
        $adj = [];
        foreach ($edges as $index => $edge) {
            $adj[$edge['home_team_id']][] = [$index, $edge['away_team_id']];
            $adj[$edge['away_team_id']][] = [$index, $edge['home_team_id']];
        }
        $used = [];
        foreach (array_keys($adj) as $start) {
            $stack = [$start];
            while ($stack) {
                $at = $stack[array_key_last($stack)];
                while (! empty($adj[$at]) && isset($used[$adj[$at][array_key_last($adj[$at])][0]])) {
                    array_pop($adj[$at]);
                }
                if (empty($adj[$at])) {
                    array_pop($stack);

                    continue;
                }
                [$index, $to] = array_pop($adj[$at]);
                $used[$index] = true;
                if ($index < count($fixtures)) {
                    $fixtures[$index]['home_team_id'] = $at;
                    $fixtures[$index]['away_team_id'] = $to;
                }
                $stack[] = $to;
            }
        }

        return $fixtures;
    }
}
