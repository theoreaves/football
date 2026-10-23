<?php

namespace App\Jobs;

use App\Services\Seasons\QueuedPlayoffGames;
use App\Support\CurrentWorld;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

class SimulatePlayoffGame implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;
    public int $timeout = 300;
    public int $tries = 5;
    public int $uniqueFor = 3600;
    public array $backoff = [15, 60, 120];
    public function __construct(public int $worldId, public int $seasonId, public int $round, public string $conference, public int $index, public string $token) {}
    public function uniqueId(): string { return "{$this->worldId}:{$this->seasonId}:{$this->round}:{$this->conference}:{$this->index}:{$this->token}"; }
    public function middleware(): array
    {
        return [(new WithoutOverlapping("playoff:{$this->worldId}:{$this->seasonId}:{$this->round}:{$this->conference}:{$this->index}"))->shared()->releaseAfter(30)->expireAfter(330)];
    }
    public function handle(QueuedPlayoffGames $games): void
    {
        $context = app(CurrentWorld::class);
        $previous = $context->id;
        try {
            $context->id = $this->worldId;
            $games->run($this->seasonId, $this->round, $this->conference, $this->index, $this->token);
        } catch (Throwable $error) {
            $games->mark($this->seasonId, $this->round, $this->conference, $this->index, $this->token, 'queued');
            throw $error;
        } finally { $context->id = $previous; }
    }
    public function failed(?Throwable $error): void
    {
        $context = app(CurrentWorld::class);
        $previous = $context->id;
        try {
            $context->id = $this->worldId;
            app(QueuedPlayoffGames::class)->mark($this->seasonId, $this->round, $this->conference, $this->index, $this->token, 'failed');
        } finally { $context->id = $previous; }
    }
}
