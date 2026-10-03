<?php

namespace App\Http\Middleware;

use App\Support\CurrentWorld;
use Closure;
use Illuminate\Http\Request;

class SetCurrentWorld
{
    public function handle(Request $request, Closure $next): \Symfony\Component\HttpFoundation\Response
    {
        $context = app(CurrentWorld::class);
        $context->id = null;
        try {
            if ($request->user()) {
                $world = $request->user()->worlds()->find($request->user()->current_world_id);
                $context->id = $world?->id;
            }

            return $next($request);
        } finally {
            $context->id = null;
        }
    }
}
