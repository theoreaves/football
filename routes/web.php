<?php

use App\Livewire\GameCompanion;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
})->name('home');


Route::get('/football', GameCompanion::class);
Route::get('/football/{gameId}', GameCompanion::class);
