<?php

namespace App\Console\Commands;

use App\Models\Player;
use App\Models\Team;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Faker\Factory as Faker;

class SeedGenericTeams extends Command
{
    protected $signature = 'football:seed-teams
        {--year=2022 : team_year to set in team_players}
        {--teams=4 : how many teams to seed (starting from lowest id)}
        {--fresh : delete existing team_players rows for that year/team before inserting}
        {--use-real-names : use the Chiefs names for the first team (others random)}
    ';

    protected $description = 'Populate existing teams with players using 2022 Chiefs-style roster template (random attributes).';

    public function handle(): int
    {
        $year      = (string) $this->option('year');
        $teamsN    = (int) $this->option('teams');
        $fresh     = (bool) $this->option('fresh');
        $realNames = (bool) $this->option('use-real-names');

        $teams = Team::query()->orderBy('id')->limit($teamsN)->get();
        if ($teams->isEmpty()) {
            $this->error('No teams found. Create your generic teams first.');
            return self::FAILURE;
        }

        // Chiefs-style playcalling defaults (Behind / Tied / Ahead): +2 +2 +1
        $defaultPlaycalling = ['behind' => 2, 'tied' => 2, 'ahead' => 1];

        $template = $this->chiefsTemplate();

        DB::transaction(function () use ($teams, $template, $year, $fresh, $defaultPlaycalling, $realNames) {
            foreach ($teams as $idx => $team) {

                if ($fresh) {
                    DB::table('team_players')
                        ->where('team_id', $team->id)
                        ->where('team_year', $year)
                        ->delete();
                }

                // playcalling defaults if blank-ish
                $team->playcalling_behind = $team->playcalling_behind ?: $defaultPlaycalling['behind'];
                $team->playcalling_tied   = $team->playcalling_tied   ?: $defaultPlaycalling['tied'];
                $team->playcalling_ahead  = $team->playcalling_ahead  ?: $defaultPlaycalling['ahead'];
                $team->save();

                // Track jersey numbers used for THIS team/year so we can guarantee uniqueness.
                // Pull existing ones (in case you are NOT using --fresh).
                $usedNumbers = DB::table('team_players')
                    ->where('team_id', $team->id)
                    ->where('team_year', $year)
                    ->pluck('jersey_number')
                    ->filter(fn ($n) => !is_null($n))
                    ->map(fn ($n) => (int) $n)
                    ->values()
                    ->all();

                $usedNumbers = array_flip($usedNumbers); // faster lookup

                foreach ($template as $row) {
                    // Create player
                    $player = new Player();
                    $player->position = $row['position'];
                    $player->age      = $this->randAgeForPosition($row['position']);

                    // Names
                    if ($realNames && $idx === 0 && !empty($row['real_name'])) {
                        [$fn, $ln] = $this->splitName($row['real_name']);
                        $player->firstname = $fn;
                        $player->lastname  = $ln;
                    } else {
                        $player->firstname = $this->randomFirstName();
                        $player->lastname  = $this->randomLastName();
                    }

                    // Random attributes, weighted by position
                    $attrs = $this->randomAttributesForPosition($row['position']);
                    foreach ($attrs as $k => $v) {
                        $player->{$k} = $v;
                    }
                    $player->save();

                    // Unique jersey number (pivot)
                    $jersey = $this->uniqueJerseyNumberForTeamYear($row['position'], $usedNumbers);
                    $usedNumbers[$jersey] = true;

                    // Pivot insert (IMPORTANT: include tackle_from/to to satisfy NOT NULL)
                    DB::table('team_players')->insert([
                        'team_id'  => $team->id,
                        'player_id'=> $player->id,
                        'team_year'=> $year,

                        'position' => $row['position'],
                        'jersey_number' => $jersey,

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

                        // Tackle ranges (your table has NOT NULL; PDF doesn’t give these, so default 0)
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

        $this->info("Seeded {$teams->count()} team(s) for year {$year} using Chiefs-style template.");
        $this->info("Tip: re-run with --fresh to replace existing team_players rows for that year.");
        return self::SUCCESS;
    }

    /**
     * Chiefs template (depth chart slots + dice ranges).
     * For fields with no ranges in the PDF, store [0,0].
     */
    private function chiefsTemplate(): array
    {
        $R = fn ($s) => $this->parseRange($s);
        $Z = fn () => [0, 0]; // convenience for fields not used

        return [
            // QB
            ['position'=>'QB', 'depth'=>'QB1', 'real_name'=>'Patrick Mahomes', 'catch'=>$R('-'), 'catch_plus'=>$R('-'), 'rush'=>$R('1-3'), 'sack'=>$R('-'), 'int'=>$R('-'), 'tackle'=>$Z(), 'kick'=>$R('-'), 'punt'=>$R('-')],
            ['position'=>'QB', 'depth'=>'QB2', 'real_name'=>'Chad Henne',      'catch'=>$R('-'), 'catch_plus'=>$R('-'), 'rush'=>$R('0-2'), 'sack'=>$R('-'), 'int'=>$R('-'), 'tackle'=>$Z(), 'kick'=>$R('-'), 'punt'=>$R('-')],

            // RB/TE/WR
            ['position'=>'RB', 'depth'=>'RB1', 'real_name'=>'Isiah Pacheco',         'catch'=>$R('1'),     'catch_plus'=>$R('-'),     'rush'=>$R('4-12'),  'sack'=>$R('-'), 'int'=>$R('-'), 'tackle'=>$Z(), 'kick'=>$R('-'), 'punt'=>$R('-')],
            ['position'=>'RB', 'depth'=>'RB2', 'real_name'=>'Jerick McKinnon',       'catch'=>$R('2-5'),   'catch_plus'=>$R('-'),     'rush'=>$R('13-15'), 'sack'=>$R('-'), 'int'=>$R('-'), 'tackle'=>$Z(), 'kick'=>$R('-'), 'punt'=>$R('-')],
            ['position'=>'RB', 'depth'=>'RB3', 'real_name'=>'Clyde Edwards-Helaire', 'catch'=>$R('6'),     'catch_plus'=>$R('-'),     'rush'=>$R('16-19'), 'sack'=>$R('-'), 'int'=>$R('-'), 'tackle'=>$Z(), 'kick'=>$R('-'), 'punt'=>$R('-')],
            ['position'=>'RB', 'depth'=>'RB4', 'real_name'=>'Ronald Jones',          'catch'=>$R('-'),     'catch_plus'=>$R('-'),     'rush'=>$R('20'),    'sack'=>$R('-'), 'int'=>$R('-'), 'tackle'=>$Z(), 'kick'=>$R('-'), 'punt'=>$R('-')],

            ['position'=>'TE', 'depth'=>'TE1', 'real_name'=>'Travis Kelce', 'catch'=>$R('7-13'), 'catch_plus'=>$R('1-5'),  'rush'=>$R('-'), 'sack'=>$R('-'), 'int'=>$R('-'), 'tackle'=>$Z(), 'kick'=>$R('-'), 'punt'=>$R('-')],
            ['position'=>'TE', 'depth'=>'TE2', 'real_name'=>'Noah Gray',    'catch'=>$R('14-15'),'catch_plus'=>$R('6'),    'rush'=>$R('-'), 'sack'=>$R('-'), 'int'=>$R('-'), 'tackle'=>$Z(), 'kick'=>$R('-'), 'punt'=>$R('-')],

            ['position'=>'WR', 'depth'=>'WR1', 'real_name'=>'JuJu Smith-Schuster', 'catch'=>$R('16-17'), 'catch_plus'=>$R('7-13'),  'rush'=>$R('-'), 'sack'=>$R('-'), 'int'=>$R('-'), 'tackle'=>$Z(), 'kick'=>$R('-'), 'punt'=>$R('-')],
            ['position'=>'WR', 'depth'=>'WR2', 'real_name'=>'M. Valdes-Scantling', 'catch'=>$R('18'),    'catch_plus'=>$R('14-17'), 'rush'=>$R('-'), 'sack'=>$R('-'), 'int'=>$R('-'), 'tackle'=>$Z(), 'kick'=>$R('-'), 'punt'=>$R('-')],
            ['position'=>'WR', 'depth'=>'WR3', 'real_name'=>'Mecole Hardman',      'catch'=>$R('19'),    'catch_plus'=>$R('18-19'), 'rush'=>$R('-'), 'sack'=>$R('-'), 'int'=>$R('-'), 'tackle'=>$Z(), 'kick'=>$R('-'), 'punt'=>$R('-')],
            ['position'=>'WR', 'depth'=>'WR4', 'real_name'=>'Justin Watson',       'catch'=>$R('20'),    'catch_plus'=>$R('20'),    'rush'=>$R('-'), 'sack'=>$R('-'), 'int'=>$R('-'), 'tackle'=>$Z(), 'kick'=>$R('-'), 'punt'=>$R('-')],

            // Defense (only sack/int shown on your sheet; tackle range not provided => 0)
            ['position'=>'DL', 'depth'=>'DL1', 'real_name'=>'Chris Jones',      'catch'=>$R('-'),'catch_plus'=>$R('-'),'rush'=>$R('-'), 'sack'=>$R('1-7'),   'int'=>$R('-'), 'tackle'=>$R('1'), 'kick'=>$R('-'), 'punt'=>$R('-')],
            ['position'=>'DL', 'depth'=>'DL2', 'real_name'=>'George Karlaftis', 'catch'=>$R('-'),'catch_plus'=>$R('-'),'rush'=>$R('-'), 'sack'=>$R('8-10'),  'int'=>$R('-'), 'tackle'=>$R('2'), 'kick'=>$R('-'), 'punt'=>$R('-')],
            ['position'=>'DL', 'depth'=>'DL3', 'real_name'=>'Frank Clark',      'catch'=>$R('-'),'catch_plus'=>$R('-'),'rush'=>$R('-'), 'sack'=>$R('11-12'), 'int'=>$R('-'), 'tackle'=>$R('3'), 'kick'=>$R('-'), 'punt'=>$R('-')],
            ['position'=>'DL', 'depth'=>'DL4', 'real_name'=>'Khalen Saunders',  'catch'=>$R('-'),'catch_plus'=>$R('-'),'rush'=>$R('-'), 'sack'=>$R('13'),    'int'=>$R('-'), 'tackle'=>$R('4'), 'kick'=>$R('-'), 'punt'=>$R('-')],
            ['position'=>'DL', 'depth'=>'DL5', 'real_name'=>'Carlos Dunlap',    'catch'=>$R('-'),'catch_plus'=>$R('-'),'rush'=>$R('-'), 'sack'=>$R('14-15'), 'int'=>$R('-'), 'tackle'=>$R('5'), 'kick'=>$R('-'), 'punt'=>$R('-')],

            ['position'=>'LB', 'depth'=>'LB1', 'real_name'=>'Nick Bolton',   'catch'=>$R('-'),'catch_plus'=>$R('-'),'rush'=>$R('-'), 'sack'=>$R('16'), 'int'=>$R('1-4'), 'tackle'=>$R('6-9'), 'kick'=>$R('-'), 'punt'=>$R('-')],
            ['position'=>'LB', 'depth'=>'LB2', 'real_name'=>'Willie Gay',    'catch'=>$R('-'),'catch_plus'=>$R('-'),'rush'=>$R('-'), 'sack'=>$R('17'), 'int'=>$R('5-6'), 'tackle'=>$R('10'), 'kick'=>$R('-'), 'punt'=>$R('-')],
            ['position'=>'LB', 'depth'=>'LB3', 'real_name'=>'Darius Harris', 'catch'=>$R('-'),'catch_plus'=>$R('-'),'rush'=>$R('-'), 'sack'=>$R('18'), 'int'=>$R('7'),   'tackle'=>$R('11'), 'kick'=>$R('-'), 'punt'=>$R('-')],
            ['position'=>'LB', 'depth'=>'LB4', 'real_name'=>'Leo Chenal',    'catch'=>$R('-'),'catch_plus'=>$R('-'),'rush'=>$R('-'), 'sack'=>$R('-'),  'int'=>$R('-'),   'tackle'=>$R('12'), 'kick'=>$R('-'), 'punt'=>$R('-')],
            ['position'=>'LB', 'depth'=>'LB5', 'real_name'=>'Jack Cochrane', 'catch'=>$R('-'),'catch_plus'=>$R('-'),'rush'=>$R('-'), 'sack'=>$R('-'),  'int'=>$R('-'),   'tackle'=>$R('-'), 'kick'=>$R('-'), 'punt'=>$R('-')],

            ['position'=>'CB', 'depth'=>'CB1', 'real_name'=>"L'Jarius Sneed", 'catch'=>$R('-'),'catch_plus'=>$R('-'),'rush'=>$R('-'), 'sack'=>$R('19-20'), 'int'=>$R('8-11'),  'tackle'=>$R('13-15'), 'kick'=>$R('-'), 'punt'=>$R('-')],
            ['position'=>'CB', 'depth'=>'CB2', 'real_name'=>'Trent McDuffie',  'catch'=>$R('-'),'catch_plus'=>$R('-'),'rush'=>$R('-'), 'sack'=>$R('-'),     'int'=>$R('12-13'), 'tackle'=>$R('16'), 'kick'=>$R('-'), 'punt'=>$R('-')],

            ['position'=>'S',  'depth'=>'S1', 'real_name'=>'Justin Reid',    'catch'=>$R('-'),'catch_plus'=>$R('-'),'rush'=>$R('-'), 'sack'=>$R('-'), 'int'=>$R('14-15'), 'tackle'=>$R('17-18'), 'kick'=>$R('-'), 'punt'=>$R('-')],
            ['position'=>'S',  'depth'=>'S2', 'real_name'=>'Juan Thornhill', 'catch'=>$R('-'),'catch_plus'=>$R('-'),'rush'=>$R('-'), 'sack'=>$R('-'), 'int'=>$R('16-17'), 'tackle'=>$R('19'), 'kick'=>$R('-'), 'punt'=>$R('-')],

            ['position'=>'DB', 'depth'=>'DB1','real_name'=>'Jaylen Watson',  'catch'=>$R('-'),'catch_plus'=>$R('-'),'rush'=>$R('-'), 'sack'=>$R('-'), 'int'=>$R('18-20'), 'tackle'=>$R('20'), 'kick'=>$R('-'), 'punt'=>$R('-')],

            // Specialists
            ['position'=>'K', 'depth'=>'K', 'real_name'=>'Harrison Butker', 'catch'=>$R('-'),'catch_plus'=>$R('-'),'rush'=>$R('-'), 'sack'=>$R('-'), 'int'=>$R('-'), 'tackle'=>$Z(), 'kick'=>$R('-'), 'punt'=>$R('-')],
            ['position'=>'P', 'depth'=>'P', 'real_name'=>'Tommy Townsend',  'catch'=>$R('-'),'catch_plus'=>$R('-'),'rush'=>$R('-'), 'sack'=>$R('-'), 'int'=>$R('-'), 'tackle'=>$Z(), 'kick'=>$R('-'), 'punt'=>$R('-')],

            // Kick return ranges -> kick_from/to
            ['position'=>'RB', 'depth'=>'KR1', 'kr_depth'=>'KR1', 'real_name'=>'Isiah Pacheco',  'catch'=>$R('-'),'catch_plus'=>$R('-'),'rush'=>$R('-'), 'sack'=>$R('-'),'int'=>$R('-'), 'tackle'=>$Z(), 'kick'=>$R('1-17'),'punt'=>$R('-')],
            ['position'=>'WR', 'depth'=>'KR2', 'kr_depth'=>'KR2', 'real_name'=>'Skyy Moore',     'catch'=>$R('-'),'catch_plus'=>$R('-'),'rush'=>$R('-'), 'sack'=>$R('-'),'int'=>$R('-'), 'tackle'=>$Z(), 'kick'=>$R('18-19'),'punt'=>$R('-')],
            ['position'=>'FB', 'depth'=>'KR3', 'kr_depth'=>'KR3', 'real_name'=>'Michael Burton', 'catch'=>$R('-'),'catch_plus'=>$R('-'),'rush'=>$R('-'), 'sack'=>$R('-'),'int'=>$R('-'), 'tackle'=>$Z(), 'kick'=>$R('20'),'punt'=>$R('-')],

            // Punt return ranges -> punt_from/to
            ['position'=>'WR', 'depth'=>'PR1', 'pr_depth'=>'PR1', 'real_name'=>'Skyy Moore',      'catch'=>$R('-'),'catch_plus'=>$R('-'),'rush'=>$R('-'), 'sack'=>$R('-'),'int'=>$R('-'), 'tackle'=>$Z(), 'kick'=>$R('-'),'punt'=>$R('1-9')],
            ['position'=>'WR', 'depth'=>'PR2', 'pr_depth'=>'PR2', 'real_name'=>'Kadarius Toney', 'catch'=>$R('-'),'catch_plus'=>$R('-'),'rush'=>$R('-'), 'sack'=>$R('-'),'int'=>$R('-'), 'tackle'=>$Z(), 'kick'=>$R('-'),'punt'=>$R('10-16')],
            ['position'=>'WR', 'depth'=>'PR3', 'pr_depth'=>'PR3', 'real_name'=>'Mecole Hardman',  'catch'=>$R('-'),'catch_plus'=>$R('-'),'rush'=>$R('-'), 'sack'=>$R('-'),'int'=>$R('-'), 'tackle'=>$Z(), 'kick'=>$R('-'),'punt'=>$R('17-20')],
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

    private function splitName(string $full): array
    {
        $full = trim($full);
        $parts = preg_split('/\s+/', $full);
        $first = array_shift($parts) ?: 'Player';
        $last  = implode(' ', $parts) ?: 'One';
        return [$first, $last];
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

    /**
     * Unique jersey number per team/year (pivot).
     * $usedNumbers is an array-flip map: [number => true]
     */
    private function uniqueJerseyNumberForTeamYear(string $pos, array $usedNumbers): int
    {
        $pos = strtoupper($pos);

        $ranges = match ($pos) {
            // Offense
            'QB' => [[1, 19]],
            'RB', 'FB', 'WR', 'TE' => [[1, 49], [80, 89]],
            'C', 'G', 'T', 'OL' => [[50, 79]],

            // Defense
            'DE', 'DT', 'NT', 'DL' => [[50, 79], [90, 99]],
            'LB', 'MLB', 'OLB' => [[1, 59], [90, 99]],
            'CB', 'FS', 'SS', 'DB', 'S' => [[1, 49]],

            // Specialists
            'K', 'P' => [[1, 19]],

            default => [[1, 99]],
        };

        $pool = $this->numbersFromRanges($ranges);
        shuffle($pool);

        foreach ($pool as $n) {
            if (!isset($usedNumbers[$n])) {
                return $n;
            }
        }

        // Worst-case fallback if somehow exhausted
        for ($n = 1; $n <= 99; $n++) {
            if (!isset($usedNumbers[$n])) return $n;
        }

        return 99;
    }

    private function numbersFromRanges(array $ranges): array
    {
        $numbers = [];
        foreach ($ranges as [$from, $to]) {
            $numbers = array_merge($numbers, range((int)$from, (int)$to));
        }
        return $numbers;
    }

    private function randomFirstName(): string
    {
//        $pool = ['Alex','Jordan','Taylor','Chris','Drew','Miles','Evan','Noah','Liam','Mason','Carter','Logan','Aiden','Jace','Cole'];
//        return $pool[array_rand($pool)];
        return Faker::create()->firstName;
    }

    private function randomLastName(): string
    {
//        $pool = ['Hayes','Walker','Bennett','Reed','Coleman','Parker','Simmons','Young','Price','Howard','Brooks','James','Foster','Murphy','Turner'];
//        return $pool[array_rand($pool)];
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
                'punt_pooch_yard' => $r(1, 10),
                'punt_pooch' => $r(0, 9),
                'punt_block' => $r(0, 9),
            ]),
            default => $base,
        };
    }
}
