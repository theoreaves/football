<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SetDepthChart extends Command
{
    protected $signature = 'football:set-depth-chart
        {--year=2025 : team_year to update}
        {--team= : optional team_id to limit to one team}
        {--fresh : overwrite existing depth_chart_position values}
        {--order=jersey : ordering within position: jersey|name|id}
    ';

    protected $description = 'Populate team_players.depth_chart_position as POS1, POS2, POS3... per team and position';

    public function handle(): int
    {
        $year = (string) $this->option('year');
        $teamId = $this->option('team');
        $fresh = (bool) $this->option('fresh');
        $order = (string) $this->option('order');

        if (!in_array($order, ['jersey', 'name', 'id'], true)) {
            $this->error("Invalid --order value. Use: jersey|name|id");
            return self::FAILURE;
        }

        // Build the base query (team_players joined to players for sorting by name when needed)
        $q = DB::table('team_players as tp')
            ->join('players as p', 'p.id', '=', 'tp.player_id')
            ->select([
                'tp.id',
                'tp.team_id',
                'tp.player_id',
                'tp.team_year',
                'tp.position as tp_position',
                'tp.depth_chart_position',
                'tp.jersey_number',
                'p.firstname',
                'p.lastname',
            ])
            ->where('tp.team_year', $year);

        if ($teamId !== null && $teamId !== '') {
            $q->where('tp.team_id', (int) $teamId);
        }

        if (!$fresh) {
            // Only set for blanks (null or empty string)
            $q->where(function ($w) {
                $w->whereNull('tp.depth_chart_position')
                    ->orWhere('tp.depth_chart_position', '=', '');
            });
        }

        // Pull into memory so we can rank per team/position
        $rows = $q->get();

        if ($rows->isEmpty()) {
            $this->info('No rows found to update.');
            return self::SUCCESS;
        }

        // Group by team, then by position
        $byTeam = $rows->groupBy('team_id');

        $updated = 0;

        DB::beginTransaction();
        try {
            foreach ($byTeam as $team_id => $teamRows) {
                $byPos = $teamRows->groupBy(function ($r) {
                    // normalize position text
                    return strtoupper(trim((string) $r->tp_position));
                });

                foreach ($byPos as $pos => $posRows) {
                    // Sort within this position group
                    $sorted = $posRows->sort(function ($a, $b) use ($order) {
                        if ($order === 'jersey') {
                            $aj = $a->jersey_number ?? 9999;
                            $bj = $b->jersey_number ?? 9999;
                            if ($aj !== $bj) return $aj <=> $bj;

                            $al = mb_strtolower(trim($a->lastname ?? ''));
                            $bl = mb_strtolower(trim($b->lastname ?? ''));
                            if ($al !== $bl) return $al <=> $bl;

                            $af = mb_strtolower(trim($a->firstname ?? ''));
                            $bf = mb_strtolower(trim($b->firstname ?? ''));
                            if ($af !== $bf) return $af <=> $bf;

                            return ((int) $a->player_id) <=> ((int) $b->player_id);
                        }

                        if ($order === 'name') {
                            $al = mb_strtolower(trim($a->lastname ?? ''));
                            $bl = mb_strtolower(trim($b->lastname ?? ''));
                            if ($al !== $bl) return $al <=> $bl;

                            $af = mb_strtolower(trim($a->firstname ?? ''));
                            $bf = mb_strtolower(trim($b->firstname ?? ''));
                            if ($af !== $bf) return $af <=> $bf;

                            $aj = $a->jersey_number ?? 9999;
                            $bj = $b->jersey_number ?? 9999;
                            if ($aj !== $bj) return $aj <=> $bj;

                            return ((int) $a->player_id) <=> ((int) $b->player_id);
                        }

                        // id
                        return ((int) $a->player_id) <=> ((int) $b->player_id);
                    })->values();

                    // Assign POS1, POS2, POS3...
                    $i = 1;
                    foreach ($sorted as $r) {
                        $new = $pos . $i;

                        // Avoid unnecessary writes
                        if ((string) $r->depth_chart_position !== $new) {
                            DB::table('team_players')
                                ->where('id', (int) $r->id)
                                ->update([
                                    'depth_chart_position' => $new,
                                    'updated_at' => now(),
                                ]);
                            $updated++;
                        }

                        $i++;
                    }
                }
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        $this->info("Updated depth_chart_position for {$updated} rows (year {$year}).");

        return self::SUCCESS;
    }
}
