<?php

return [
    'IR' => [
        'name' => 'Inside Run',
        'roll' => [
            '10' => [
                'player' => 'PENALITY',
                'rating' => 'OFF',
                'skill_pass' => '5',
                'skill_fail' => 'N/A',
            ],
            '11-12' => [
                'player' => 'QB1',
                'rating' => 'RUSH',
                'skill_pass' => '5 + R',
                'skill_fail' => '1',
            ],
            '13-15' => [
                'player' => 'RB1',
                'rating' => 'RUSH',
                'skill_pass' => '3 + R',
                'skill_fail' => '2',
            ],
            '16-17' => [
                'player' => 'OL',
                'rating' => 'RUSH',
                'skill_pass' => '7',
                'skill_fail' => '2',
            ],
            '18-19' => [
                'player' => 'OL',
                'rating' => 'RUSH',
                'skill_pass' => 'B!',
                'skill_fail' => '3',
            ],
            '20-29' => [
                'player' => 'DEF',
                'rating' => 'N/A',
                'skill_pass' => 'N/A',
                'skill_fail' => 'N/A',
            ],
            '30-34' => [
                'player' => 'RB1',
                'rating' => 'POWER',
                'skill_pass' => '2 + R',
                'skill_fail' => '0',
            ],
            '35-36' => [
                'player' => 'RB2',
                'rating' => 'POWER',
                'skill_pass' => '3 + R',
                'skill_fail' => '1',
            ],
            '37-38' => [
                'player' => 'RB3',
                'rating' => 'POWER',
                'skill_pass' => '4 + R',
                'skill_fail' => '-1',
            ],
            '39-41' => [
                'player' => 'OL',
                'rating' => 'POWER',
                'skill_pass' => '3',
                'skill_fail' => '0',
            ],
            '42-45' => [
                'player' => 'OL',
                'rating' => 'POWER',
                'skill_pass' => '5',
                'skill_fail' => '1',
            ],
            '46-49' => [
                'player' => 'DL1',
                'rating' => 'TACKLE',
                'skill_pass' => '-2',
                'skill_fail' => '3',
            ],
            '50-53' => [
                'player' => 'DL2',
                'rating' => 'TACKLE',
                'skill_pass' => '0',
                'skill_fail' => '4',
            ],
            '54-56' => [
                'player' => 'DL3',
                'rating' => 'TACKLE',
                'skill_pass' => '1',
                'skill_fail' => '6',
            ],
            '57-58' => [
                'player' => 'DL4',
                'rating' => 'TACKLE',
                'skill_pass' => '2',
                'skill_fail' => '8',
            ],
            '59-60' => [
                'player' => 'LB1',
                'rating' => 'TACKLE',
                'skill_pass' => '3',
                'skill_fail' => 'B!',
            ],
            '61-62' => [
                'player' => 'LB2',
                'rating' => 'TACKLE',
                'skill_pass' => '3',
                'skill_fail' => 'B!',
            ],
            '63' => [
                'player' => 'LB3',
                'rating' => 'TACKLE',
                'skill_pass' => '3',
                'skill_fail' => 'B!',
            ],
            '64' => [
                'player' => 'S1',
                'rating' => 'TACKLE',
                'skill_pass' => '2',
                'skill_fail' => '11',
            ],
            '65-68' => [
                'player' => 'AUTO',
                'rating' => 'REDZONE',
                'skill_pass' => '4',
                'skill_fail' => '0',
            ],
            '69' => [
                'player' => 'AUTO',
                'rating' => 'HOME',
                'skill_pass' => '8',
                'skill_fail' => '1',
            ],
        ]
    ],
    'OR' => [
        'name' => 'Outside Run',
        'roll' => [
            '10' => [
                'player' => 'PENALITY',
                'rating' => 'OFF',
                'skill_pass' => '5',
                'skill_fail' => 'N/A',
            ],
            '11-15' => [
                'player' => 'RB1',
                'rating' => 'RUSH',
                'skill_pass' => '5 + R',
                'skill_fail' => '0',
            ],
            '16' => [
                'player' => 'RB4',
                'rating' => 'RUSH',
                'skill_pass' => '4 + R',
                'skill_fail' => '-1',
            ],
            '17-20' => [
                'player' => 'OL',
                'rating' => 'RUSH',
                'skill_pass' => 'B!',
                'skill_fail' => '0',
            ],
            '21-24' => [
                'player' => 'OL',
                'rating' => 'RUSH',
                'skill_pass' => '5',
                'skill_fail' => '-2',
            ],
            '25-34' => [
                'player' => 'DEF',
                'rating' => 'N/A',
                'skill_pass' => 'N/A',
                'skill_fail' => 'N/A',
            ],
            '35-38' => [
                'player' => 'RB2',
                'rating' => 'RUSH',
                'skill_pass' => '6 + R',
                'skill_fail' => '0',
            ],
            '39-40' => [
                'player' => 'RB2',
                'rating' => 'RUSH',
                'skill_pass' => '2 + R',
                'skill_fail' => '1',
            ],
            '41-42' => [
                'player' => 'DL1',
                'rating' => 'TACKLE',
                'skill_pass' => '1',
                'skill_fail' => 'B!',
            ],
            '43-44' => [
                'player' => 'DL2',
                'rating' => 'TACKLE',
                'skill_pass' => '-3',
                'skill_fail' => '3',
            ],
            '45-46' => [
                'player' => 'DL3',
                'rating' => 'TACKLE',
                'skill_pass' => '1',
                'skill_fail' => 'B!',
            ],
            '47-48' => [
                'player' => 'DL4',
                'rating' => 'TACKLE',
                'skill_pass' => '0',
                'skill_fail' => '5',
            ],
            '49-51' => [
                'player' => 'LB1',
                'rating' => 'TACKLE',
                'skill_pass' => '-4',
                'skill_fail' => '2',
            ],
            '52-54' => [
                'player' => 'LB2',
                'rating' => 'TACKLE',
                'skill_pass' => '2',
                'skill_fail' => '7',
            ],
            '55-57' => [
                'player' => 'LB3',
                'rating' => 'TACKLE',
                'skill_pass' => '2',
                'skill_fail' => '12',
            ],
            '58-59' => [
                'player' => 'LB4',
                'rating' => 'TACKLE',
                'skill_pass' => '1',
                'skill_fail' => '10',
            ],
            '60-62' => [
                'player' => 'S1',
                'rating' => 'TACKLE',
                'skill_pass' => '-1',
                'skill_fail' => '5',
            ],
            '63' => [
                'player' => 'S2',
                'rating' => 'TACKLE',
                'skill_pass' => '4',
                'skill_fail' => '13!',
            ],
            '64' => [
                'player' => 'LB1',
                'rating' => 'STRIP',
                'skill_pass' => 'FF 2',
                'skill_fail' => '4',
            ],
            '65-68' => [
                'player' => 'AUTO',
                'rating' => 'REDZONE',
                'skill_pass' => '6',
                'skill_fail' => '1',
            ],
            '69' => [
                'player' => 'AUTO',
                'rating' => 'HOME',
                'skill_pass' => '10',
                'skill_fail' => '3',
            ],
        ]
    ],

];
