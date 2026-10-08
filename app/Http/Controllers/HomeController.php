<?php

namespace App\Http\Controllers;

use App\Support\CurrentWorld;
use Illuminate\Http\Request;

class HomeController extends Controller
{
    public function __invoke(Request $request)
    {
        if (! $request->user()) {
            return view('welcome');
        }
        if (! $request->user()->hasVerifiedEmail()) {
            return redirect()->route('verification.notice');
        }
        if (! app(CurrentWorld::class)->id) {
            return redirect()->route('worlds.index');
        }

        return app(ExhibitionController::class)->index();
    }
}
