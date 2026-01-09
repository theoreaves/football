<?php

namespace App\Console\Commands;

use App\Models\Player;
use App\Models\Team;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Faker\Factory as Faker;

class SeedNflTeams extends Command
{
    protected $signature = 'football:seed-nfl
        {--year=2022 : team_year to set in team_players}
        {--fresh : delete existing team_players rows for that year/team before inserting}
        {--reset-teams : delete ALL teams first (and team_players for that year)}
    ';

    protected $description = 'Create/update all 32 NFL teams with colors + random OL fields, then seed a generic roster for each team/year.';

    public function handle(): int
    {
        $year  = (string) $this->option('year');
        $fresh = (bool) $this->option('fresh');
        $reset = (bool) $this->option('reset-teams');

        $defaultPlaycalling = ['behind' => 2, 'tied' => 2, 'ahead' => 1];
        $teamsData = $this->nflTeams();

        DB::transaction(function () use ($teamsData, $year, $fresh, $reset, $defaultPlaycalling) {
            if ($reset) {
                // If you truly want a clean slate.
                // This deletes team rows (and relies on your DB constraints or app logic for related cleanup).
                // We also remove team_players for the given year to avoid leftovers if constraints are loose.
                DB::table('team_players')->where('team_year', $year)->delete();
                Team::query()->delete();
            }

            // 1) Upsert the 32 teams
            $teams = [];
            foreach ($teamsData as $t) {
                /** @var Team $team */
                $team = Team::query()->firstOrCreate(
                    ['city' => $t['city'], 'name' => $t['name']],
                    [
                        'playcalling_behind' => $defaultPlaycalling['behind'],
                        'playcalling_tied'   => $defaultPlaycalling['tied'],
                        'playcalling_ahead'  => $defaultPlaycalling['ahead'],
                    ]
                );

                // Always update colors + OL fields (and playcalling if blank-ish)
                $team->team_color1 = $t['color1'];
                $team->team_color2 = $t['color2'];
                $team->jersey_dark_primary = $t['color1'];
                $team->jersey_dark_outline = $t['color2'];
                $team->jersey_dark_font = $t['color2'];
                $team->jersey_white_primary = '#FFFFFF';
                $team->jersey_white_outline = $t['color2'];
                $team->jersey_white_font = $t['color1'];

                $team->ol_rush    = random_int(1, 9);
                $team->ol_power   = random_int(1, 9);
                $team->ol_pass    = random_int(1, 9);
                $team->ol_protect = random_int(1, 9);

                $team->playcalling_behind = $team->playcalling_behind ?: $defaultPlaycalling['behind'];
                $team->playcalling_tied   = $team->playcalling_tied   ?: $defaultPlaycalling['tied'];
                $team->playcalling_ahead  = $team->playcalling_ahead  ?: $defaultPlaycalling['ahead'];

                $team->save();

                $teams[] = $team;
            }

            // 2) Seed rosters for each team
            $template = []; // $this->chiefsTemplate();

            foreach ($teams as $team) {
                if ($fresh) {
                    DB::table('team_players')
                        ->where('team_id', $team->id)
                        ->where('team_year', $year)
                        ->delete();
                }

                // Track jersey numbers used for THIS team/year to guarantee uniqueness
                $usedNumbers = DB::table('team_players')
                    ->where('team_id', $team->id)
                    ->where('team_year', $year)
                    ->pluck('jersey_number')
                    ->filter(fn ($n) => !is_null($n))
                    ->map(fn ($n) => (int) $n)
                    ->values()
                    ->all();

                $usedNumbers = array_flip($usedNumbers);

                foreach ($template as $row) {
                    $player = new Player();
                    $player->position  = $row['position'];
                    $player->age       = $this->randAgeForPosition($row['position']);
                    $player->firstname = $this->randomFirstName();
                    $player->lastname  = $this->randomLastName();

                    $attrs = $this->randomAttributesForPosition($row['position']);
                    foreach ($attrs as $k => $v) {
                        $player->{$k} = $v;
                    }
                    $player->save();

                    $jersey = $this->uniqueJerseyNumberForTeamYear($row['position'], $usedNumbers);
                    $usedNumbers[$jersey] = true;

                    DB::table('team_players')->insert([
                        'team_id'   => $team->id,
                        'player_id' => $player->id,
                        'team_year' => $year,

                        'position'       => $row['position'],
                        'jersey_number'  => $jersey,

                        'depth_chart_position' => $row['depth'] ?? '',
                        'kick_return_depth_chart_position' => $row['kr_depth'] ?? '',
                        'punt_return_depth_chart_position' => $row['pr_depth'] ?? '',

                        // Offense ranges
                        'catch_from' => $row['catch'][0] ?? 0,
                        'catch_to' => $row['catch'][1] ?? 0,
                        'catch_plus_from' => $row['catch_plus'][0] ?? 0,
                        'catch_plus_to' => $row['catch_plus'][1] ?? 0,
                        'rush_from' => $row['rush'][0] ?? 0,
                        'rush_to' => $row['rush'][1] ?? 0,

                        // Defense ranges
                        'sack_from' => $row['sack'][0] ?? 0,
                        'sack_to' => $row['sack'][1] ?? 0,
                        'interception_from' => $row['int'][0] ?? 0,
                        'interception_to' => $row['int'][1] ?? 0,

                        // Tackles (default 0 if not supplied)
                        'tackle_from' => $row['tackle'][0] ?? 0,
                        'tackle_to'   => $row['tackle'][1] ?? 0,

                        // Return ranges
                        'kick_from' => $row['kick'][0] ?? 0,
                        'kick_to' => $row['kick'][1] ?? 0,
                        'punt_from' => $row['punt'][0] ?? 0,
                        'punt_to' => $row['punt'][1] ?? 0,

                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        });

        $this->info("Created/updated 32 NFL teams and seeded rosters for year {$year}.");
        if ($fresh) $this->info("Used --fresh (existing team_players rows for that year/team were deleted first).");

        return self::SUCCESS;
    }

    /**
     * 32 NFL teams: city, name, two primary colors (hex).
     * (These are commonly-used official/brand hex values.)
     */
    private function nflTeams(): array
    {
        return [
            ['city'=>'Arizona',        'name'=>'Cardinals',  'color1'=>'#97233F', 'color2'=>'#000000'],
            ['city'=>'Atlanta',        'name'=>'Falcons',    'color1'=>'#A71930', 'color2'=>'#000000'],
            ['city'=>'Baltimore',      'name'=>'Ravens',     'color1'=>'#241773', 'color2'=>'#000000'],
            ['city'=>'Buffalo',        'name'=>'Bills',      'color1'=>'#00338D', 'color2'=>'#C60C30'],
            ['city'=>'Carolina',       'name'=>'Panthers',   'color1'=>'#0085CA', 'color2'=>'#101820'],
            ['city'=>'Chicago',        'name'=>'Bears',      'color1'=>'#0B162A', 'color2'=>'#C83803'],
            ['city'=>'Cincinnati',     'name'=>'Bengals',    'color1'=>'#FB4F14', 'color2'=>'#000000'],
            ['city'=>'Cleveland',      'name'=>'Browns',     'color1'=>'#311D00', 'color2'=>'#FF3C00'],
            ['city'=>'Dallas',         'name'=>'Cowboys',    'color1'=>'#003594', 'color2'=>'#869397'],
            ['city'=>'Denver',         'name'=>'Broncos',    'color1'=>'#FB4F14', 'color2'=>'#002244'],
            ['city'=>'Detroit',        'name'=>'Lions',      'color1'=>'#0076B6', 'color2'=>'#B0B7BC'],
            ['city'=>'Green Bay',      'name'=>'Packers',    'color1'=>'#203731', 'color2'=>'#FFB612'],
            ['city'=>'Houston',        'name'=>'Texans',     'color1'=>'#03202F', 'color2'=>'#A71930'],
            ['city'=>'Indianapolis',   'name'=>'Colts',      'color1'=>'#002C5F', 'color2'=>'#A2AAAD'],
            ['city'=>'Jacksonville',   'name'=>'Jaguars',    'color1'=>'#006778', 'color2'=>'#D7A22A'],
            ['city'=>'Kansas City',    'name'=>'Chiefs',     'color1'=>'#E31837', 'color2'=>'#FFB81C'],
            ['city'=>'Las Vegas',      'name'=>'Raiders',    'color1'=>'#000000', 'color2'=>'#A5ACAF'],
            ['city'=>'Los Angeles',    'name'=>'Chargers',   'color1'=>'#0080C6', 'color2'=>'#FFC20E'],
            ['city'=>'Los Angeles',    'name'=>'Rams',       'color1'=>'#003594', 'color2'=>'#FFA300'],
            ['city'=>'Miami',          'name'=>'Dolphins',   'color1'=>'#008E97', 'color2'=>'#FC4C02'],
            ['city'=>'Minnesota',      'name'=>'Vikings',    'color1'=>'#4F2683', 'color2'=>'#FFC62F'],
            ['city'=>'New England',    'name'=>'Patriots',   'color1'=>'#002244', 'color2'=>'#C60C30'],
            ['city'=>'New Orleans',    'name'=>'Saints',     'color1'=>'#D3BC8D', 'color2'=>'#101820'],
            ['city'=>'New York',       'name'=>'Giants',     'color1'=>'#0B2265', 'color2'=>'#A71930'],
            ['city'=>'New York',       'name'=>'Jets',       'color1'=>'#125740', 'color2'=>'#000000'],
            ['city'=>'Philadelphia',   'name'=>'Eagles',     'color1'=>'#004C54', 'color2'=>'#A5ACAF'],
            ['city'=>'Pittsburgh',     'name'=>'Steelers',   'color1'=>'#101820', 'color2'=>'#FFB612'],
            ['city'=>'San Francisco',  'name'=>'49ers',      'color1'=>'#AA0000', 'color2'=>'#B3995D'],
            ['city'=>'Seattle',        'name'=>'Seahawks',   'color1'=>'#002244', 'color2'=>'#69BE28'],
            ['city'=>'Tampa Bay',      'name'=>'Buccaneers', 'color1'=>'#D50A0A', 'color2'=>'#34302B'],
            ['city'=>'Tennessee',      'name'=>'Titans',     'color1'=>'#0C2340', 'color2'=>'#4B92DB'],
            ['city'=>'Washington',     'name'=>'Commanders', 'color1'=>'#5A1414', 'color2'=>'#FFB612'],
        ];
    }

    /**
     * Chiefs template (depth chart slots + dice ranges).
     * Same shape you already use; names are ignored here (we randomize).
     */
    private function chiefsTemplate(): array
    {
        $R = fn ($s) => $this->parseRange($s);
        $Z = fn () => [0, 0];

        return [
            ['position'=>'QB','depth'=>'QB1','catch'=>$R('-'),'catch_plus'=>$R('-'),'rush'=>$R('1-3'),'sack'=>$R('-'),'int'=>$R('-'),'tackle'=>$Z(),'kick'=>$R('-'),'punt'=>$R('-')],
            ['position'=>'QB','depth'=>'QB2','catch'=>$R('-'),'catch_plus'=>$R('-'),'rush'=>$R('0-2'),'sack'=>$R('-'),'int'=>$R('-'),'tackle'=>$Z(),'kick'=>$R('-'),'punt'=>$R('-')],

            ['position'=>'RB','depth'=>'RB1','catch'=>$R('1'),'catch_plus'=>$R('-'),'rush'=>$R('4-12'),'sack'=>$R('-'),'int'=>$R('-'),'tackle'=>$Z(),'kick'=>$R('-'),'punt'=>$R('-')],
            ['position'=>'RB','depth'=>'RB2','catch'=>$R('2-5'),'catch_plus'=>$R('-'),'rush'=>$R('13-15'),'sack'=>$R('-'),'int'=>$R('-'),'tackle'=>$Z(),'kick'=>$R('-'),'punt'=>$R('-')],
            ['position'=>'RB','depth'=>'RB3','catch'=>$R('6'),'catch_plus'=>$R('-'),'rush'=>$R('16-19'),'sack'=>$R('-'),'int'=>$R('-'),'tackle'=>$Z(),'kick'=>$R('-'),'punt'=>$R('-')],
            ['position'=>'RB','depth'=>'RB4','catch'=>$R('-'),'catch_plus'=>$R('-'),'rush'=>$R('20'),'sack'=>$R('-'),'int'=>$R('-'),'tackle'=>$Z(),'kick'=>$R('-'),'punt'=>$R('-')],

            ['position'=>'TE','depth'=>'TE1','catch'=>$R('7-13'),'catch_plus'=>$R('1-5'),'rush'=>$R('-'),'sack'=>$R('-'),'int'=>$R('-'),'tackle'=>$Z(),'kick'=>$R('-'),'punt'=>$R('-')],
            ['position'=>'TE','depth'=>'TE2','catch'=>$R('14-15'),'catch_plus'=>$R('6'),'rush'=>$R('-'),'sack'=>$R('-'),'int'=>$R('-'),'tackle'=>$Z(),'kick'=>$R('-'),'punt'=>$R('-')],

            ['position'=>'WR','depth'=>'WR1','catch'=>$R('16-17'),'catch_plus'=>$R('7-13'),'rush'=>$R('-'),'sack'=>$R('-'),'int'=>$R('-'),'tackle'=>$Z(),'kick'=>$R('-'),'punt'=>$R('-')],
            ['position'=>'WR','depth'=>'WR2','catch'=>$R('18'),'catch_plus'=>$R('14-17'),'rush'=>$R('-'),'sack'=>$R('-'),'int'=>$R('-'),'tackle'=>$Z(),'kick'=>$R('-'),'punt'=>$R('-')],
            ['position'=>'WR','depth'=>'WR3','catch'=>$R('19'),'catch_plus'=>$R('18-19'),'rush'=>$R('-'),'sack'=>$R('-'),'int'=>$R('-'),'tackle'=>$Z(),'kick'=>$R('-'),'punt'=>$R('-')],
            ['position'=>'WR','depth'=>'WR4','catch'=>$R('20'),'catch_plus'=>$R('20'),'rush'=>$R('-'),'sack'=>$R('-'),'int'=>$R('-'),'tackle'=>$Z(),'kick'=>$R('-'),'punt'=>$R('-')],

            ['position'=>'DL','depth'=>'DL1','catch'=>$R('-'),'catch_plus'=>$R('-'),'rush'=>$R('-'),'sack'=>$R('1-7'),'int'=>$R('-'),'tackle'=>$R('1'),'kick'=>$R('-'),'punt'=>$R('-')],
            ['position'=>'DL','depth'=>'DL2','catch'=>$R('-'),'catch_plus'=>$R('-'),'rush'=>$R('-'),'sack'=>$R('8-10'),'int'=>$R('-'),'tackle'=>$R('2'),'kick'=>$R('-'),'punt'=>$R('-')],
            ['position'=>'DL','depth'=>'DL3','catch'=>$R('-'),'catch_plus'=>$R('-'),'rush'=>$R('-'),'sack'=>$R('11-12'),'int'=>$R('-'),'tackle'=>$R('3'),'kick'=>$R('-'),'punt'=>$R('-')],
            ['position'=>'DL','depth'=>'DL4','catch'=>$R('-'),'catch_plus'=>$R('-'),'rush'=>$R('-'),'sack'=>$R('13'),'int'=>$R('-'),'tackle'=>$R('4'),'kick'=>$R('-'),'punt'=>$R('-')],
            ['position'=>'DL','depth'=>'DL5','catch'=>$R('-'),'catch_plus'=>$R('-'),'rush'=>$R('-'),'sack'=>$R('14-15'),'int'=>$R('-'),'tackle'=>$R('5'),'kick'=>$R('-'),'punt'=>$R('-')],

            ['position'=>'LB','depth'=>'LB1','catch'=>$R('-'),'catch_plus'=>$R('-'),'rush'=>$R('-'),'sack'=>$R('16'),'int'=>$R('1-4'),'tackle'=>$R('6-9'),'kick'=>$R('-'),'punt'=>$R('-')],
            ['position'=>'LB','depth'=>'LB2','catch'=>$R('-'),'catch_plus'=>$R('-'),'rush'=>$R('-'),'sack'=>$R('17'),'int'=>$R('5-6'),'tackle'=>$R('10'),'kick'=>$R('-'),'punt'=>$R('-')],
            ['position'=>'LB','depth'=>'LB3','catch'=>$R('-'),'catch_plus'=>$R('-'),'rush'=>$R('-'),'sack'=>$R('18'),'int'=>$R('7'),'tackle'=>$R('11'),'kick'=>$R('-'),'punt'=>$R('-')],
            ['position'=>'LB','depth'=>'LB4','catch'=>$R('-'),'catch_plus'=>$R('-'),'rush'=>$R('-'),'sack'=>$R('-'),'int'=>$R('-'),'tackle'=>$R('12'),'kick'=>$R('-'),'punt'=>$R('-')],
            ['position'=>'LB','depth'=>'LB5','catch'=>$R('-'),'catch_plus'=>$R('-'),'rush'=>$R('-'),'sack'=>$R('-'),'int'=>$R('-'),'tackle'=>$R('-'),'kick'=>$R('-'),'punt'=>$R('-')],

            ['position'=>'CB','depth'=>'CB1','catch'=>$R('-'),'catch_plus'=>$R('-'),'rush'=>$R('-'),'sack'=>$R('19-20'),'int'=>$R('8-11'),'tackle'=>$R('13-15'),'kick'=>$R('-'),'punt'=>$R('-')],
            ['position'=>'CB','depth'=>'CB2','catch'=>$R('-'),'catch_plus'=>$R('-'),'rush'=>$R('-'),'sack'=>$R('-'),'int'=>$R('12-13'),'tackle'=>$R('16'),'kick'=>$R('-'),'punt'=>$R('-')],

            ['position'=>'S','depth'=>'S1','catch'=>$R('-'),'catch_plus'=>$R('-'),'rush'=>$R('-'),'sack'=>$R('-'),'int'=>$R('14-15'),'tackle'=>$R('17-18'),'kick'=>$R('-'),'punt'=>$R('-')],
            ['position'=>'S','depth'=>'S2','catch'=>$R('-'),'catch_plus'=>$R('-'),'rush'=>$R('-'),'sack'=>$R('-'),'int'=>$R('16-17'),'tackle'=>$R('19'),'kick'=>$R('-'),'punt'=>$R('-')],

            ['position'=>'DB','depth'=>'DB1','catch'=>$R('-'),'catch_plus'=>$R('-'),'rush'=>$R('-'),'sack'=>$R('-'),'int'=>$R('18-20'),'tackle'=>$R('20'),'kick'=>$R('-'),'punt'=>$R('-')],

            ['position'=>'K','depth'=>'K','catch'=>$R('-'),'catch_plus'=>$R('-'),'rush'=>$R('-'),'sack'=>$R('-'),'int'=>$R('-'),'tackle'=>$Z(),'kick'=>$R('-'),'punt'=>$R('-')],
            ['position'=>'P','depth'=>'P','catch'=>$R('-'),'catch_plus'=>$R('-'),'rush'=>$R('-'),'sack'=>$R('-'),'int'=>$R('-'),'tackle'=>$Z(),'kick'=>$R('-'),'punt'=>$R('-')],

            // Kick return
            ['position'=>'RB','depth'=>'KR1','kr_depth'=>'KR1','catch'=>$R('-'),'catch_plus'=>$R('-'),'rush'=>$R('-'),'sack'=>$R('-'),'int'=>$R('-'),'tackle'=>$Z(),'kick'=>$R('1-17'),'punt'=>$R('-')],
            ['position'=>'WR','depth'=>'KR2','kr_depth'=>'KR2','catch'=>$R('-'),'catch_plus'=>$R('-'),'rush'=>$R('-'),'sack'=>$R('-'),'int'=>$R('-'),'tackle'=>$Z(),'kick'=>$R('18-19'),'punt'=>$R('-')],
            ['position'=>'FB','depth'=>'KR3','kr_depth'=>'KR3','catch'=>$R('-'),'catch_plus'=>$R('-'),'rush'=>$R('-'),'sack'=>$R('-'),'int'=>$R('-'),'tackle'=>$Z(),'kick'=>$R('20'),'punt'=>$R('-')],

            // Punt return
            ['position'=>'WR','depth'=>'PR1','pr_depth'=>'PR1','catch'=>$R('-'),'catch_plus'=>$R('-'),'rush'=>$R('-'),'sack'=>$R('-'),'int'=>$R('-'),'tackle'=>$Z(),'kick'=>$R('-'),'punt'=>$R('1-9')],
            ['position'=>'WR','depth'=>'PR2','pr_depth'=>'PR2','catch'=>$R('-'),'catch_plus'=>$R('-'),'rush'=>$R('-'),'sack'=>$R('-'),'int'=>$R('-'),'tackle'=>$Z(),'kick'=>$R('-'),'punt'=>$R('10-16')],
            ['position'=>'WR','depth'=>'PR3','pr_depth'=>'PR3','catch'=>$R('-'),'catch_plus'=>$R('-'),'rush'=>$R('-'),'sack'=>$R('-'),'int'=>$R('-'),'tackle'=>$Z(),'kick'=>$R('-'),'punt'=>$R('17-20')],
        ];
    }

    private function parseRange(string $s): array
    {
        $s = trim($s);
        if ($s === '' || $s === '-' || $s === '—') return [0, 0];

        if (str_contains($s, '-')) {
            [$a, $b] = array_map('trim', explode('-', $s, 2));
            $a = (int) $a;
            $b = (int) $b;
            if ($a <= 0 || $b <= 0) return [0, 0];
            return [$a, $b];
        }

        $n = (int) $s;
        return $n > 0 ? [$n, $n] : [0, 0];
    }

    private function randAgeForPosition(string $pos): int
    {
        $pos = strtoupper($pos);

        return match ($pos) {
            'QB' => random_int(22, 35),
            'K', 'P' => random_int(23, 38),
            default => random_int(20, 33),
        };
    }

    private function uniqueJerseyNumberForTeamYear(string $pos, array $usedNumbers): int
    {
        $pos = strtoupper($pos);

        $ranges = match ($pos) {
            'QB' => [[1, 19]],
            'RB', 'FB', 'WR', 'TE' => [[1, 49], [80, 89]],
            'C', 'G', 'T', 'OL' => [[50, 79]],

            'DE', 'DT', 'NT', 'DL' => [[50, 79], [90, 99]],
            'LB', 'MLB', 'OLB' => [[1, 59], [90, 99]],
            'CB', 'FS', 'SS', 'DB', 'S' => [[1, 49]],

            'K', 'P' => [[1, 19]],

            default => [[1, 99]],
        };

        $pool = $this->numbersFromRanges($ranges);
        shuffle($pool);

        foreach ($pool as $n) {
            if (!isset($usedNumbers[$n])) return $n;
        }

        for ($n = 1; $n <= 99; $n++) {
            if (!isset($usedNumbers[$n])) return $n;
        }

        return 99;
    }

    private function numbersFromRanges(array $ranges): array
    {
        $numbers = [];
        foreach ($ranges as [$from, $to]) {
            $numbers = array_merge($numbers, range((int) $from, (int) $to));
        }
        return $numbers;
    }

    private function randomFirstName(): string
    {
        return Faker::create()->firstNameMale;
    }

    private function randomLastName(): string
    {
        return Faker::create()->lastName;
    }

    private function randomAttributesForPosition(string $pos): array
    {
        $pos = strtoupper($pos);
        $r = fn (int $min, int $max) => random_int($min, $max);

        $base = [
            'pass_evade' => 0, 'pass_accuracy' => 0, 'pass_deep' => 0, 'pass_control' => 0,
            'rush' => 0, 'rush_power' => 0, 'receive' => 0, 'receive_deep' => 0,
            'fumble' => 0, 'speed' => 0,
            'tackle' => 0, 'sack' => 0, 'cover' => 0, 'interception' => 0, 'strip' => 0,
            'kick30' => 0, 'kick39' => 0, 'kick49' => 0, 'kick50' => 0,
            'punt_distance' => 0, 'punt_pooch_yard' => 0, 'punt_pooch' => 0, 'punt_block' => 0,
            'return_yards' => 0, 'return_speed' => 0, 'return_fumble' => 0,
        ];

        return match ($pos) {
            'QB' => array_merge($base, [
                'pass_evade' => $r(2, 9),
                'pass_accuracy' => $r(3, 9),
                'pass_deep' => $r(2, 9),
                'pass_control' => $r(3, 9),
                'rush' => $r(0, 6),
                'speed' => $r(2, 8),
                'fumble' => $r(0, 6),
            ]),
            'RB' => array_merge($base, [
                'rush' => $r(3, 9),
                'rush_power' => $r(2, 9),
                'receive' => $r(0, 8),
                'receive_deep' => $r(0, 6),
                'speed' => $r(3, 9),
                'fumble' => $r(0, 8),
                'return_yards' => $r(0, 8),
                'return_speed' => $r(0, 9),
                'return_fumble' => $r(0, 8),
            ]),
            'WR', 'TE' => array_merge($base, [
                'receive' => $r(3, 9),
                'receive_deep' => $r(2, 9),
                'speed' => $r(3, 9),
                'fumble' => $r(0, 7),
                'return_yards' => $r(0, 8),
                'return_speed' => $r(0, 9),
                'return_fumble' => $r(0, 8),
            ]),
            'DL', 'LB', 'CB', 'S', 'DB' => array_merge($base, [
                'tackle' => $r(2, 9),
                'sack' => $r(0, ($pos === 'DL' || $pos === 'LB') ? 9 : 5),
                'cover' => $r(0, ($pos === 'CB' || $pos === 'S' || $pos === 'DB') ? 9 : 6),
                'interception' => $r(0, ($pos === 'CB' || $pos === 'S' || $pos === 'DB') ? 9 : 5),
                'strip' => $r(0, 7),
                'speed' => $r(1, 9),
            ]),
            'K' => array_merge($base, [
                'kick30' => $r(4, 10),
                'kick39' => $r(3, 10),
                'kick49' => $r(1, 10),
                'kick50' => $r(0, 9),
            ]),
            'P' => array_merge($base, [
                'punt_distance' => $r(3, 10),
                'punt_pooch_yard' => $r(45, 55),
                'punt_pooch' => $r(0, 9),
                'punt_block' => $r(0, 9),
            ]),
            default => $base,
        };
    }
}
