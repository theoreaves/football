<?php

namespace App\Http\Middleware;

use App\Support\CurrentWorld;
use Closure;
use Illuminate\Http\Request;

class RequireWorld
{
    public function handle(Request $request, Closure $next): \Symfony\Component\HttpFoundation\Response
    {
        return app(CurrentWorld::class)->id ? $next($request) : redirect()->route('worlds.index');
    }
}
