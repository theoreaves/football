<?php

namespace App\Jobs;

use App\Services\Seasons\QueuedSeasonGames;
use App\Support\CurrentWorld;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

class SimulateSeasonGame implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 300;

    public int $tries = 5;

    public int $uniqueFor = 3600;

    public array $backoff = [15, 60, 120];

    public function __construct(public int $worldId, public int $seasonId, public int $fixtureId, public string $token) {}

    public function uniqueId(): string
    {
        return $this->worldId.':'.$this->fixtureId.':'.$this->token;
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('season-game:'.$this->worldId.':'.$this->fixtureId))->shared()->releaseAfter(30)->expireAfter(330)];
    }

    public function handle(QueuedSeasonGames $games): void
    {
        $context = app(CurrentWorld::class);
        $previous = $context->id;
        try {
            $context->id = $this->worldId;
            $games->run($this->seasonId, $this->fixtureId, $this->token);
        } catch (Throwable $error) {
            $games->mark($this->fixtureId, $this->token, 'queued');
            throw $error;
        } finally {
            $context->id = $previous;
        }
    }

    public function failed(?Throwable $error): void
    {
        $context = app(CurrentWorld::class);
        $previous = $context->id;
        try {
            $context->id = $this->worldId;
            app(QueuedSeasonGames::class)->mark($this->fixtureId, $this->token, 'failed');
        } finally {
            $context->id = $previous;
        }
    }
}
