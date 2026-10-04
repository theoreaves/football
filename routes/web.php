<?php

use App\Http\Controllers\TeamEditor;
use App\Http\Controllers\TeamRosterController;
use Illuminate\Support\Facades\Route;

Route::get('/worlds', [\App\Http\Controllers\WorldController::class, 'index'])->name('worlds.index');
Route::post('/worlds', [\App\Http\Controllers\WorldController::class, 'store'])->name('worlds.store');
Route::post('/worlds/{world}/select', [\App\Http\Controllers\WorldController::class, 'select'])->whereNumber('world')->name('worlds.select');
Route::post('/worlds/close', [\App\Http\Controllers\WorldController::class, 'close'])->name('worlds.close');
Route::get('/practice', \App\Http\Controllers\PracticeController::class)->name('practice');

Route::middleware([\App\Http\Middleware\RequireWorld::class])->group(function () {

    Route::get('/teams/{team}/art/{asset}', [\App\Http\Controllers\PracticeController::class, 'art'])->name('teams.art');

    Route::get('/exhibitions', [\App\Http\Controllers\ExhibitionController::class, 'index'])->name('exhibitions.index');
    Route::post('/exhibitions', [\App\Http\Controllers\ExhibitionController::class, 'store'])->name('exhibitions.store');
    Route::get('/exhibitions/{exhibition}', [\App\Http\Controllers\ExhibitionController::class, 'show'])->name('exhibitions.show');
    Route::post('/exhibitions/{exhibition}/play', [\App\Http\Controllers\ExhibitionController::class, 'play'])->name('exhibitions.play');
    Route::get('/teams/{team}/simulation-ratings', [\App\Http\Controllers\SimulationRatingsController::class, 'edit'])->name('simulation-ratings.edit');
    Route::put('/teams/{team}/simulation-ratings', [\App\Http\Controllers\SimulationRatingsController::class, 'update'])->name('simulation-ratings.update');

    Route::get('/', [\App\Http\Controllers\ExhibitionController::class, 'index'])->name('home');
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

        Route::put('teams/{team}/players/{player}', [TeamRosterController::class, 'update'])
            ->name('teams.players.update');
    });
});
