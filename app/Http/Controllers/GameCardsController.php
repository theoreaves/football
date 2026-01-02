<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class GameCardsController extends Controller
{
    public function index($cardType)
    {
        $cards = [];
        $cardSheet = '';
        switch($cardType){
            case 'offense':
                $cardSheet = 'Offense Game Cards';
                $cards = [
                    ['key' => 'card1', 'label' => 'Inside Run', 'src' => asset('offense-cards/inside_run.png')],
                    ['key' => 'card2', 'label' => 'Outside Run', 'src' => asset('offense-cards/outside_run.png')],
                    ['key' => 'card3', 'label' => 'Draw Play', 'src' => asset('offense-cards/draw_play.png')],
                    ['key' => 'card4', 'label' => 'Screen Pass', 'src' => asset('offense-cards/screen_pass.png')],
                    ['key' => 'card5', 'label' => 'Short Pass', 'src' => asset('offense-cards/short_pass.png')],
                    ['key' => 'card6', 'label' => 'Medium Pass', 'src' => asset('offense-cards/medium_pass.png')],
                    ['key' => 'card7', 'label' => 'Long Pass', 'src' => asset('offense-cards/long_pass.png')],
                    ['key' => 'card8', 'label' => 'Pressure', 'src' => asset('offense-cards/pressure.png')],
                    ['key' => 'card9', 'label' => 'Hail Mary', 'src' => asset('offense-cards/hail_mary.png')],
                ];
                break;
            case 'defense':
                $cardSheet = 'Defense Game Cards';
                $cards = [
                    ['key' => 'card1', 'label' => 'Short Yardage', 'src' => asset('defense-cards/short_yardage.png')],
                    ['key' => 'card2', 'label' => 'Rush Contain', 'src' => asset('defense-cards/rush_contain.png')],
                    ['key' => 'card3', 'label' => 'Balanced', 'src' => asset('defense-cards/balanced.png')],
                    ['key' => 'card4', 'label' => 'Bump & Run', 'src' => asset('defense-cards/bump_and_run.png')],
                    ['key' => 'card5', 'label' => 'Short Zone', 'src' => asset('defense-cards/short_zone.png')],
                    ['key' => 'card6', 'label' => 'Pass Blitz', 'src' => asset('defense-cards/pass_blitz.png')],
                    ['key' => 'card7', 'label' => 'Deep Zone', 'src' => asset('defense-cards/deep_zone.png')],
                ];
                break;
            case 'coach':
                $cardSheet = 'Defense Game Cards';
                $cards = [
                    ['key' => 'card1', 'label' => 'Play Selection', 'src' => asset('helper-cards/play_selection.png')],
                ];
                break;
        }
        return view('game-cards.index', ['cards' => $cards, 'cardSheet' => $cardSheet]);
    }
}
