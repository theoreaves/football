<?php

use App\Http\Controllers\TeamEditor;
use App\Http\Controllers\TeamRosterController;
use Illuminate\Support\Facades\Route;

Route::get('/', \App\Http\Controllers\HomeController::class)->middleware('auth.session')->name('home');

Route::get('/auth/google', [\App\Http\Controllers\GoogleAuthController::class, 'redirect'])->middleware('throttle:10,1')->name('google.redirect');
Route::get('/auth/google/callback', [\App\Http\Controllers\GoogleAuthController::class, 'callback'])->middleware('throttle:10,1')->name('google.callback');

Route::middleware('guest')->group(function () {
    Route::get('/login', [\App\Http\Controllers\AuthController::class, 'form'])->name('login');
    Route::post('/login', [\App\Http\Controllers\AuthController::class, 'login'])->middleware('throttle:5,1')->name('login.store');
    Route::get('/register', [\App\Http\Controllers\AuthController::class, 'form'])->name('register');
    Route::post('/register', [\App\Http\Controllers\AuthController::class, 'register'])->middleware('throttle:3,1')->name('register.store');
    Route::get('/forgot-password', [\App\Http\Controllers\AuthController::class, 'form'])->name('password.request');
    Route::post('/forgot-password', [\App\Http\Controllers\AuthController::class, 'sendReset'])->middleware('throttle:3,1')->name('password.email');
    Route::get('/reset-password/{token}', [\App\Http\Controllers\AuthController::class, 'form'])->name('password.reset');
    Route::post('/reset-password', [\App\Http\Controllers\AuthController::class, 'reset'])->middleware('throttle:5,1')->name('password.update');
});
Route::middleware(['auth', 'auth.session'])->group(function () {
    Route::post('/logout', [\App\Http\Controllers\AuthController::class, 'logout'])->name('logout');
    Route::get('/email/verify', [\App\Http\Controllers\AuthController::class, 'form'])->name('verification.notice');
    Route::get('/email/verify/{id}/{hash}', [\App\Http\Controllers\AuthController::class, 'verify'])->middleware(['signed', 'throttle:6,1'])->name('verification.verify');
    Route::post('/email/verification-notification', [\App\Http\Controllers\AuthController::class, 'resend'])->middleware('throttle:3,1')->name('verification.send');
});

Route::middleware(['auth', 'auth.session', 'verified'])->group(function () {
    Route::get('/worlds', [\App\Http\Controllers\WorldController::class, 'index'])->name('worlds.index');
    Route::post('/worlds', [\App\Http\Controllers\WorldController::class, 'store'])->middleware('throttle:5,1')->name('worlds.store');
    Route::post('/worlds/{world}/select', [\App\Http\Controllers\WorldController::class, 'select'])->whereNumber('world')->name('worlds.select');
    Route::post('/worlds/close', [\App\Http\Controllers\WorldController::class, 'close'])->name('worlds.close');
    Route::get('/practice', \App\Http\Controllers\PracticeController::class)->name('practice');

    Route::middleware([\App\Http\Middleware\RequireWorld::class, \App\Http\Middleware\BlockQueuedSeasonGame::class])->group(function () {

        Route::get('/seasons', [\App\Http\Controllers\SeasonController::class, 'index'])->name('seasons.index');
        Route::get('/seasons/create', [\App\Http\Controllers\SeasonController::class, 'create'])->name('seasons.create');
        Route::post('/seasons/preview', [\App\Http\Controllers\SeasonController::class, 'preview'])->name('seasons.preview');
        Route::post('/seasons', [\App\Http\Controllers\SeasonController::class, 'store'])->name('seasons.store');
        Route::get('/seasons/{season}', [\App\Http\Controllers\SeasonController::class, 'show'])->name('seasons.show');
        Route::post('/seasons/{season}/fixtures/{fixture}/game', [\App\Http\Controllers\SeasonController::class, 'game'])->name('seasons.game');
        Route::get('/seasons/{season}/fixtures/{fixture}/box-score', [\App\Http\Controllers\SeasonController::class, 'boxScore'])->name('seasons.box-score');
        Route::post('/seasons/{season}/sim-cpu', [\App\Http\Controllers\SeasonController::class, 'simCpu'])->name('seasons.sim-cpu');
        Route::post('/seasons/{season}/playoffs/bracket', [\App\Http\Controllers\SeasonController::class, 'createPlayoffBracket'])->name('seasons.playoffs.bracket');
        Route::post('/seasons/{season}/playoffs/advance', [\App\Http\Controllers\SeasonController::class, 'advancePlayoffRound'])->name('seasons.playoffs.advance');
        Route::post('/seasons/{season}/playoffs/games/{conference}/{index}', [\App\Http\Controllers\SeasonController::class, 'playoffGame'])->whereNumber('index')->name('seasons.playoffs.game');
        Route::post('/seasons/{season}/advance', [\App\Http\Controllers\SeasonController::class, 'advance'])->name('seasons.advance');
        Route::put('/seasons/{season}/championship-venue', [\App\Http\Controllers\SeasonController::class, 'championshipVenue'])->name('seasons.championship-venue');
        Route::put('/seasons/{season}/rules', [\App\Http\Controllers\SeasonController::class, 'rules'])->name('seasons.rules');
        Route::put('/seasons/{season}/controls', [\App\Http\Controllers\SeasonController::class, 'controls'])->name('seasons.controls');
        Route::get('/seasons/{season}/teams/{team}', [\App\Http\Controllers\SeasonController::class, 'team'])->name('seasons.team');
        Route::put('/seasons/{season}/teams/{team}/depth', [\App\Http\Controllers\SeasonController::class, 'depth'])->name('seasons.depth');
        Route::put('/seasons/{season}/teams/{team}/specialists', [\App\Http\Controllers\SeasonController::class, 'specialists'])->name('seasons.specialists');
        Route::post('/seasons/{season}/teams/{team}/plays', [\App\Http\Controllers\SeasonController::class, 'saveTeamPlay'])->name('seasons.plays.save');
        Route::delete('/seasons/{season}/teams/{team}/plays/{play}', [\App\Http\Controllers\SeasonController::class, 'deleteTeamPlay'])->whereUuid('play')->name('seasons.plays.delete');

        Route::get('/teams/{team}/championship-art/{side}', [\App\Http\Controllers\PracticeController::class, 'championshipArt'])->whereIn('side', ['left', 'right'])->name('teams.championship-art');
        Route::get('/leagues/{league}/logo', [\App\Http\Controllers\LeagueBrandingController::class, 'logo'])->name('leagues.logo');
        Route::put('/leagues/{league}/branding', [\App\Http\Controllers\LeagueBrandingController::class, 'update'])->name('leagues.branding');
        Route::get('/teams/{team}/audio/{clip}', [\App\Http\Controllers\PracticeController::class, 'audio'])->name('teams.audio');
        Route::get('/teams/{team}/art/{asset}', [\App\Http\Controllers\PracticeController::class, 'art'])->name('teams.art');

        Route::get('/exhibitions', [\App\Http\Controllers\ExhibitionController::class, 'index'])->name('exhibitions.index');
        Route::post('/exhibitions', [\App\Http\Controllers\ExhibitionController::class, 'store'])->name('exhibitions.store');
        Route::get('/exhibitions/{exhibition}', [\App\Http\Controllers\ExhibitionController::class, 'show'])->name('exhibitions.show');
        Route::delete('/exhibitions/{exhibition}', [\App\Http\Controllers\ExhibitionController::class, 'destroy'])->name('exhibitions.destroy');
        Route::post('/exhibitions/{exhibition}/highlights', [\App\Http\Controllers\ExhibitionController::class, 'saveHighlight'])->name('exhibitions.highlights.save');
        Route::post('/exhibitions/{exhibition}/finish', [\App\Http\Controllers\ExhibitionController::class, 'finish'])->name('exhibitions.finish');
        Route::post('/exhibitions/{exhibition}/play', [\App\Http\Controllers\ExhibitionController::class, 'play'])->name('exhibitions.play');
        Route::post('/exhibitions/{exhibition}/personnel', [\App\Http\Controllers\ExhibitionController::class, 'personnel'])->name('exhibitions.personnel');
        Route::get('/teams/{team}/simulation-ratings', [\App\Http\Controllers\SimulationRatingsController::class, 'edit'])->name('simulation-ratings.edit');
        Route::put('/teams/{team}/simulation-ratings', [\App\Http\Controllers\SimulationRatingsController::class, 'update'])->name('simulation-ratings.update');

        Route::get('/teams', [TeamEditor::class, 'index'])->name('teams.index');
        Route::resource('teams/editor', TeamEditor::class)
            ->only(['index', 'create', 'store', 'edit', 'update'])->names('teams.editor')
            ->parameters(['editor' => 'team']);
        Route::prefix('teams/editor')->name('teams.editor.')->group(function () {
            Route::get('teams/{team}/players', [TeamRosterController::class, 'index'])
                ->name('teams.players.index');

            Route::get('teams/{team}/players/create', [TeamRosterController::class, 'create'])
                ->name('teams.players.create');

            Route::post('teams/{team}/players', [TeamRosterController::class, 'store'])
                ->name('teams.players.store');

            Route::get('teams/{team}/players/{player}/edit', [TeamRosterController::class, 'edit'])
                ->name('teams.players.edit');

            Route::get('teams/{team}/players/{player}/history/{tab}', [TeamRosterController::class, 'historyTab'])
                ->whereIn('tab', ['statistics', 'injuries'])
                ->name('teams.players.history');

            Route::put('teams/{team}/players/{player}', [TeamRosterController::class, 'update'])
                ->name('teams.players.update');
        });
    });

});
