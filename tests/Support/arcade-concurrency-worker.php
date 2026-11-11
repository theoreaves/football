<?php

// Test-only subprocess. All connections are pinned to the parent's disposable SQLite file.
require dirname(__DIR__, 2).'/vendor/autoload.php';

use App\Models\ArcadePlaySession;
use App\Models\Exhibition;
use App\Models\Team;
use App\Models\User;
use App\Models\World;
use App\Services\Arcade\ExhibitionSessions;
use App\Services\Simulation\ExhibitionEngine;
use App\Services\Simulation\PlayerRatings;
use App\Services\Simulation\RosterBuilder;
use App\Support\CurrentWorld;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

$input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$db = $input['database'];
if (! is_file($db) || ! str_starts_with(basename(dirname($db)), 'arcade-race-')) {
    throw new RuntimeException('Concurrency worker requires its disposable database.');
}
putenv('APP_ENV=testing');
putenv('DB_CONNECTION=sqlite');
putenv('DB_DATABASE='.$db);
putenv('DB_URL');
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $db,
    'database.connections.sqlite.url' => null, 'database.connections.sqlite.busy_timeout' => 5000,
    'cache.default' => 'array', 'session.driver' => 'array', 'arcade.enabled' => true]);
DB::purge('sqlite');
try {
    if ($input['action'] === 'init') {
        Artisan::call('migrate:fresh', ['--force' => true]);
        $user = User::factory()->create();
        $world = World::create(['name' => 'Disposable concurrency world', 'owner_user_id' => $user->id]);
        app(CurrentWorld::class)->id = $world->id;
        $home = Team::create(['city' => 'Home', 'name' => 'Race']);
        $away = Team::create(['city' => 'Away', 'name' => 'Race']);
        $rosters = [];
        $id = 1;
        foreach (['home', 'away'] as $side) {
            foreach (RosterBuilder::GROUPS as $role => $positions) {
                $rosters[$side]['players'][$role] = ['id' => $id++, 'name' => $side.' '.$role, 'number' => $id,
                    'position' => $positions[0], 'depth' => $role, 'ratings' => array_fill_keys(PlayerRatings::FIELDS, 70),
                    'appearance' => [], 'height_inches' => 72, 'weight_pounds' => 220];
            }
        }
        $state = app(ExhibitionEngine::class)->initial(180, 1234, false);
        $state['controls'] = ['home' => 'human', 'away' => 'cpu'];
        $state['rules'] = ['penalties' => false, 'injuries' => false];
        $game = Exhibition::create(['home_team_id' => $home->id, 'away_team_id' => $away->id,
            'rosters' => $rosters, 'state' => $state, 'history' => []]);
        $result = ['game' => $game->id, 'world' => $world->id, 'user' => $user->id];
    } else {
        app(CurrentWorld::class)->id = $input['world'];
        auth()->loginUsingId($input['user']);
        $game = Exhibition::findOrFail($input['game']);
        if (isset($input['barrier'])) {
            file_put_contents($input['ready'], (string) getmypid());
            $deadline = microtime(true) + 15;
            while (! is_file($input['barrier'])) {
                if (microtime(true) > $deadline) {
                    throw new RuntimeException('Timed out waiting for race barrier.');
                }
                usleep(1000);
            }
        }
        $service = app(ExhibitionSessions::class);
        $result = match ($input['action']) {
            'flow_status' => app(App\Services\Arcade\ExhibitionFlow::class)->status($game),
            'flow' => (function () use ($game, $input) {
                $request = Illuminate\Http\Request::create('/', 'POST', $input['data']);
                $request->setUserResolver(fn () => auth()->user());
                $request->setLaravelSession(app('session')->driver());

                return app(App\Services\Arcade\ExhibitionFlow::class)->execute($game, $request);
            })(),
            'start' => $service->start($game, $input['user'], ['version' => 0, 'kind' => 'run', 'tempo' => 'normal']),
            'validate' => (function () use ($input, $game) {
                $session = ArcadePlaySession::findOrFail($input['session']);
                $snap = $session->snapshot;
                $context = new App\Services\Arcade\ArcadeContext($game->id, $input['user'], $game->world_id,
                    $snap['state']['possession'], $snap['state'], $snap['rosters'], $session->setup['actors']);
                app(App\Services\Arcade\LivePlayContract::class)->derive($context, $input['data']['recording']);

                return ['valid' => true];
            })(),
            'submit' => $service->submit($game, $input['user'], $input['session'], $input['data']),
            'cancel' => $service->cancel($game, $input['user'], $input['session']),
            'inspect' => ['state' => $game->state, 'history' => $game->history, 'sessions' => ArcadePlaySession::all()->toArray()],
            'expire' => tap([], fn () => ArcadePlaySession::whereKey($input['session'])->update(['expires_at' => now()->subSecond()])),
            // Representative serialized state mutation, not a mock of Arcade's commit/locking path.
            'mutate' => DB::transaction(function () use ($game) {
                Exhibition::whereKey($game->id)->toBase()->update(['id' => DB::raw('id')]);
                $fresh = Exhibition::whereKey($game->id)->lockForUpdate()->firstOrFail();
                $state = $fresh->state;
                $state['race_marker'] = 'preserved';
                $state['version']++;
                $fresh->update(['state' => $state]);

                return ['version' => $state['version']];
            }),
            default => throw new RuntimeException('Unknown test action.'),
        };
    }
    echo json_encode(['status' => 200, 'pid' => getmypid(), 'result' => $result], JSON_THROW_ON_ERROR);
} catch (Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
    echo json_encode(['status' => $e->getStatusCode(), 'pid' => getmypid(), 'message' => $e->getMessage()], JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    fwrite(STDERR, (string) $e);
    exit(1);
}
