<?php

use App\Livewire\GameCompanion;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
})->name('home');


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

use App\Http\Controllers\GameSetupController;
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
//Route::resource('teams/editor', TeamEditor::class)->names('teams.editor');


Route::resource('teams/editor', TeamEditor::class)
    ->names('teams.editor')
    ->parameters(['editor' => 'team']);

