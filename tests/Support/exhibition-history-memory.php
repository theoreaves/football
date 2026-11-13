<?php

require dirname(__DIR__, 2).'/vendor/autoload.php';

use App\Models\Exhibition;
use App\Services\Arcade\ArcadeContext;
use App\Support\ExhibitionHistory;

// Synthetic data only. Exercise raw history dirty comparison without a database;
// normal save events and transactions are covered by persistence integration tests.
$game = new class extends Exhibition
{
    public int $writes = 0;

    public function save(array $options = [])
    {
        if (! array_key_exists('history', $this->getDirty())) {
            throw new RuntimeException('History must be dirty during the explicit save.');
        }
        $this->writes++;
        $this->syncOriginal();

        return true;
    }
};
$frame = [1, 16667, 0, array_fill(0, 22, [8210, 2670, 0, 100, 0, 0, 1]), [8210, 90, 2670, 0, 0]];
$play = ['number' => 1, 'recording' => ['frames' => array_fill(0, 180, $frame)]];
$encoded = json_encode($play, JSON_THROW_ON_ERROR);
$raw = '['.implode(',', array_fill(0, 100, $encoded)).']';
$game->setRawAttributes(['state' => '{}', 'rosters' => '{}', 'history' => $raw, 'home_team_id' => 1, 'away_team_id' => 2, 'world_id' => 3], true);
unset($raw, $frame, $encoded);
$hash = ArcadeContext::exhibitionHash($game);
ExhibitionHistory::append($game, ['number' => 101]);
ExhibitionHistory::replace($game, 0, ['number' => 1, 'corrected' => true]);
echo json_encode(['hash' => $hash, 'count' => ExhibitionHistory::count($game), 'first' => ExhibitionHistory::at($game, 0), 'last' => ExhibitionHistory::last($game), 'writes' => $game->writes, 'bytes' => strlen(ExhibitionHistory::json($game)), 'peak' => memory_get_peak_usage(true)], JSON_THROW_ON_ERROR);
