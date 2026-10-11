<?php

namespace App\Services\Simulation;

use Random\Randomizer;

class ProRosterGenerator extends MiddleEarthRosterGenerator
{
    private const FIRST_NAMES = ['Aaron', 'Adrian', 'Alex', 'Andre', 'Anthony', 'Austin', 'Blake', 'Brandon', 'Bryce', 'Caleb', 'Cameron', 'Carlos', 'Carter', 'Chase', 'Chris', 'Cole', 'Connor', 'Corey', 'Damon', 'Daniel', 'Darius', 'David', 'Devin', 'Dillon', 'Drew', 'Dylan', 'Eli', 'Eric', 'Ethan', 'Felix', 'Gavin', 'Grant', 'Isaac', 'Isaiah', 'Jalen', 'James', 'Jared', 'Jason', 'Jayden', 'Jeremiah', 'Jordan', 'Julian', 'Kai', 'Kevin', 'Landon', 'Leo', 'Logan', 'Lucas', 'Malcolm', 'Marcus', 'Mason', 'Micah', 'Miles', 'Nathan', 'Noah', 'Owen', 'Parker', 'Quentin', 'Reed', 'Ryan', 'Sean', 'Seth', 'Tanner', 'Theo', 'Travis', 'Troy', 'Tyler', 'Victor', 'Wesley', 'Zachary'];

    private const LAST_NAMES = ['Abbott', 'Adams', 'Anderson', 'Archer', 'Armstrong', 'Bailey', 'Baker', 'Banks', 'Bennett', 'Bishop', 'Blair', 'Boone', 'Bradley', 'Brooks', 'Burke', 'Campbell', 'Cannon', 'Carpenter', 'Carter', 'Chambers', 'Clark', 'Coleman', 'Collins', 'Cooper', 'Crawford', 'Cross', 'Dalton', 'Davis', 'Dixon', 'Douglas', 'Ellis', 'Evans', 'Fisher', 'Fletcher', 'Ford', 'Foster', 'Franklin', 'Freeman', 'Garcia', 'Grant', 'Gray', 'Griffin', 'Hall', 'Hamilton', 'Harper', 'Harris', 'Hart', 'Hayes', 'Henderson', 'Hill', 'Holland', 'Howard', 'Hudson', 'Hunter', 'Jackson', 'James', 'Jenkins', 'Johnson', 'Jones', 'Kelly', 'King', 'Knight', 'Lawson', 'Lewis', 'Marshall', 'Martin', 'Mason', 'Miller', 'Mitchell', 'Morgan', 'Nelson', 'Parker', 'Perry', 'Porter', 'Powell', 'Price', 'Reed', 'Reynolds', 'Richards', 'Rivera', 'Roberts', 'Robinson', 'Sanders', 'Scott', 'Shaw', 'Simmons', 'Smith', 'Spencer', 'Stevens', 'Stewart', 'Stone', 'Sullivan', 'Taylor', 'Thomas', 'Thompson', 'Turner', 'Walker', 'Wallace', 'Ward', 'Watson', 'Wells', 'West', 'White', 'Williams', 'Wilson', 'Woods', 'Wright', 'Young'];

    /** Full names already allocated elsewhere in this generated league. */
    public function generate(int $seed, array $usedNames = []): array
    {
        $roster = parent::generate($seed);
        // Separate streams keep identity/number changes independent of ratings and depth.
        $random = new Randomizer(new \Random\Engine\Mt19937($seed));
        $used = array_fill_keys($usedNames, true);
        $available = [];
        foreach (self::FIRST_NAMES as $first) {
            foreach (self::LAST_NAMES as $last) {
                if (! isset($used[$first.' '.$last])) {
                    $available[] = [$first, $last];
                }
            }
        }
        if (count($available) < count($roster)) {
            throw new \LogicException('Not enough unused names to generate a Pro roster.');
        }
        $available = $random->shuffleArray($available);
        foreach ($roster as $i => &$entry) {
            [$entry['player']['firstname'], $entry['player']['lastname']] = $available[$i];
        }
        unset($entry);
        $numbers = new Randomizer(new \Random\Engine\Mt19937($seed ^ 0x4e554d));
        $taken = [];
        // Allocate narrow ranges first, leaving ample choices for flexible positions.
        foreach (['QB', 'OL', 'DL', 'CB', 'S', 'RB', 'WR', 'TE', 'LB', 'K', 'P'] as $position) {
            foreach ($roster as &$entry) {
                if ($entry['roster']['position'] !== $position) {
                    continue;
                }
                $choices = array_values(array_diff(self::legalNumbers($position), array_keys($taken)));
                if ($choices === []) {
                    throw new \LogicException('No legal jersey number remains for '.$position.'.');
                }
                $number = $choices[$numbers->getInt(0, count($choices) - 1)];
                $entry['roster']['jersey_number'] = $number;
                $taken[$number] = true;
            }
            unset($entry);
        }

        return $roster;
    }

    /** NFL 2026 Rule 5, Section 1, Article 2. */
    public static function legalNumbers(string $position): array
    {
        return match ($position) {
            'QB' => range(0, 19),
            'K', 'P' => array_merge(range(0, 49), range(90, 99)),
            'CB', 'S' => range(0, 49),
            'RB', 'WR', 'TE' => array_merge(range(0, 49), range(80, 89)),
            'OL' => range(50, 79),
            'DL' => array_merge(range(50, 79), range(90, 99)),
            'LB' => array_merge(range(0, 59), range(90, 99)),
            default => throw new \InvalidArgumentException('Unknown Pro position: '.$position),
        };
    }

    protected function name(Randomizer $random): array
    {
        return [self::FIRST_NAMES[$random->getInt(0, count(self::FIRST_NAMES) - 1)], self::LAST_NAMES[$random->getInt(0, count(self::LAST_NAMES) - 1)]];
    }
}
