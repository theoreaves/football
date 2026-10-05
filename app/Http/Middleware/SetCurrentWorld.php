<?php

namespace App\Http\Middleware;

use App\Models\World;
use App\Support\CurrentWorld;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetCurrentWorld
{
    public function handle(Request $request, Closure $next): Response
    {
        $context = app(CurrentWorld::class);
        $context->id = null;
        try {
            $id = $request->session()->get('current_world_id');
            $context->id = $id && $request->user() ? World::where('owner_user_id', $request->user()->id)->whereKey($id)->value('id') : null;
            if ($id && ! $context->id) {
                $request->session()->forget('current_world_id');
            }

            return $next($request);
        } finally {
            $context->id = null;
        }
    }
}
