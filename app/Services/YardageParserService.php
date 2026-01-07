<?php

declare(strict_types=1);

namespace App\Services;

class YardageParserService
{
    /**
     * Parse the yards from a result string, optionally using the player's skill value.
     *
     * @param string $result The result string (e.g., skill_pass or skill_fail)
     * @param int|null $playerSkill The player's skill value (for "+ R" cases)
     * @return int|string|null
     */
    public static function parseYards(string $result, ?int $skillRoll = null): int|string|null
    {
        if (stripos($result, 'B!') !== false) {
            $number = (int)filter_var($result, FILTER_SANITIZE_NUMBER_INT);
            if ($number === 0) {
                return 'B!';
            }
            return $number . ' B!';
        }

        if (stripos($result, 'INC') !== false) {
            return 0;
        }

        if (stripos($result, 'SK') !== false) {
            if (preg_match('/(-?\d+)/', $result, $matches)) {
                return -(int)$matches[1];
            }
            return null;
        }

        if (stripos($result, 'INT') !== false) {
            if (preg_match('/(-?\d+)/', $result, $matches)) {
                return (int)$matches[1];
            }
            return null;
        }

        if (stripos($result, ' + R') !== false) {
            $number = (int)filter_var($result, FILTER_SANITIZE_NUMBER_INT);
            if ($skillRoll !== null) {
                return $number + $skillRoll;
            }

        }

        if (preg_match('/(-?\d+)/', $result, $matches)) {
            return (int)$matches[1];
        }

        return null;
    }
}

