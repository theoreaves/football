<?php

namespace App\Livewire;

use App\Models\Season;
use App\Services\Seasons\QueuedPlayoffGames;
use Livewire\Attributes\Locked;
use Livewire\Component;

class PlayoffQueuePanel extends Component
{
    #[Locked]
    public int $seasonId;
    public string $message = '';

    public function mount(int $seasonId): void { $this->seasonId = $seasonId; }

    public function queueGames(): void
    {
        $season = Season::findOrFail($this->seasonId);
        $round = (int) ($season->settings['playoff_bracket']['current_round'] ?? 1);
        $count = app(QueuedPlayoffGames::class)->queue($season, $round);
        $this->message = $count ? "Queued {$count} playoff game(s). You can leave this page while they finish." : 'No eligible CPU playoff games remain.';
    }

    public function render()
    {
        $season = Season::findOrFail($this->seasonId);
        $queue = app(QueuedPlayoffGames::class);
        $entries = $queue->entries($season);
        $active = collect($entries)->contains(fn ($entry) => in_array($entry['game']['simulation_status'] ?? null, ['queued', 'running'], true));
        return view('livewire.playoff-queue-panel', [
            'season' => $season, 'active' => $active,
            'eligible' => count($queue->eligible($season)),
            'queued' => collect($entries)->where('game.simulation_status', 'queued')->count(),
            'running' => collect($entries)->where('game.simulation_status', 'running')->count(),
            'failed' => collect($entries)->where('game.simulation_status', 'failed')->count(),
        ]);
    }
}
