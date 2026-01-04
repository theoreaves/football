<?php

return [
    'kickoffs' => [
        '0-24' => [
            'kick' => 'red + white',
            'return' => '10 + blue',
        ],
        '25-31' => [
            'kick' => 'white',
            'return' => 'B!',
        ],
        '32-56' => [
            'kick' => 'white',
            'return' => '10 + blue + KR',
        ],
        '57-59' => [
            'kick' => 'white - 5',
            'return' => '10 + blue + KR',
        ],
        '60' => [
            'kick' => 'OB',
            'return' => '40YL',
        ],
        '61-69' => [
            'kick' => 'white EZ',
            'return' => '10 + B!',
        ],
        '70-1000' => [
            'kick' => 'TB',
            'return' => 'TB',
        ],
    ],
    'field_goals' => [
        '0-19' => '9',
        '20-22' => '10',
        '23-25' => '11',
        '26-29' => '12',
        '30-34' => '13',
        '35-39' => '14',
        '40-43' => '15',
        '44-46' => '16',
        '47-49' => '17',
        '50-52' => '18',
        '53-55' => '19',
        '56-57' => '20',
        '58-59' => '21',
        '60-61' => '22',
        '62-63' => '23',
        '64' => '24',
        '65' => '25',
    ],
    'punts' => [
        '10' => [
            'distance' => '25',
            'type' => 'OOB',
        ],
        '11' => [
            'distance' => '28',
            'type' => 'FC',
        ],
        '12-13' => [
            'distance' => '30',
            'type' => 'C',
        ],
        '14-15' => [
            'distance' => '31',
            'type' => 'OOB',
        ],
        '16-18' => [
            'distance' => '33',
            'type' => 'C',
        ],
        '19' => [
            'distance' => '34',
            'type' => 'D',
        ],
        '20-24' => [
            'distance' => '35',
            'type' => 'C',
        ],
        '25-26' => [
            'distance' => '36',
            'type' => 'FC',
        ],
        '27-29' => [
            'distance' => '37',
            'type' => 'C',
        ],
        '30-31' => [
            'distance' => '38',
            'type' => 'OOB',
        ],
        '32-37' => [
            'distance' => '39',
            'type' => 'C',
        ],
        '38-39' => [
            'distance' => '40',
            'type' => 'FC',
        ],
        '40-44' => [
            'distance' => '41',
            'type' => 'C',
        ],
        '45' => [
            'distance' => '42',
            'type' => 'D',
        ],
        '46-49' => [
            'distance' => '43',
            'type' => 'C',
        ],
        '50-51' => [
            'distance' => '44',
            'type' => 'FC',
        ],
        '52' => [
            'distance' => '45',
            'type' => 'D',
        ],
        '53-55' => [
            'distance' => '46',
            'type' => 'C',
        ],
        '56' => [
            'distance' => '47',
            'type' => 'OOB',
        ],
        '57-59' => [
            'distance' => '48',
            'type' => 'C',
        ],
        '60' => [
            'distance' => '49',
            'type' => 'D',
        ],
        '61-62' => [
            'distance' => '50',
            'type' => 'C',
        ],
        '63' => [
            'distance' => '51',
            'type' => 'FC',
        ],
        '64-65' => [
            'distance' => '53',
            'type' => 'C',
        ],
        '66' => [
            'distance' => '55',
            'type' => 'C',
        ],
        '67' => [
            'distance' => '58',
            'type' => 'C',
        ],
        '68' => [
            'distance' => '60',
            'type' => 'D',
        ],
        '69+' => [
            'distance' => '65',
            'type' => 'C',
        ],
    ],
    'pooch_punts' => [

        '10-12' => [
            'skill_less' => [
                'spot' => '16',
                'type' => 'D',
            ],
            'skill_greater' => [
                'spot' => '23',
                'type' => 'OOB',
            ],
        ],

        '13-15' => [
            'skill_less' => [
                'spot' => '15',
                'type' => 'FC',
            ],
            'skill_greater' => [
                'spot' => '17',
                'type' => 'C',
            ],
        ],

        '16-18' => [
            'skill_less' => [
                'spot' => '15',
                'type' => 'OOB',
            ],
            'skill_greater' => [
                'spot' => '15',
                'type' => 'C',
            ],
        ],

        '19-21' => [
            'skill_less' => [
                'spot' => '14',
                'type' => 'C',
            ],
            'skill_greater' => [
                'spot' => '20',
                'type' => 'OOB',
            ],
        ],

        '22-24' => [
            'skill_less' => [
                'spot' => '12',
                'type' => 'FC',
            ],
            'skill_greater' => [
                'spot' => '13',
                'type' => 'C',
            ],
        ],

        '25-27' => [
            'skill_less' => [
                'spot' => '12',
                'type' => 'D',
            ],
            'skill_greater' => [
                'spot' => '12',
                'type' => 'C',
            ],
        ],

        '28-30' => [
            'skill_less' => [
                'spot' => '11',
                'type' => 'OOB',
            ],
            'skill_greater' => [
                'spot' => '11',
                'type' => 'C',
            ],
        ],

        '31-33' => [
            'skill_less' => [
                'spot' => '10',
                'type' => 'C',
            ],
            'skill_greater' => [
                'spot' => '19',
                'type' => 'OOB',
            ],
        ],

        '34-36' => [
            'skill_less' => [
                'spot' => '10',
                'type' => 'FC',
            ],
            'skill_greater' => [
                'spot' => '20',
                'type' => 'TB',
            ],
        ],

        '37-39' => [
            'skill_less' => [
                'spot' => '9',
                'type' => 'OOB',
            ],
            'skill_greater' => [
                'spot' => '16',
                'type' => 'OOB',
            ],
        ],

        '40-42' => [
            'skill_less' => [
                'spot' => '9',
                'type' => 'D',
            ],
            'skill_greater' => [
                'spot' => '20',
                'type' => 'TB',
            ],
        ],

        '43-45' => [
            'skill_less' => [
                'spot' => '8',
                'type' => 'C',
            ],
            'skill_greater' => [
                'spot' => '20',
                'type' => 'TB',
            ],
        ],

        '46-48' => [
            'skill_less' => [
                'spot' => '7',
                'type' => 'FC',
            ],
            'skill_greater' => [
                'spot' => '20',
                'type' => 'TB',
            ],
        ],

        '49-51' => [
            'skill_less' => [
                'spot' => '6',
                'type' => 'C',
            ],
            'skill_greater' => [
                'spot' => '20',
                'type' => 'TB',
            ],
        ],

        '52-54' => [
            'skill_less' => [
                'spot' => '6',
                'type' => 'D',
            ],
            'skill_greater' => [
                'spot' => '20',
                'type' => 'TB',
            ],
        ],

        '55-57' => [
            'skill_less' => [
                'spot' => '6',
                'type' => 'OOB',
            ],
            'skill_greater' => [
                'spot' => '20',
                'type' => 'TB',
            ],
        ],

        '58-60' => [
            'skill_less' => [
                'spot' => '5',
                'type' => 'FC',
            ],
            'skill_greater' => [
                'spot' => '20',
                'type' => 'TB',
            ],
        ],

        '61-63' => [
            'skill_less' => [
                'spot' => '4',
                'type' => 'C',
            ],
            'skill_greater' => [
                'spot' => '20',
                'type' => 'TB',
            ],
        ],

        '64-66' => [
            'skill_less' => [
                'spot' => '3',
                'type' => 'D',
            ],
            'skill_greater' => [
                'spot' => '20',
                'type' => 'TB',
            ],
        ],

        '67-69' => [
            'skill_less' => [
                'spot' => '2',
                'type' => 'OOB',
            ],
            'skill_greater' => [
                'spot' => '20',
                'type' => 'TB',
            ],
        ],

        '70+' => [
            'skill_less' => [
                'spot' => '1',
                'type' => 'C',
            ],
            'skill_greater' => [
                'spot' => '1',
                'type' => 'D',
            ],
        ],
    ],
    'punt_returns' => [
        '10-12' => 'blue - 4',
        '13-39' => 'blue',
        '40-59' => 'blue + PR',
        '60-1000' => 'B!',
    ],
    'onside_kicks' => [
        '0-18' => [
            'recover' => 'R',
        ],
        '19-1000' => [
            'recover' => 'K',
        ],
    ],
];
