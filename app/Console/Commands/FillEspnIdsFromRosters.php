<?php

namespace App\Console\Commands;

use App\Models\Player;
use App\Models\Team;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class FillEspnIdsFromRosters extends Command
{
    protected $signature = 'football:fill-espn-ids-from-rosters
        {--year=2025 : team_year to use for team_players context + ESPN season param}
        {--limit=0 : limit number of players processed (0 = no limit)}
        {--dry-run : do not write changes}
        {--active-only : only consider players that have a team_players row for this year}
        {--name-only : if a player has no team for the year, try name-only unique match across all rosters}
    ';

    protected $description = 'Backfill players.espn_id (NULL) by indexing ESPN team rosters and matching by team/name/position';

    public function handle(): int
    {
        $year = (string) $this->option('year');
        $limit = (int) $this->option('limit');
        $dryRun = (bool) $this->option('dry-run');
        $activeOnly = (bool) $this->option('active-only');
        $nameOnly = (bool) $this->option('name-only');

        // Your team map: team_id -> abbr
        $teamAbbrById = Team::query()
            ->select(['id', 'abbr'])
            ->whereNotNull('abbr')
            ->get()
            ->mapWithKeys(fn ($t) => [(int) $t->id => strtoupper(trim($t->abbr))]);

        if ($teamAbbrById->isEmpty()) {
            $this->error("No teams found with non-null 'abbr'. Seed/update teams first.");
            return self::FAILURE;
        }

        // Build ESPN roster index
        $this->info("Building ESPN roster index for season={$year}...");
        $index = $this->buildEspnRosterIndex($year);

        if ($index === null) {
            $this->error("Failed to build ESPN roster index.");
            return self::FAILURE;
        }

        $this->info("Index built. Teams indexed: " . count($index['by_team']));

        // Pull players missing espn_id
        $q = Player::query()->whereNull('espn_id');

        if ($activeOnly) {
            $q->whereExists(function ($sub) use ($year) {
                $sub->select(DB::raw(1))
                    ->from('team_players')
                    ->whereColumn('team_players.player_id', 'players.id')
                    ->where('team_players.team_year', $year);
            });
        }

        $players = $q->orderBy('id')->get();

        if ($players->isEmpty()) {
            $this->info("No players found with espn_id NULL.");
            return self::SUCCESS;
        }

        $processed = $updated = $ambiguous = $noMatch = $skippedNoTeam = 0;

        foreach ($players as $player) {
            if ($limit > 0 && $processed >= $limit) break;

            // Use roster/pivot context for this year
            $ctx = DB::table('team_players')
                ->where('player_id', $player->id)
                ->where('team_year', $year)
                ->orderByDesc('updated_at')
                ->orderByDesc('id')
                ->first();

            $teamAbbr = $ctx?->team_id ? ($teamAbbrById[(int)$ctx->team_id] ?? null) : null;

            // Prefer team_players position (team context), fall back to players.position
            $pos = strtoupper(trim((string)($ctx->position ?? $player->position ?? '')));

            $nameKey = $this->nameKey($player->firstname, $player->lastname);

            $match = null;

            // 1) Best: team + name + pos
            if ($teamAbbr) {
                $match = $this->matchTeamNamePos($index, $teamAbbr, $nameKey, $pos);

                // 2) Next: team + name (unique across positions)
                if ($match === null) {
                    $match = $this->matchTeamNameUnique($index, $teamAbbr, $nameKey);
                }
            } else {
                $skippedNoTeam++;
                if ($nameOnly) {
                    // 3) Fallback: name-only unique across all rosters
                    $match = $this->matchNameOnlyUnique($index, $nameKey);
                }
            }

            if ($match && $match['status'] === 'ok') {
                $espnId = (string)$match['id'];

                if (!$dryRun) {
                    $player->espn_id = $espnId;
                    $player->save();
                }

                $updated++;
                $this->line("✔ {$player->id} {$player->firstname} {$player->lastname}"
                    . ($teamAbbr ? " ({$teamAbbr})" : '')
                    . ($pos ? " {$pos}" : '')
                    . " -> ESPN {$espnId}"
                );
            } elseif ($match && $match['status'] === 'ambiguous') {
                $ambiguous++;
                $this->warn("— AMBIGUOUS {$player->id} {$player->firstname} {$player->lastname}"
                    . ($teamAbbr ? " ({$teamAbbr})" : '')
                    . ($pos ? " {$pos}" : '')
                    . " -> " . implode(', ', array_slice($match['candidates'], 0, 8))
                );
            } else {
                $noMatch++;
                $this->warn("— NO MATCH {$player->id} {$player->firstname} {$player->lastname}"
                    . ($teamAbbr ? " ({$teamAbbr})" : '')
                    . ($pos ? " {$pos}" : '')
                );
            }

            $processed++;
        }

        $this->info("Done. processed={$processed}, updated={$updated}, ambiguous={$ambiguous}, no_match={$noMatch}, skipped_no_team={$skippedNoTeam}"
            . ($dryRun ? " (dry-run)" : "")
        );

        return self::SUCCESS;
    }

    /**
     * Build a roster index from ESPN team endpoints.
     *
     * Team list: https://site.api.espn.com/apis/site/v2/sports/football/nfl/teams :contentReference[oaicite:2]{index=2}
     * Team detail roster: /teams/{id}?enable=roster (community-discovered) :contentReference[oaicite:3]{index=3}
     */
    private function buildEspnRosterIndex(string $seasonYear): ?array
    {
        $teamsResp = Http::timeout(60)->retry(3, 500)->acceptJson()
            ->get('https://site.api.espn.com/apis/site/v2/sports/football/nfl/teams');

        if (!$teamsResp->ok()) return null;

        $teamsJson = $teamsResp->json();
        if (!is_array($teamsJson)) return null;

        // teams are typically at sports[0].leagues[0].teams[*].team
        $teams = $teamsJson['sports'][0]['leagues'][0]['teams'] ?? null;
        if (!is_array($teams)) return null;

        $byTeam = [];
        $byNameGlobal = [];

        foreach ($teams as $tWrap) {
            $t = $tWrap['team'] ?? null;
            if (!is_array($t)) continue;

            $teamId = $t['id'] ?? null;
            $abbr = $t['abbreviation'] ?? null;

            if (!$teamId || !$abbr) continue;

            $abbr = strtoupper(trim((string)$abbr));

            // Team endpoint with roster enabled
            $teamUrl = "https://site.api.espn.com/apis/site/v2/sports/football/nfl/teams/{$teamId}";
            $teamResp = Http::timeout(60)->retry(3, 500)->acceptJson()
                ->get($teamUrl, [
                    'enable' => 'roster',
                    'season' => $seasonYear, // ESPN generally accepts this; if ignored, it will return current roster
                ]);

            if (!$teamResp->ok()) {
                $this->warn("Failed roster for {$abbr} (teamId={$teamId}): HTTP {$teamResp->status()}");
                continue;
            }

            $teamJson = $teamResp->json();
            if (!is_array($teamJson)) continue;

            // Typical shape: team.roster.athletes is an array of position groups
            $athleteGroups = $teamJson['team']['roster']['athletes'] ?? null;

            if (!is_array($athleteGroups)) {
                // Some responses use team.athletes or different nesting; try a couple fallbacks.
                $athleteGroups = $teamJson['team']['athletes'] ?? $teamJson['athletes'] ?? null;
            }

            if (!is_array($athleteGroups)) {
                $this->warn("No roster athletes found for {$abbr} (teamId={$teamId}).");
                continue;
            }

            foreach ($athleteGroups as $group) {
                // if it's a grouped format, athletes might be under 'items'
                $items = $group['items'] ?? $group['athletes'] ?? null;
                if (!is_array($items)) {
                    // Sometimes it's already a flat athlete item list
                    $items = is_array($group) && isset($group['id'], $group['fullName']) ? [$group] : null;
                }
                if (!is_array($items)) continue;

                foreach ($items as $a) {
                    if (!is_array($a)) continue;

                    // ESPN roster item formats vary:
                    // - athlete.id + athlete.displayName
                    // - id + fullName
                    $ath = $a['athlete'] ?? $a;

                    if (!is_array($ath)) continue;

                    $id = $ath['id'] ?? null;
                    $full = $ath['fullName'] ?? $ath['displayName'] ?? $ath['shortName'] ?? null;

                    if (!$id || !$full) continue;

                    $pos = $ath['position']['abbreviation'] ?? $ath['position']['name'] ?? ($a['position'] ?? null);
                    $pos = $pos ? strtoupper(trim((string)$pos)) : null;

                    // normalize common ESPN position names to your abbreviations if needed
                    $pos = $this->normalizePos($pos);

                    $nameKey = $this->fullNameKey((string)$full);

                    $byTeam[$abbr] ??= [];
                    $byTeam[$abbr][$nameKey] ??= [];
                    $byTeam[$abbr][$nameKey][$pos ?? 'UNK'] ??= [];

                    // store candidates (some names can appear twice; we keep list)
                    $byTeam[$abbr][$nameKey][$pos ?? 'UNK'][] = (string)$id;

                    $byNameGlobal[$nameKey] ??= [];
                    $byNameGlobal[$nameKey][] = "{$abbr}/" . ($pos ?? 'UNK') . "/" . (string)$id;
                }
            }

            $this->line("Indexed roster: {$abbr}");
        }

        return [
            'by_team' => $byTeam,
            'by_name_global' => $byNameGlobal,
        ];
    }

    private function matchTeamNamePos(array $index, string $teamAbbr, string $nameKey, string $pos): ?array
    {
        $teamAbbr = strtoupper(trim($teamAbbr));
        $pos = $this->normalizePos(strtoupper(trim($pos)));

        $rows = $index['by_team'][$teamAbbr][$nameKey] ?? null;
        if (!is_array($rows)) return null;

        // exact position bucket
        $ids = $rows[$pos] ?? null;
        if (is_array($ids) && count($ids) === 1) {
            return ['status' => 'ok', 'id' => $ids[0]];
        }

        // If we found multiple for exact pos, ambiguous
        if (is_array($ids) && count($ids) > 1) {
            return ['status' => 'ambiguous', 'candidates' => array_map(fn($id) => "{$teamAbbr}/{$pos}/{$id}", $ids)];
        }

        return null;
    }

    private function matchTeamNameUnique(array $index, string $teamAbbr, string $nameKey): ?array
    {
        $teamAbbr = strtoupper(trim($teamAbbr));
        $rows = $index['by_team'][$teamAbbr][$nameKey] ?? null;
        if (!is_array($rows)) return null;

        // Flatten all ids under that team+name across positions
        $flat = [];
        foreach ($rows as $pos => $ids) {
            foreach ((array)$ids as $id) {
                $flat[] = "{$teamAbbr}/{$pos}/{$id}";
            }
        }

        $uniqueIds = array_values(array_unique($flat));
        if (count($uniqueIds) === 1) {
            // parse last segment as id
            $parts = explode('/', $uniqueIds[0]);
            return ['status' => 'ok', 'id' => $parts[2] ?? null];
        }

        if (count($uniqueIds) > 1) {
            return ['status' => 'ambiguous', 'candidates' => $uniqueIds];
        }

        return null;
    }

    private function matchNameOnlyUnique(array $index, string $nameKey): ?array
    {
        $cands = $index['by_name_global'][$nameKey] ?? [];
        $cands = array_values(array_unique($cands));

        if (count($cands) === 1) {
            $parts = explode('/', $cands[0]);
            return ['status' => 'ok', 'id' => $parts[2] ?? null];
        }

        if (count($cands) > 1) {
            return ['status' => 'ambiguous', 'candidates' => $cands];
        }

        return null;
    }

    private function nameKey(?string $first, ?string $last): string
    {
        return $this->fullNameKey(trim((string)$first . ' ' . (string)$last));
    }

    private function fullNameKey(string $fullName): string
    {
        $n = strtolower(trim($fullName));

        // remove punctuation
        $n = preg_replace('/[^a-z0-9\s]/', '', $n) ?? $n;

        // remove suffixes
        $suffixes = [' jr', ' sr', ' ii', ' iii', ' iv', ' v'];
        foreach ($suffixes as $suf) {
            if (str_ends_with($n, $suf)) {
                $n = trim(substr($n, 0, -strlen($suf)));
                break;
            }
        }

        // collapse whitespace
        $n = preg_replace('/\s+/', ' ', $n) ?? $n;

        return $n;
    }

    private function normalizePos(?string $pos): string
    {
        $pos = strtoupper(trim((string)$pos));
        if ($pos === '') return 'UNK';

        // Common ESPN variants -> your abbreviations
        return match ($pos) {
            'HB' => 'RB',
            'FB' => 'RB',
            'CB' => 'CB',
            'SS', 'FS' => 'S',
            default => $pos,
        };
    }
}
