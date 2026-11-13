<?php

namespace App\Http\Controllers;

use App\Http\Requests\ArcadeExhibitionRequest;
use App\Models\ArcadePlaySession;
use App\Models\Exhibition;
use App\Services\Arcade\ExhibitionSessions;
use App\Support\ExhibitionHistory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;

class ArcadeExhibitionController extends Controller
{
    public function show(ArcadeExhibitionRequest $request, Exhibition $exhibition, ExhibitionSessions $sessions): View
    {
        $sessions->authorize($exhibition, $request->user()->id);
        $selectedPlay = null;
        if ($request->query->has('play')) {
            $value = $request->query('play');
            abort_unless(is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value) === 1 && strlen($value) <= 9, 404);
            $selectedPlay = (int) $value;
            $play = ExhibitionHistory::at($exhibition, $selectedPlay - 1);
            abort_unless(is_array($play) && ($play['source'] ?? null) === 'arcade' && is_array($play['arcade_recording'] ?? null), 404);
            $lastRecording = $play['arcade_recording'];
        } else {
            $lastRecording = null;
            foreach (ExhibitionHistory::entries($exhibition) as $entry) {
                if (($entry['source'] ?? null) === 'arcade' && is_array($entry['arcade_recording'] ?? null)) {
                    $lastRecording = $entry['arcade_recording'];
                }
            }
            unset($entry);
        }
        $status = $sessions->status($exhibition);
        if ($selectedPlay === null) {
            $status['flow'] = app(\App\Services\Arcade\FlowNotices::class)->consume($request, $exhibition, $status['flow']);
        }
        $pending = $selectedPlay === null ? ArcadePlaySession::where('active_slot', $exhibition->id)->where('user_id', $request->user()->id)->where('expires_at', '>', now())->first() : null;

        return view('arcade.exhibition', ['exhibition' => $exhibition, 'state' => $selectedPlay !== null ? ($play['after'] ?? $play['before']) : $status['state'], 'available' => $selectedPlay === null && $status['available'],
            'presentationUrl' => $selectedPlay === null ? route('exhibitions.arcade.presentation', $exhibition) : null,
            'flow' => $status['flow'], 'flowUrl' => route('exhibitions.arcade.flow', $exhibition), 'coachUrl' => $status['coach_url'], 'startUrl' => route('exhibitions.arcade.start', $exhibition),
            'pendingSession' => $pending ? $sessions->publicSession($pending) : null,
            'replayOnly' => $selectedPlay !== null, 'selectedPlay' => $selectedPlay, 'lastRecording' => $lastRecording]);
    }

    public function start(ArcadeExhibitionRequest $request, Exhibition $exhibition, ExhibitionSessions $sessions): JsonResponse
    {
        return response()->json($sessions->start($exhibition, $request->user()->id, $request->validated()));
    }

    public function submit(ArcadeExhibitionRequest $request, Exhibition $exhibition, string $session, ExhibitionSessions $sessions): JsonResponse
    {
        return response()->json($sessions->submit($exhibition, $request->user()->id, $session, $request->validated()));
    }

    public function cancel(ArcadeExhibitionRequest $request, Exhibition $exhibition, string $session, ExhibitionSessions $sessions): JsonResponse
    {
        return response()->json($sessions->cancel($exhibition, $request->user()->id, $session));
    }
}
