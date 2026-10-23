<?php

namespace App\Jobs;

use App\Services\Seasons\QueuedPlayoffGames;
use App\Support\CurrentWorld;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

class DispatchCpuPlayoffRound implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;
    public int $timeout = 60;
    public int $tries = 3;
    public array $backoff = [15, 60];
    public function __construct(public int $worldId, public int $seasonId, public array $tasks) {}
    public function handle(): void
    {
        foreach ($this->tasks as $task) {
            SimulatePlayoffGame::dispatch($this->worldId, $this->seasonId, $task['round'], $task['conference'], $task['index'], $task['token'])->onConnection('database');
        }
    }
    public function failed(?Throwable $error): void
    {
        $context = app(CurrentWorld::class);
        $previous = $context->id;
        try {
            $context->id = $this->worldId;
            foreach ($this->tasks as $task) {
                app(QueuedPlayoffGames::class)->mark($this->seasonId, $task['round'], $task['conference'], $task['index'], $task['token'], 'failed');
            }
        } finally {
            $context->id = $previous;
        }
    }
}
