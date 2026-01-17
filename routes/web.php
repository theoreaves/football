<?php

use App\Livewire\GameCompanion;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\GameSetupController;
//Route::get('/', function () {
//    return view('welcome');
//})->name('home');
Route::get('/', [GameSetupController::class, 'index'])->name('home');


Route::get('/football', GameCompanion::class);
Route::get('/football/{gameId}', GameCompanion::class);

use App\Http\Controllers\TeamSheetController;

Route::get('/teams/show/{team}/sheet/{year?}', [TeamSheetController::class, 'show'])
    ->whereNumber('team')
    ->name('teams.sheet');

use App\Http\Controllers\TeamController;

Route::get('/teams', [TeamController::class, 'index'])->name('teams.index');
Route::get('/teams/{team}/sheet', [TeamController::class, 'sheet'])->name('teams.sheet');

use App\Http\Controllers\PlayerController;

Route::get('/players/{player}', [PlayerController::class, 'show'])->name('players.show');

//use App\Livewire\GameCompanion;

Route::get('/games/', [GameSetupController::class, 'index'])->name('games.index');
Route::get('/games/new', [GameSetupController::class, 'create'])->name('games.create');
Route::post('/games/new', [GameSetupController::class, 'store'])->name('games.store');

Route::get('/games/{gameId}', GameCompanion::class)->name('games.show');

use \App\Http\Controllers\GameLookupController;
Route::get('/games/{game}/lookup-jersey', [GameLookupController::class, 'lookup'])
    ->name('games.lookupJersey');

use \App\Http\Controllers\GameCardsController;
Route::get('/game-cards/{cardType}', [GameCardsController::class, 'index'])
    ->name('games-cards.index');

use App\Http\Controllers\DiceController;
//Route::get('/dice/{game}', [DiceController::class, 'index'])
//    ->name('dice.index');
Route::get('/games/{game}/dice', [DiceController::class, 'index'])->name('dice.index');
Route::post('/games/{game}/dice/resolve', [DiceController::class, 'resolve'])->name('dice.resolve');


// routes/web.php

use App\Http\Controllers\PdfLibraryController;

//Route::get('/pdf-library/list', [PdfLibraryController::class, 'list'])->name('pdf.library.list');
//Route::get('/pdf-library/view/{file}', [PdfLibraryController::class, 'view'])->where('file', '.*')->name('pdf.library.view');
//

Route::get('/pdf-library', [PdfLibraryController::class, 'index'])->name('pdf.library');
Route::get('/pdf-library/list', [PdfLibraryController::class, 'list'])->name('pdf.library.list');

// routes/web.php
use App\Http\Controllers\BoxscoreController;

Route::get('/games/{game}/boxscore', [BoxscoreController::class, 'show'])
    ->name('games.boxscore');

use App\Http\Controllers\GamePlayTestController;

Route::get('/gameplay/test', [GamePlayTestController::class, 'showForm'])->name('gameplay.test');
Route::post('/gameplay/test', [GamePlayTestController::class, 'submitForm']);
Route::post('/gameplay/test/kickoff', [GamePlayTestController::class, 'submitKickoffForm']);
Route::post('/gameplay/test/punt', [GamePlayTestController::class, 'submitPuntForm']);
Route::post('/gameplay/test/punt_return', [GamePlayTestController::class, 'submitPuntReturn']);

use App\Http\Controllers\TeamEditor;
use App\Http\Controllers\TeamRosterController;

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

