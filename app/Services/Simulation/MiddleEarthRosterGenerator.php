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
                $roster[] = ['player' => $player, 'roster' => ['position' => $position, 'depth_chart_position' => $slot, 'jersey_number' => $jersey++]];
            }
        }

        return $roster;
    }
}
