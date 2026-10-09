<?php

namespace App\Console\Commands;

use App\Models\Player;
use App\Models\World;
use App\Support\CurrentWorld;
use Illuminate\Console\Command;

class RandomizePlayerAppearance extends Command
{
    protected $signature = 'players:randomize-appearance
        {--world= : Required world ID whose players will be updated}
        {--all : Replace existing appearance settings as well as missing ones}
        {--seed= : Reproducible integer seed (optional)}
        {--dry-run : Show counts without updating players}';

    protected $description = 'Give players varied faces and skin tones in the current world.';

    public function handle(): int
    {
        $worldOption = $this->option('world');
        if ($worldOption === null || ! ctype_digit((string) $worldOption) || (int) $worldOption < 1) {
            $this->error('Specify a valid world ID, for example --world=3.');
            return self::FAILURE;
        }

        $worldId = (int) $worldOption;
        if (! World::query()->whereKey($worldId)->exists()) {
            $this->error("World {$worldId} does not exist.");
            return self::FAILURE;
        }

        app(CurrentWorld::class)->id = $worldId;
        $this->info("World ID: {$worldId}");

        $replace = (bool) $this->option('all');
        $dryRun = (bool) $this->option('dry-run');
        $seed = $this->option('seed');
        if ($seed !== null && ! preg_match('/^-?\d+$/', (string) $seed)) {
            $this->error('The --seed option must be an integer.');
            return self::FAILURE;
        }
        $seed = $seed === null ? random_int(1, PHP_INT_MAX) : (int) $seed;
        $this->info('Appearance seed: '.$seed);
        $this->line($replace ? 'Mode: replace existing appearance' : 'Mode: fill missing appearance only');
        $skinTones = ['#edc5a3', '#d9a37f', '#bd845f', '#a76d49', '#8c5536', '#593b2c'];
        $hairColors = ['#171616', '#29241f', '#563625', '#875530', '#ad6b35', '#c49b6b', '#d0c0a6', '#6d6b6a'];
        $eyes = ['#60452f', '#513b24', '#416e8b', '#55724d', '#867647'];
        $options = [
            'head_shape' => ['round', 'oval', 'square', 'wide', 'long'],
            'hair' => ['bald', 'buzz', 'short', 'curly', 'long'],
            'brow' => ['straight', 'angled', 'thick', 'arched'],
            'nose' => ['standard', 'small', 'wide', 'long'],
            'mouth' => ['neutral', 'wide', 'thin', 'smile'],
            'beard' => ['none', 'none', 'none', 'stubble', 'moustache', 'goatee', 'full'],
            'eye_color' => $eyes,
            'hair_color' => $hairColors,
        ];
        $counts = ['seen' => 0, 'changed' => 0];
        Player::query()->orderBy('id')->chunkById(250, function ($players) use ($options, $skinTones, $seed, $replace, $dryRun, &$counts) {
            foreach ($players as $player) {
                $counts['seen']++;
                $profile = $player->appearance ?? [];
                $updated = $replace ? [] : $profile;
                foreach ($options as $field => $choices) {
                    if (! $replace && ! empty($updated[$field])) continue;
                    $hash = sprintf('%u', crc32("football-appearance:{$seed}:{$player->id}:{$field}"));
                    $updated[$field] = $choices[((int) $hash) % count($choices)];
                }
                $skinHash = sprintf('%u', crc32("football-appearance:{$seed}:{$player->id}:skin"));
                $skin = $skinTones[((int) $skinHash) % count($skinTones)];
                $changes = [];
                if ($updated !== $profile) $changes['appearance'] = $updated;
                if ($replace || ! $player->skin_tone) {
                    if ($player->skin_tone !== $skin) $changes['skin_tone'] = $skin;
                }
                if (! $changes) continue;
                $counts['changed']++;
                if (! $dryRun) $player->update($changes);
            }
        });
        $this->info("Players scanned: {$counts['seen']}; ".($dryRun ? 'would update' : 'updated').": {$counts['changed']}");
        $this->warn('Existing game snapshots are not changed. Start a NEW game to see updated portraits.');
        return self::SUCCESS;
    }
}
