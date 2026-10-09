<?php

namespace App\Services\Seasons;

class SeasonOptions
{
    public const QUARTER_LENGTHS = [180 => 3, 300 => 5, 600 => 10, 900 => 15];

    public const SIZES = [4, 8, 12, 16, 24, 28, 32];

    public const PLAYOFFS = ['none' => 'No playoffs', '2' => '2 teams', '4' => '4 teams', '8' => '8 teams', 'nfl10' => 'Historical NFL · 10 teams', 'nfl12' => 'Classic NFL · 12 teams', 'nfl14' => 'Current NFL · 14 teams'];

    public static function lengths(int $count): array
    {
        return $count === 4 ? [3, 6] : [7, 10, 11, 14, 16, 17];
    }

    public static function groups(int $count, string $layout): array
    {
        if ($layout === 'flat') {
            return ['league' => ['conference' => 'League', 'division' => 'All teams', 'size' => $count]];
        }
        $sizes = $layout === 'conferences' ? [$count / 2] : match ($count) {
            4 => [2], 8 => [2, 2], 12 => [3, 3], 16 => [4, 4], 24 => [4, 4, 4], 28 => [5, 5, 4], 32 => [4, 4, 4, 4],
        };
        $groups = [];
        foreach (['A', 'B'] as $conference) {
            foreach ($sizes as $i => $size) {
                $groups[$conference.($i + 1)] = ['conference' => 'Conference '.$conference,
                    'division' => $layout === 'conferences' ? 'All teams' : 'Division '.($i + 1), 'size' => (int) $size];
            }
        }

        return $groups;
    }

    public static function compatible(int $count, string $layout, string $playoffs): bool
    {
        if ($playoffs === 'none') {
            return true;
        }
        if (is_numeric($playoffs)) {
            return (int) $playoffs <= $count;
        }
        $divisions = count(self::groups($count, $layout)) / 2;

        return $layout !== 'flat' && match ($playoffs) {
            'nfl10' => $layout === 'divisions' && $divisions === 3 && $count >= 10,
            'nfl12' => $count >= 12 && $divisions <= 6,
            'nfl14' => $count >= 14 && $layout === 'divisions' && $divisions === 4,
            default => false,
        };
    }
}
