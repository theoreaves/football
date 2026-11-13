<?php

// Independent HTTP-kernel worker. All writes target the test's disposable SQLite file.
require dirname(__DIR__, 2).'/vendor/autoload.php';

use App\Models\ArcadeFlowReceipt;
use App\Models\ArcadeGameFlow;
use App\Models\Exhibition;
use App\Models\User;
use App\Services\Arcade\ExhibitionFlow;
use App\Support\CurrentWorld;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

$input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$db = $input['database'];
if (! is_file($db) || ! str_starts_with(basename(dirname($db)), 'arcade-race-')) {
    throw new RuntimeException('Memory worker requires its disposable database.');
}
putenv('APP_ENV=testing');
// Measure normal request memory. Laravel's debug exception query collector retains
// complete SQL bindings (including each historical JSON rewrite) independently.
putenv('APP_DEBUG=false');
putenv('DB_CONNECTION=sqlite');
putenv('DB_DATABASE='.$db);
putenv('DB_URL');
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $db,
    'database.connections.sqlite.url' => null, 'cache.default' => 'array',
    'session.driver' => 'array', 'arcade.enabled' => true]);
DB::purge('sqlite');
app(CurrentWorld::class)->id = $input['world'];
$game = Exhibition::findOrFail($input['game']);

if ($input['action'] === 'seed') {
    // Storage-growth fixture, not a claim of real gameplay or valid submission evidence.
    $frame = [1, 16667, 0, array_fill(0, 22, [8210, 2670, 0, 100, 0, 0, 1]), [8210, 90, 2670, 0, 0]];
    $recording = ['frames' => array_fill(0, 180, $frame)];
    $history = array_fill(0, 13, ['summary' => 'Synthetic saved recording', 'arcade_recording' => $recording, 'animation' => ['recording' => $recording]]);
    $state = $game->state;
    $state['possession'] = 'away';
    $state['phase'] = 'scrimmage';
    $state['clock'] = 900;
    $state['quarter_length'] = 900;
    $state['away_score'] = 0;
    $state['spot'] = 25;
    $state['version'] = 13;
    $game->update(['state' => $state, 'history' => $history]);
    ArcadeGameFlow::create(['exhibition_id' => $game->id, 'human_side' => 'home', 'defense_mode' => 'fast']);
    unset($frame, $recording, $history);
    $flow = app(ExhibitionFlow::class)->status($game)['flow'];
    $body = json_encode(['command_id' => (string) Str::uuid(), 'version' => 13, 'flow_revision' => $flow['revision'], 'state_hash' => $flow['state_hash'], 'action' => 'fast'], JSON_THROW_ON_ERROR);
    file_put_contents(dirname($db).'/body.json', $body);
    echo json_encode(['seeded' => true], JSON_THROW_ON_ERROR);
    exit;
}

if ($input['action'] === 'inspect') {
    $history = $game->history;
    $prefix = array_slice($history, 0, 13);
    echo json_encode(['version' => $game->state['version'], 'history_count' => count($history),
        'prefix_hash' => hash('sha256', json_encode($prefix, JSON_THROW_ON_ERROR)),
        'receipts' => ArcadeFlowReceipt::where('exhibition_id', $game->id)->count()], JSON_THROW_ON_ERROR);
    exit;
}

$body = file_get_contents(dirname($db).'/body.json');
$url = route('exhibitions.arcade.flow', $game);
unset($game);
auth()->setUser(User::findOrFail($input['user']));
$app->instance('middleware.disable', false);
app('session')->start();
app('session')->put('current_world_id', $input['world']);
$request = Request::create($url, 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], $body);
$request->setLaravelSession(app('session')->driver());
$response = $app->make(Kernel::class)->handle($request);
$result = json_decode($response->getContent(), true, 512, JSON_THROW_ON_ERROR);
echo json_encode(['app_debug' => (bool) config('app.debug'), 'status' => $response->getStatusCode(), 'peak' => memory_get_peak_usage(true),
    'body_hash' => hash('sha256', $body), 'receipt' => $result['receipt'] ?? null,
    'replayed' => $result['replayed'] ?? null, 'phase' => $result['flow']['phase'] ?? null], JSON_THROW_ON_ERROR);
