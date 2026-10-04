<?php

use App\Http\Controllers\BoxscoreController;
use App\Http\Controllers\DiceController;
use App\Http\Controllers\GameCardsController;
use App\Http\Controllers\GameLookupController;
use App\Http\Controllers\GamePlayTestController;
use App\Http\Controllers\GameSetupController;
use App\Http\Controllers\PdfLibraryController;
use App\Http\Controllers\PlayerController;
use App\Http\Controllers\TeamController;
use App\Http\Controllers\TeamEditor;
use App\Http\Controllers\TeamRosterController;
use App\Http\Controllers\TeamSheetController;
use App\Livewire\GameCompanion;
use Illuminate\Support\Facades\Route;

Route::get('/worlds', [\App\Http\Controllers\WorldController::class, 'index'])->name('worlds.index');
Route::post('/worlds', [\App\Http\Controllers\WorldController::class, 'store'])->name('worlds.store');
Route::post('/worlds/{world}/select', [\App\Http\Controllers\WorldController::class, 'select'])->whereNumber('world')->name('worlds.select');
Route::post('/worlds/close', [\App\Http\Controllers\WorldController::class, 'close'])->name('worlds.close');
Route::get('/practice', \App\Http\Controllers\PracticeController::class)->name('practice');

Route::middleware([\App\Http\Middleware\RequireWorld::class])->group(function () {

    Route::get('/exhibitions', [\App\Http\Controllers\ExhibitionController::class, 'index'])->name('exhibitions.index');
    Route::post('/exhibitions', [\App\Http\Controllers\ExhibitionController::class, 'store'])->name('exhibitions.store');
    Route::get('/exhibitions/{exhibition}', [\App\Http\Controllers\ExhibitionController::class, 'show'])->name('exhibitions.show');
    Route::post('/exhibitions/{exhibition}/play', [\App\Http\Controllers\ExhibitionController::class, 'play'])->name('exhibitions.play');
    Route::get('/teams/{team}/simulation-ratings', [\App\Http\Controllers\SimulationRatingsController::class, 'edit'])->name('simulation-ratings.edit');
    Route::put('/teams/{team}/simulation-ratings', [\App\Http\Controllers\SimulationRatingsController::class, 'update'])->name('simulation-ratings.update');

    Route::get('/', [GameSetupController::class, 'index'])->name('home');

    Route::get('/football', GameCompanion::class);
    Route::get('/football/{gameId}', GameCompanion::class);

    Route::get('/teams/show/{team}/sheet/{year?}', [TeamSheetController::class, 'show'])
        ->whereNumber('team')
        ->name('teams.historical-sheet');

    Route::get('/teams', [TeamController::class, 'index'])->name('teams.index');
    Route::get('/teams/{team}/sheet', [TeamController::class, 'sheet'])->name('teams.sheet');

    Route::get('/players/{player}', [PlayerController::class, 'show'])->name('players.show');

    Route::get('/games/', [GameSetupController::class, 'index'])->name('games.index');
    Route::get('/games/new', [GameSetupController::class, 'create'])->name('games.create');
    Route::post('/games/new', [GameSetupController::class, 'store'])->name('games.store');

    Route::get('/games/{gameId}', GameCompanion::class)->name('games.show');

    Route::get('/games/{game}/lookup-jersey', [GameLookupController::class, 'lookup'])
        ->name('games.lookupJersey');

    Route::get('/game-cards/{cardType}', [GameCardsController::class, 'index'])
        ->name('games-cards.index');

    Route::get('/games/{game}/dice', [DiceController::class, 'index'])->name('dice.index');
    Route::post('/games/{game}/dice/resolve', [DiceController::class, 'resolve'])->name('dice.resolve');

    Route::get('/pdf-library', [PdfLibraryController::class, 'index'])->name('pdf.library');
    Route::get('/pdf-library/list', [PdfLibraryController::class, 'list'])->name('pdf.library.list');

    Route::get('/games/{game}/boxscore', [BoxscoreController::class, 'show'])
        ->name('games.boxscore');

    Route::get('/gameplay/test', [GamePlayTestController::class, 'showForm'])->name('gameplay.test');
    Route::post('/gameplay/test', [GamePlayTestController::class, 'submitForm']);
    Route::post('/gameplay/test/kickoff', [GamePlayTestController::class, 'submitKickoffForm']);
    Route::post('/gameplay/test/punt', [GamePlayTestController::class, 'submitPuntForm']);
    Route::post('/gameplay/test/punt_return', [GamePlayTestController::class, 'submitPuntReturn']);

    Route::resource('teams/editor', TeamEditor::class)
        ->names('teams.editor')
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
    Route::post('teams/editor/teams/{team}/import-team-card', [TeamEditor::class, 'importTeamCard'])
        ->name('teams.editor.importTeamCard');

    Route::post('/teams/{team}/players/{player}/ratings/from-season/{seasonYear}', [
        \App\Http\Controllers\TeamPlayerRatingsController::class,
        'fromSeason',
    ])->name('teams.editor.teams.players.ratings.fromSeason');

});
