<?php

use App\Support\PlayAnnouncement;

test('major play results get clear event headlines', function ($play, $titles) {
    expect(PlayAnnouncement::titles($play))->toBe($titles);
})->with([
    'touchdown' => [['outcome' => 'touchdown'], ['TOUCHDOWN!']],
    'return touchdown' => [['outcome' => 'kickoff_return_touchdown'], ['TOUCHDOWN!']],
    'interception' => [['outcome' => 'interception'], ['TURNOVER!']],
    'pick six' => [['outcome' => 'interception', 'summary' => 'defensive touchdown'], ['TOUCHDOWN!', 'TURNOVER!']],
    'fumble' => [['outcome' => 'fumble'], ['TURNOVER!']],
    'downs' => [['outcome' => 'tackle', 'summary' => 'Turnover on downs'], ['TURNOVER!']],
    'made kick' => [['outcome' => 'field_goal_good'], ['FIELD GOAL!']],
    'missed kick' => [['outcome' => 'field_goal_missed'], ['MISSED!']],
    'blocked kick' => [['outcome' => 'field_goal_blocked'], ['MISSED!']],
    'flag erases provisional score' => [['outcome' => 'touchdown', 'penalty' => ['type' => 'holding']], ['FLAG!']],
    'regular play' => [['outcome' => 'tackle'], ['Play result']],
]);
