<?php

use App\Livewire\GameCompanion;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
})->name('home');


Route::get('/football', GameCompanion::class);
Route::get('/football/{gameId}', GameCompanion::class);

use App\Http\Controllers\TeamSheetController;

Route::get('/teams/{team}/sheet/{year?}', [TeamSheetController::class, 'show'])
    ->whereNumber('team')
    ->name('teams.sheet');

use App\Http\Controllers\TeamController;

Route::get('/teams', [TeamController::class, 'index'])->name('teams.index');
Route::get('/teams/{team}/sheet', [TeamController::class, 'sheet'])->name('teams.sheet');

use App\Http\Controllers\PlayerController;

Route::get('/players/{player}', [PlayerController::class, 'show'])->name('players.show');

use App\Http\Controllers\GameSetupController;
//use App\Livewire\GameCompanion;

Route::get('/games/new', [GameSetupController::class, 'create'])->name('games.create');
Route::post('/games/new', [GameSetupController::class, 'store'])->name('games.store');

Route::get('/games/{gameId}', GameCompanion::class)->name('games.show');
