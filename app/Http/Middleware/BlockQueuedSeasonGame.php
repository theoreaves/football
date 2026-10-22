<?php

namespace App\Http\Middleware;

use App\Models\Exhibition;
use App\Models\SeasonFixture;
use Closure;
use Illuminate\Http\Request;

class BlockQueuedSeasonGame
{
    public function handle(Request $request, Closure $next)
    {
        $game = $request->route('exhibition');
        if ($game instanceof Exhibition) {
            abort_if(SeasonFixture::where('exhibition_id', $game->id)->whereNotNull('simulation_status')->exists(),
                409, 'This game is processing in the background. Return to the season schedule for updates.');
        }

        return $next($request);
    }
}
