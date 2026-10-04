<?php

namespace App\Services\Simulation;

use Random\Engine\Mt19937;
use Random\Randomizer;

class MiddleEarthRosterGenerator
{
    private const POSITIONS = ['QB' => 2, 'RB' => 4, 'WR' => 6, 'TE' => 3, 'OL' => 10, 'DL' => 9, 'LB' => 6, 'CB' => 7, 'S' => 4, 'K' => 1, 'P' => 1];

    private const SPECIALTIES = [
        'QB' => ['throwing', 'awareness', 'ball_security'], 'RB' => ['speed', 'acceleration', 'ball_security'],
        'WR' => ['speed', 'acceleration', 'catching'], 'TE' => ['catching', 'blocking', 'strength'],
        'OL' => ['blocking', 'strength', 'awareness'], 'DL' => ['tackling', 'strength', 'acceleration'],
        'LB' => ['tackling', 'awareness', 'strength'], 'CB' => ['coverage', 'speed', 'acceleration'],
        'S' => ['coverage', 'tackling', 'awareness'], 'K' => ['kicking', 'awareness'], 'P' => ['kicking', 'awareness'],
    ];

    private const NAMES = [
        [['Ar', 'Bar', 'Bor', 'Dar', 'El', 'Far', 'Hal', 'Thor'], ['adan', 'amir', 'anor', 'ath', 'born', 'dil', 'ion', 'ond'], ['Stone', 'Iron', 'Silver', 'Oak', 'Grey', 'Storm', 'Long', 'North'], ['ward', 'helm', 'hand', 'shield', 'brook', 'field', 'wood', 'heart']],
        [['Ae', 'Cele', 'Ela', 'Fae', 'Gala', 'Lau', 'Lin', 'Sil'], ['dir', 'dor', 'ion', 'las', 'nor', 'riel', 'rion', 'thir'], ['Star', 'Moon', 'Dawn', 'Leaf', 'River', 'Sun', 'Mist', 'Light'], ['song', 'weaver', 'whisper', 'bloom', 'glade', 'wind', 'watch', 'vale']],
        [['Bal', 'Bro', 'Dor', 'Dwa', 'Gar', 'Gro', 'Nor', 'Tho'], ['din', 'drin', 'gar', 'grom', 'in', 'li', 'rim', 'rin'], ['Iron', 'Stone', 'Copper', 'Deep', 'Flint', 'Forge', 'Gold', 'Mountain'], ['beard', 'fist', 'hammer', 'delver', 'axe', 'anvil', 'pick', 'mantle']],
        [['Bram', 'Bil', 'Cot', 'Dro', 'Fer', 'Hob', 'Meri', 'Tob'], ['bo', 'din', 'do', 'ford', 'go', 'kin', 'lo', 'wick'], ['Green', 'Apple', 'Under', 'Moss', 'Bramble', 'Clover', 'Heather', 'Small'], ['hill', 'foot', 'burrow', 'bottom', 'bank', 'meadow', 'garden', 'hedge']],
        [['Az', 'Bol', 'Dur', 'Gor', 'Grish', 'Kra', 'Lug', 'Urz'], ['bag', 'dush', 'gash', 'goth', 'luk', 'nak', 'rat', 'ug'], ['Black', 'Red', 'Ash', 'Night', 'Grim', 'Fire', 'Dark', 'Skull'], ['fang', 'claw', 'scar', 'blade', 'crusher', 'maw', 'brand', 'breaker']],
    ];

    public function generate(int $seed): array
    {
        $random = new Randomizer(new Mt19937($seed));
        $names = [];
        $roster = [];
        $jersey = 1;
        foreach (self::POSITIONS as $position => $count) {
            $players = [];
            for ($i = 0; $i < $count; $i++) {
                do {
                    $parts = self::NAMES[$random->getInt(0, count(self::NAMES) - 1)];
                    $name = array_map(fn ($pool) => $pool[$random->getInt(0, count($pool) - 1)], $parts);
                    $first = $name[0].$name[1];
                    $last = $name[2].$name[3];
                } while (isset($names[$first.' '.$last]));
                $names[$first.' '.$last] = true;
                $talent = intdiv($random->getInt(40, 88) + $random->getInt(40, 88), 2);
                $ratings = [];
                foreach (PlayerRatings::FIELDS as $field) {
                    $specialty = in_array($field, self::SPECIALTIES[$position], true);
                    $ratings[$field] = max(10, min(99, $talent + ($specialty ? 12 : -18) + $random->getInt(-12, 12)));
                }
                $players[] = ['firstname' => $first, 'lastname' => $last, 'age' => $random->getInt(21, 34), 'position' => $position, 'simulation_ratings' => $ratings];
            }
            usort($players, fn ($a, $b) => array_sum(array_intersect_key($b['simulation_ratings'], array_flip(self::SPECIALTIES[$position]))) <=> array_sum(array_intersect_key($a['simulation_ratings'], array_flip(self::SPECIALTIES[$position]))));
            foreach ($players as $i => $player) {
                $slot = $position.($i + 1);
                $roster[] = ['player' => array_merge($player, $this->legacyRatings($player['simulation_ratings'])), 'roster' => array_merge([
                    'position' => $position, 'depth_chart_position' => $slot, 'jersey_number' => $jersey++,
                    'kick_return_depth_chart_position' => '', 'punt_return_depth_chart_position' => '',
                ], $this->ranges($slot))];
            }
        }

        return $roster;
    }

    private function legacyRatings(array $ratings): array
    {
        $mapping = [
            'speed' => 'speed', 'pass_evade' => 'acceleration', 'pass_accuracy' => 'throwing', 'pass_deep' => 'throwing', 'pass_control' => 'awareness',
            'rush' => 'acceleration', 'rush_power' => 'strength', 'receive' => 'catching', 'receive_deep' => 'catching',
            'tackle' => 'tackling', 'sack' => 'strength', 'cover' => 'coverage', 'interception' => 'coverage', 'strip' => 'tackling',
            'kick30' => 'kicking', 'kick39' => 'kicking', 'kick49' => 'kicking', 'kick50' => 'kicking',
            'punt_distance' => 'kicking', 'punt_pooch' => 'kicking', 'punt_block' => 'awareness', 'return_yards' => 'speed', 'return_speed' => 'acceleration',
        ];
        $legacy = [];
        foreach ($mapping as $field => $rating) {
            $legacy[$field] = max(1, min(9, (int) round($ratings[$rating] / 11)));
        }
        $legacy['fumble'] = $legacy['return_fumble'] = max(1, min(9, (int) round((100 - $ratings['ball_security']) / 11)));
        $legacy['punt_pooch_yard'] = 50;

        return $legacy;
    }

    private function ranges(string $slot): array
    {
        $groups = [
            'catch' => ['RB1', 'RB2', 'TE1', 'TE2', 'WR1', 'WR2', 'WR3', 'WR4'],
            'catch_plus' => ['TE1', 'TE2', 'WR1', 'WR2', 'WR3', 'WR4'], 'rush' => ['QB1', 'RB1', 'RB2', 'RB3', 'RB4'],
            'sack' => ['DL1', 'DL2', 'DL3', 'DL4', 'LB1', 'LB2', 'LB3', 'LB4'],
            'interception' => ['LB1', 'LB2', 'LB3', 'LB4', 'CB1', 'CB2', 'S1', 'S2'],
            'tackle' => ['DL1', 'DL2', 'DL3', 'DL4', 'LB1', 'LB2', 'LB3', 'LB4', 'CB1', 'CB2', 'S1', 'S2'],
            'kick' => ['WR1', 'WR2', 'RB2'], 'punt' => ['WR1', 'WR2', 'RB2'],
        ];
        $ranges = [];
        foreach ($groups as $prefix => $slots) {
            $index = array_search($slot, $slots, true);
            $ranges[$prefix.'_from'] = $index === false ? 0 : intdiv($index * 20, count($slots)) + 1;
            $ranges[$prefix.'_to'] = $index === false ? 0 : intdiv(($index + 1) * 20, count($slots));
            if ($index !== false && in_array($prefix, ['kick', 'punt'], true)) {
                $ranges[$prefix === 'kick' ? 'kick_return_depth_chart_position' : 'punt_return_depth_chart_position'] = ($prefix === 'kick' ? 'KR' : 'PR').($index + 1);
            }
        }

        return $ranges;
    }
}
