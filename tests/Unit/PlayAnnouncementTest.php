<?php

use App\Support\PlayAnnouncement;

test('major play results get clear event headlines', function ($play, $titles) {
    expect(PlayAnnouncement::titles($play))->toBe($titles);
})->with([
    'touchdown' => [['outcome' => 'touchdown'], ['TOUCHDOWN!']],
    'return touchdown' => [['outcome' => 'kickoff_return_touchdown'], ['TOUCHDOWN!']],
    'interception' => [['outcome' => 'interception'], ['INTERCEPTION!']],
    'pick six' => [['outcome' => 'interception', 'summary' => 'defensive touchdown'], ['TOUCHDOWN!']],
    'fumble' => [['outcome' => 'fumble'], ['FUMBLE!']],
    'downs' => [['outcome' => 'tackle', 'summary' => 'Turnover on downs'], ['TURNOVER ON DOWNS']],
    'made kick' => [['outcome' => 'field_goal_good'], ['FIELD GOAL GOOD!']],
    'missed kick' => [['outcome' => 'field_goal_missed'], ['FIELD GOAL MISSED']],
    'blocked kick' => [['outcome' => 'field_goal_blocked'], ['FIELD GOAL BLOCKED!']],
    'flag erases provisional score' => [['outcome' => 'touchdown', 'penalty' => ['type' => 'holding']], ['PENALTY — DECISION PENDING']],
    'regular play' => [['outcome' => 'tackle'], ['PLAY RESULT']],
]);
