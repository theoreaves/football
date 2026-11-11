<?php

namespace App\Http\Controllers;

use App\Http\Requests\ArcadeFlowRequest;
use App\Models\Exhibition;
use App\Services\Arcade\ExhibitionFlow;
use App\Services\Arcade\ExhibitionSessions;
use Illuminate\Http\Request;

class ArcadeFlowController extends Controller
{
    public function show(Request $request, Exhibition $exhibition, ExhibitionFlow $flow, ExhibitionSessions $sessions)
    {
        $sessions->authorize($exhibition, $request->user()->id);

        return response()->json($flow->status($exhibition));
    }

    public function store(ArcadeFlowRequest $request, Exhibition $exhibition, ExhibitionFlow $flow)
    {
        $result = $flow->execute($exhibition, $request);

        return $request->expectsJson() ? response()->json($result) : redirect($result['navigate_url']);
    }
}
