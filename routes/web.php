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

