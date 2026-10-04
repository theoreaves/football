<?php

namespace App\Http\Middleware;

use App\Models\LocalSetting;
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
            $id = $request->session()->get('current_world_id') ?? LocalSetting::find(1)?->current_world_id;
            $context->id = $id ? World::find($id)?->id : null;

            return $next($request);
        } finally {
            $context->id = null;
        }
    }
}
