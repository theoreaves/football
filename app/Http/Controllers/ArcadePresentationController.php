<?php

namespace App\Http\Controllers;

use App\Models\ArcadePlaySession;
use App\Models\Exhibition;
use App\Services\Arcade\ExhibitionPresentation;
use App\Services\Arcade\ExhibitionSessions;
use Illuminate\Http\Request;

/** Authorized reads only. Gameplay and command receipts remain in their existing APIs. */
class ArcadePresentationController extends Controller
{
    public function show(Request $request, Exhibition $exhibition, ExhibitionSessions $sessions, ExhibitionPresentation $presentation)
    {
        $sessions->authorize($exhibition, $request->user()->id);
        $status = $sessions->status($exhibition);
        $data = $presentation->viewData($exhibition);
        $history = $exhibition->history ?? [];
        $last = $history ? $history[array_key_last($history)] : null;
        $pending = ArcadePlaySession::where('active_slot', $exhibition->id)->where('user_id', $request->user()->id)->where('expires_at', '>', now())->first();
        $controlsData = $data + ['arcadeFlow' => $status['flow'], 'arcadeFlowUrl' => route('exhibitions.arcade.flow', $exhibition),
            'arcadeReturnUrl' => route('exhibitions.arcade.show', $exhibition), 'state' => $status['state'],
            'playContext' => $status['flow']['enabled'], 'watching' => false, 'shown' => $status['state'],
            'teamNames' => ['home' => $data['exhibition']->homeTeam->name, 'away' => $data['exhibition']->awayTeam->name]];
        // Existing Coach-compatible placement data, without the duplicate Arcade recording.
        $animation = $data['animation'];
        unset($animation['recording']);
        $nextAnimation = $data['nextAnimation'];
        if ($nextAnimation !== null) {
            unset($nextAnimation['recording']);
        }

        $controlsData['animation'] = $animation;
        $controlsData['nextAnimation'] = $nextAnimation;
        $controlsData['presentationWatching'] = false;

        return response()->json($status + [
            'presentation_version' => 1, 'game_id' => $exhibition->id, 'world_id' => $exhibition->world_id,
            'appearance' => $data['appearance'], 'team_names' => $controlsData['teamNames'],
            'crowd' => $status['state']['crowd'] ?? ['fullness' => 80, 'visitors' => 10, 'seed' => $exhibition->id],
            'pending_session' => $pending ? $sessions->publicSession($pending) : null,
            'latest_play' => $last ? $this->descriptor($exhibition, $last) : null,
            'history_cursor' => count($history),
            'scene' => ['animation' => $animation, 'next_animation' => $nextAnimation,
                'unit_change' => (bool) $data['unitChange'], 'substitution_change' => $data['substitutionChange'],
                'sideline_roster' => $data['sidelineRoster'], 'personnel' => $data['personnel'],
                'formation_personnel' => $data['formationPersonnel']],
            'controls_html' => view('exhibitions.partials.play-controls', $controlsData)->render(),
            'box_score' => $data['boxScore'],
            'urls' => ['presentation' => route('exhibitions.arcade.presentation', $exhibition),
                'flow' => route('exhibitions.arcade.flow', $exhibition), 'start' => route('exhibitions.arcade.start', $exhibition),
                'personnel' => route('exhibitions.personnel', $exhibition),
                'playback_base' => url('exhibitions/'.$exhibition->id.'/arcade/playback')],
        ])->header('Cache-Control', 'private, no-store');
    }

    public function playback(Request $request, Exhibition $exhibition, string $number, ExhibitionSessions $sessions)
    {
        $sessions->authorize($exhibition, $request->user()->id);
        abort_unless(preg_match('/^[1-9][0-9]{0,8}$/D', $number), 404);
        $play = collect($exhibition->history ?? [])->firstWhere('number', (int) $number);
        abort_unless($play && isset($play['animation']), 404, 'This saved play is unavailable.');
        $arcade = ($play['source'] ?? null) === 'arcade';
        abort_if($arcade && ! is_array($play['arcade_recording'] ?? null), 404, 'This recorded play is unavailable.');

        $controlsHtml = null;
        if (! $arcade) {
            $data = app(ExhibitionPresentation::class)->viewData($exhibition, (int) $number, false, true);
            unset($data['animation']['recording']);
            if ($data['nextAnimation'] !== null) {
                unset($data['nextAnimation']['recording']);
            }
            $controlsHtml = view('exhibitions.partials.play-controls', $data + [
                'arcadeFlow' => null, 'arcadeFlowUrl' => null, 'arcadeReturnUrl' => null,
                'presentationWatching' => true, 'presentationReadonly' => true,
            ])->render();
        }

        return response()->json($this->descriptor($exhibition, $play) + [
            'controls_html' => $controlsHtml, 'replay_only' => true,
            'before' => $play['before'], 'after' => $play['after'],
            'penalty' => $play['penalty'] ?? null, 'personnel_notices' => $play['personnel_notices'] ?? [],
            'animation' => $arcade ? null : $play['animation'],
            'recording' => $arcade ? $play['arcade_recording'] : null,
        ])->header('Cache-Control', 'private, no-store');
    }

    private function descriptor(Exhibition $game, array $play): array
    {
        return ['game_id' => $game->id, 'number' => $play['number'],
            'source' => ($play['source'] ?? null) === 'arcade' ? 'arcade' : 'coach',
            'summary' => $play['summary'], 'outcome' => $play['outcome'], 'call' => $play['call'],
            'url' => route('exhibitions.arcade.playback', ['exhibition' => $game, 'number' => $play['number']])];
    }
}
