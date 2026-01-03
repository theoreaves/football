<?php

namespace App\Console\Commands;

use App\Models\OffensePlay;
use App\Models\OffensePlayRoll;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SeedOffensePlays extends Command
{
    protected $signature = 'offense-plays:seed {--fresh : Delete existing plays/rolls first}';
    protected $description = 'Populate offense_plays and offense_play_rolls from config/offense_plays.php';

    public function handle(): int
    {
        $data = config('offense_plays');

        if (!is_array($data) || empty($data)) {
            $this->error('config(offense_plays) is empty or missing. Create config/offense_plays.php');
            return self::FAILURE;
        }

        DB::transaction(function () use ($data) {

            if ($this->option('fresh')) {
                // Order matters due to FK
                OffensePlayRoll::query()->delete();
                OffensePlay::query()->delete();
            }

            foreach ($data as $code => $playDef) {
                $play = OffensePlay::query()->updateOrCreate(
                    ['code' => (string) $code],
                    ['name' => (string) ($playDef['name'] ?? $code),
                    'play_type' => (string) ($playDef['play_type'] ?? $code)]
                );

                $rolls = $playDef['roll'] ?? [];
                if (!is_array($rolls)) {
                    continue;
                }

                $sort = 1;

                foreach ($rolls as $rollLabel => $row) {
                    [$min, $max] = $this->parseRollLabel((string) $rollLabel);

                    OffensePlayRoll::query()->updateOrCreate(
                        [
                            'offense_play_id' => $play->id,
                            'roll_label'      => (string) $rollLabel,
                        ],
                        [
                            'roll_min'   => $min,
                            'roll_max'   => $max,
                            'player'     => (string) ($row['player'] ?? ''),
                            'rating'     => (string) ($row['rating'] ?? ''),
                            'skill_pass' => (string) ($row['skill_pass'] ?? ''),
                            'skill_fail' => (string) ($row['skill_fail'] ?? ''),
                            'sort_order' => $sort++,
                        ]
                    );
                }
            }
        });

        $this->info('Offense plays seeded successfully.');
        return self::SUCCESS;
    }

    private function parseRollLabel(string $label): array
    {
        $label = trim($label);

        // "11-12" => [11, 12]
        if (preg_match('/^(\d+)\s*-\s*(\d+)$/', $label, $m)) {
            $a = (int) $m[1];
            $b = (int) $m[2];
            return [$a, $b];
        }

        // "10" => [10, 10]
        if (preg_match('/^\d+$/', $label)) {
            $n = (int) $label;
            return [$n, $n];
        }

        // If something unexpected shows up, store as 0 range rather than failing
        return [0, 0];
    }
}
