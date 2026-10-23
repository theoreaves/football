<?php

namespace App\Livewire;

use App\Models\Season;
use App\Models\Team;
use App\Services\Seasons\QueuedSeasonGames;
use Livewire\Attributes\Locked;
use Livewire\Component;

class SeasonWeekGames extends Component
{
    #[Locked]
    public int $seasonId;

    #[Locked]
    public string $tab = 'overview';

    #[Locked]
    public int $week;

    public string $message = '';

    public function mount(int $seasonId, string $tab = 'overview'): void
    {
        $this->seasonId = $seasonId;
        $this->tab = $tab === 'schedule' ? 'schedule' : 'overview';
        $this->week = (int) Season::findOrFail($seasonId)->current_week;
    }

    public function queueGames(): void
    {
        $count = app(QueuedSeasonGames::class)->queue(Season::findOrFail($this->seasonId), $this->week);
        $this->message = $count ? "Queued {$count} CPU game(s). You can leave this page while they finish." : 'No unstarted CPU vs CPU games remain this week.';
    }

    public function render()
    {
        $season = Season::findOrFail($this->seasonId);
        $this->week = (int) $season->current_week;
        $fixtures = $season->fixtures()->orderBy('week')->orderBy('id')->get();

        return view('livewire.season-week-games', [
            'season' => $season, 'fixtures' => $fixtures, 'tab' => $this->tab,
            'active' => $fixtures->contains(fn ($game) => in_array($game->simulation_status, ['queued', 'running'], true)),
            'cpuGames' => app(QueuedSeasonGames::class)->eligible($season)->count(),
            'records' => app(\App\Services\Seasons\SeasonGames::class)->standings($season),
            'logos' => Team::whereIn('id', array_keys($season->settings['members']))->whereNotNull('team_logo')->get(['id', 'team_logo'])
                ->filter(fn ($team) => (bool) $team->team_logo)->mapWithKeys(fn ($team) => [$team->id => route('teams.art', ['team' => $team, 'asset' => 'team_logo'])]),
        ]);
    }
}
