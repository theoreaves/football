<?php

namespace App\Services\Simulation;

use Random\Randomizer;

class ProRosterGenerator extends MiddleEarthRosterGenerator
{
    private const FIRST_NAMES = ['Aaron', 'Adrian', 'Alex', 'Andre', 'Anthony', 'Austin', 'Blake', 'Brandon', 'Bryce', 'Caleb', 'Cameron', 'Carlos', 'Carter', 'Chase', 'Chris', 'Cole', 'Connor', 'Corey', 'Damon', 'Daniel', 'Darius', 'David', 'Devin', 'Dillon', 'Drew', 'Dylan', 'Eli', 'Eric', 'Ethan', 'Felix', 'Gavin', 'Grant', 'Isaac', 'Isaiah', 'Jalen', 'James', 'Jared', 'Jason', 'Jayden', 'Jeremiah', 'Jordan', 'Julian', 'Kai', 'Kevin', 'Landon', 'Leo', 'Logan', 'Lucas', 'Malcolm', 'Marcus', 'Mason', 'Micah', 'Miles', 'Nathan', 'Noah', 'Owen', 'Parker', 'Quentin', 'Reed', 'Ryan', 'Sean', 'Seth', 'Tanner', 'Theo', 'Travis', 'Troy', 'Tyler', 'Victor', 'Wesley', 'Zachary'];

    private const LAST_NAMES = ['Abbott', 'Adams', 'Anderson', 'Archer', 'Armstrong', 'Bailey', 'Baker', 'Banks', 'Bennett', 'Bishop', 'Blair', 'Boone', 'Bradley', 'Brooks', 'Burke', 'Campbell', 'Cannon', 'Carpenter', 'Carter', 'Chambers', 'Clark', 'Coleman', 'Collins', 'Cooper', 'Crawford', 'Cross', 'Dalton', 'Davis', 'Dixon', 'Douglas', 'Ellis', 'Evans', 'Fisher', 'Fletcher', 'Ford', 'Foster', 'Franklin', 'Freeman', 'Garcia', 'Grant', 'Gray', 'Griffin', 'Hall', 'Hamilton', 'Harper', 'Harris', 'Hart', 'Hayes', 'Henderson', 'Hill', 'Holland', 'Howard', 'Hudson', 'Hunter', 'Jackson', 'James', 'Jenkins', 'Johnson', 'Jones', 'Kelly', 'King', 'Knight', 'Lawson', 'Lewis', 'Marshall', 'Martin', 'Mason', 'Miller', 'Mitchell', 'Morgan', 'Nelson', 'Parker', 'Perry', 'Porter', 'Powell', 'Price', 'Reed', 'Reynolds', 'Richards', 'Rivera', 'Roberts', 'Robinson', 'Sanders', 'Scott', 'Shaw', 'Simmons', 'Smith', 'Spencer', 'Stevens', 'Stewart', 'Stone', 'Sullivan', 'Taylor', 'Thomas', 'Thompson', 'Turner', 'Walker', 'Wallace', 'Ward', 'Watson', 'Wells', 'West', 'White', 'Williams', 'Wilson', 'Woods', 'Wright', 'Young'];

    protected function name(Randomizer $random): array
    {
        return [self::FIRST_NAMES[$random->getInt(0, count(self::FIRST_NAMES) - 1)], self::LAST_NAMES[$random->getInt(0, count(self::LAST_NAMES) - 1)]];
    }
}
