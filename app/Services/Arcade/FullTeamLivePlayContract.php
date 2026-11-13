<?php

namespace App\Services\Arcade;

use App\Services\Simulation\FieldOrientation;
use DomainException;

/** Validates bounded live evidence without rerunning gameplay or claiming anti-cheat proof. */
final class FullTeamLivePlayContract
{
    public function derive(ArcadeContext $context, array $recording, array $setup): array
    {
        $context->assertPlayable();
        $r = $recording;
        $this->check(($setup['contractVersion'] ?? null) === 'live-recording-2' && ($r['contractVersion'] ?? null) === $setup['contractVersion'] && ($r['fullTeam'] ?? null) === true && ($r['mode'] ?? null) === $setup['mode'] && ($r['seed'] ?? null) === $setup['seed'] && ($r['routeSet'] ?? null) === $setup['routeSet'] && ($r['call'] ?? null) === $setup['call'] && ($r['catalogVersion'] ?? null) === $setup['catalogVersion'], 'Full-team catalog mismatch.');
        $nodes = 0;
        $walk = function ($value, int $depth = 0) use (&$walk, &$nodes): void {
            $this->check(++$nodes <= FullTeamSetup::LIMITS['nodes'] && $depth <= 16, 'Recording structure exceeds limits.');
            if (is_array($value)) {
                foreach ($value as $child) {
                    $walk($child, $depth + 1);
                }
            }
        };
        $walk($r);
        $this->check(strlen(json_encode($r, JSON_THROW_ON_ERROR)) <= FullTeamSetup::LIMITS['bytes'], 'Recording exceeds 2 MiB.');
        $this->check(($r['version'] ?? null) === 2 && ($r['tickRate'] ?? null) === 60 && ($r['sampleRate'] ?? null) === 30
            && ($r['scales'] ?? null) === ['position' => 100, 'angle' => 1000, 'time' => 1000000, 'gait' => 1000]
            && ($r['poses'] ?? null) === ['idle', 'run', 'juke', 'tackled', 'throw', 'block', 'handoff'] && ($r['ballPhases'] ?? null) === ['held', 'flight', 'dead']
            && ($r['truncated'] ?? true) === false, 'Unsupported recording header.');
        $this->check(in_array($r['mode'] ?? null, ['running', 'passing'], true) && ($r['speedScale'] ?? null) === .925
            && ($r['tuningVersion'] ?? null) === 'arcade-fullteam-1' && ($r['direction'] ?? null) === FieldOrientation::direction($context->state), 'Gameplay configuration mismatch.');
        foreach (['startX' => $context->state['spot'], 'goalX' => 100, 'rearX' => 0] as $key => $spot) {
            $this->check((is_int($r['field'][$key] ?? null) || is_float($r['field'][$key] ?? null)) && abs($r['field'][$key] - FieldPosition::physical($spot, $context->state)) < .00001, 'Field mismatch.');
        }
        $this->check(is_array($r['actors'] ?? null) && array_is_list($r['actors']) && count($r['actors']) === count($context->actors) && count($r['actors']) === 22, 'Actor count mismatch.');
        $assigned = [];
        foreach ($context->actors as $id => $actor) {
            $key = $actor['replay_key'] ?? $id;
            $this->check(! isset($assigned[$key]), 'Duplicate actor key.');
            $assigned[$key] = $actor + ['actor_id' => $id];
        }
        $actors = [];
        foreach ($r['actors'] as $i => $actor) {
            $this->check(is_array($actor) && is_string($actor['key'] ?? null), 'Invalid actor identity.');
            $key = $actor['key'];
            $this->check(isset($assigned[$key]), 'Unknown actor.');
            $a = $assigned[$key];
            unset($assigned[$key]);
            $person = $context->roster[$a['side']]['players'][$a['role']];
            $this->check(($actor['id'] ?? null) === $person['id'] && ($actor['side'] ?? null) === $a['side'] && ($actor['role'] ?? null) === $a['role'], 'Actor identity mismatch.');
            $this->check(($a['side'] === $context->side) === ! str_starts_with($key, 'defense_'), 'Assigned side mismatch.');
            $this->check(! str_starts_with($key, 'defense_') || preg_match('/^(DE[12]|DT[12]|LB[1-5]|CB[1-4]|S[12])$/D', $a['role']) === 1, 'Invalid defensive role.');
            $this->check(! in_array($key, $setup['eligibleReceiverKeys'], true) || preg_match('/^(WR[1-5]?|TE[12]?|RB|FB)$/D', $a['role']) === 1, 'Invalid receiving role.');
            $this->check($key !== 'offense_RB' || in_array($a['role'], ['RB', 'FB', 'QB'], true), 'Invalid runner role.');
            $this->check($key === ($setup['players'][$i]['key'] ?? null), 'Actor order mismatch.');
            $actors[$i] = $a;
            $r['actors'][$i] = ['key' => $key, 'side' => $a['side'], 'role' => $a['role']] + array_intersect_key($person, array_flip(['id', 'name', 'firstname', 'lastname', 'number', 'position', 'skin_tone', 'height_inches', 'weight_pounds', 'appearance']));
        }
        $r['appearance'] = $context->state['team_appearance'] ?? array_map(fn ($roster) => $roster['appearance'] ?? [], $context->roster);
        $frames = $r['frames'] ?? null;
        $this->check(is_array($frames) && array_is_list($frames) && count($frames) >= 2 && count($frames) <= FullTeamSetup::LIMITS['frames'], 'Invalid frame count.');
        $previous = null;
        foreach ($frames as $frame) {
            $this->check(is_array($frame) && array_is_list($frame) && count($frame) === 5 && $this->integer($frame[0], 0, 1200) && $this->integer($frame[1], 0, 20000000)
                && $this->integer($frame[2], 0, count($actors) - 1) && is_array($frame[3]) && array_is_list($frame[3]) && count($frame[3]) === count($actors), 'Invalid frame.');
            $this->check($frame[1] <= round($frame[0] * 1000000 / 60) + 1 && $frame[1] >= round(max(0, $frame[0] - 1) * 1000000 / 60) - 1, 'Frame clock mismatch.');
            if ($previous) {
                $this->check($frame[0] >= $previous[0] && $frame[0] - $previous[0] <= 2 && $frame[1] >= $previous[1] && ($frame[0] !== $previous[0] || $frame[1] !== $previous[1]), 'Missing or unordered frames.');
            } else {
                $this->check($frame[0] === 0 && $frame[1] === 0, 'Missing initial frame.');
            }
            foreach ($frame[3] as $i => $p) {
                $this->check(is_array($p) && array_is_list($p) && count($p) === 7 && count(array_filter($p, fn ($v) => $this->integer($v, -1000000, 1000000))) === 7
                    && $this->integer($p[0], 0, 12000) && $this->integer($p[1], 0, 5333) && abs($p[2]) <= 3142
                    && $this->integer($p[3], 0, 6) && $p[4] >= 0 && $p[4] <= 1500 && $p[5] >= 0 && abs($p[6]) <= 1000, 'Invalid actor pose.');
                if ($previous) {
                    $dt = ($frame[1] - $previous[1]) / 1000000;
                    $this->check($this->distance($p, $previous[3][$i]) <= 15 * $dt + .035, 'Impossible actor movement.');
                } else {
                    $this->check(abs($p[0] / 100 - $actors[$i]['x']) <= .006 && abs($p[1] / 100 - $actors[$i]['y']) <= .006, 'Actor start mismatch.');
                }
            }
            $b = $frame[4];
            $this->check(is_array($b) && array_is_list($b) && count($b) === 5 && $this->integer($b[0], 0, 12000) && $this->integer($b[1], 0, 4000)
                && $this->integer($b[2], 0, 5333) && $this->integer($b[3], 0, 2) && $this->integer($b[4], -1, count($actors) - 1), 'Invalid ball.');
            if ($b[3] === 0) {
                $this->check($b[4] >= 0 && $this->distance([$b[0], $b[2]], $frame[3][$b[4]]) <= .02, 'Ball ownership mismatch.');
            }
            if ($previous) {
                $this->check($this->distance([$b[0], $b[2]], [$previous[4][0], $previous[4][2]]) <= 90 * (($frame[1] - $previous[1]) / 1000000) + ($previous[4][3] === 1 && $b[4] >= 0 ? 1.73 : .08), 'Impossible ball movement.');
            }
            $previous = $frame;
        }
        $last = end($frames);
        $duration = $last[1] / 1000000;
        $events = $r['events'] ?? null;
        $this->check(is_array($events) && array_is_list($events) && count($events) >= 2 && count($events) <= FullTeamSetup::LIMITS['events'], 'Invalid events.');
        $byType = [];
        $previousTime = -1;
        foreach ($events as $e) {
            $this->check(is_array($e) && array_is_list($e) && count($e) === 6 && $this->integer($e[0], 0, $last[0]) && $this->integer($e[1], 0, $last[1])
                && $e[1] >= $previousTime && in_array($e[2], ['start', 'throw', 'catch_attempt', 'catch', 'control_changed', 'interception', 'incomplete', 'juke', 'broken_tackle', 'tackle', 'finished', 'handoff', 'block', 'block_shed', 'pressure', 'pass_breakup'], true)
                && $this->integer($e[3], -1, count($actors) - 1) && $this->integer($e[4], -1, count($actors) - 1) && is_array($e[5]), 'Invalid event.');
            $this->check(count(array_filter($frames, fn ($f) => $f[0] === $e[0] && $f[1] >= $e[1] && $f[1] - $e[1] <= 16668)) >= 1, 'Event lacks its recorded tick frame.');
            $previousTime = $e[1];
            $byType[$e[2]][] = $e;
        }
        $this->check(count($byType['start'] ?? []) === 1 && $events[0][2] === 'start' && $events[0][1] === 0
            && count($byType['finished'] ?? []) === 1 && end($events)[2] === 'finished' && end($events)[1] === $last[1], 'Invalid terminal sequence.');
        $reason = $r['result']['reason'] ?? null;
        $this->check(in_array($reason, ['tackle', 'sack', 'out_of_bounds', 'touchdown', 'safety', 'incomplete', 'interception'], true) && (end($events)[5]['reason'] ?? null) === $reason, 'Unsupported terminal result.');
        $this->check(! isset($byType['pass_breakup']) || (($r['result']['reason'] ?? null) === 'incomplete' && ($byType['incomplete'][0][5]['cause'] ?? null) === 'breakup'), 'Breakup must end the pass.');
        $catchPoint = null;
        $catchExact = null;
        $throw = $byType['throw'][0] ?? null;
        $catch = $byType['catch'][0] ?? null;
        $intercept = $byType['interception'][0] ?? null;
        foreach (['throw', 'catch', 'interception', 'incomplete', 'tackle'] as $type) {
            $this->check(count($byType[$type] ?? []) <= 1, 'Multiple terminal or pass events.');
        }
        $initial = $frames[0][2];
        $this->check($frames[0][4][3] === 0 && $frames[0][4][4] === $initial && $events[0][3] === $initial && $events[0][4] === -1, 'Invalid snap possession.');
        $handoff = $byType['handoff'][0] ?? null;
        $this->check(count($byType['handoff'] ?? []) <= 1 && (! $handoff || $r['mode'] === 'running'), 'Invalid handoff count.');
        if ($handoff) {
            $at = $this->sample($frames, $handoff[1]);
            $this->check($handoff[3] === $initial && $handoff[4] >= 0 && $actors[$handoff[4]]['side'] === $context->side && $actors[$handoff[4]]['role'] === 'RB' && $handoff[1] > 0 && $this->distance($at[3][$initial], $at[3][$handoff[4]]) <= 1.02, 'Invalid handoff contact.');
        }
        foreach (['block' => 1.07, 'block_shed' => 1.5, 'pressure' => 3.52, 'broken_tackle' => .95] as $type => $reach) {
            foreach ($byType[$type] ?? [] as $event) {
                $a = $event[3];
                $b = $event[4];
                $this->check($a >= 0 && $b >= 0 && $actors[$a]['side'] !== $actors[$b]['side'], 'Invalid contact identities.');
                $at = $this->sample($frames, $event[1]);
                $this->check($this->distance($at[3][$a], $at[3][$b]) <= $reach, 'Contact beyond reach.');
                if (in_array($type, ['block', 'block_shed', 'broken_tackle'], true)) {
                    $this->check($actors[$a]['side'] === $context->side && $actors[$b]['side'] !== $context->side, 'Invalid offensive contact participants.');
                }
                if ($type === 'pressure') {
                    $this->check($actors[$a]['side'] !== $context->side && $b === $initial, 'Invalid pressure participants.');
                }
            }
        }
        $carrier = $last[2];
        $terminalExact = $this->exact(end($events)[5]['position'] ?? null, array_slice($last[3][$carrier], 0, 2));
        $receiver = null;
        $targetReceiver = null;
        $tackler = null;
        $interceptor = null;
        $this->check($actors[$initial]['side'] === $context->side && ($r['actors'][$initial]['key'] === 'offense_QB'), 'Invalid initial carrier.');
        if ($throw) {
            $this->check($r['mode'] === 'passing' && $throw[3] === $initial && $actors[$initial]['role'] === 'QB', 'Invalid passer.');
            $release = $this->sample($frames, $throw[1]);
            $this->check(FieldPosition::official($release[3][$initial][0] / 100, $context->state) <= $context->state['spot'] + .02, 'Pass beyond scrimmage.');
            $aim = $throw[5]['actualTarget'] ?? null;
            $this->check(is_array($aim) && array_is_list($aim) && count($aim) === 2 && $this->integer($aim[0], -100, 12100) && $this->integer($aim[1], -100, 5433)
                && ($aim[0] - $release[3][$initial][0]) * $r['direction'] > 0, 'Unsupported backward or lateral pass.');
            $this->check((int) ($catch !== null) + (int) ($intercept !== null) + (int) isset($byType['incomplete']) === 1, 'Pass needs exactly one resolution.');
            $resolved = $catch ?? $intercept ?? $byType['incomplete'][0];
            $this->check($resolved[1] > $throw[1], 'Pass must travel before resolution.');
            if (! $catch) {
                $this->check($resolved[1] === $last[1], 'Recording continues after pass resolution.');
            }
            foreach ($frames as $f) {
                if ($f[1] > $throw[1] && $f[1] < $resolved[1]) {
                    $this->check($f[4][3] === 1 && $f[4][4] === -1 && $f[4][1] > 0 && $f[4][0] > 0 && $f[4][0] < 12000 && ! $this->out($f[4][2] / 100)
                        && ($f[4][0] - $release[3][$initial][0]) * $r['direction'] >= -.02, 'Invalid pass flight.');
                }
            }
            if ($catch || $intercept) {
                $event = $catch ?? $intercept;
                $index = $event[3];
                $this->check(! $catch || in_array($r['actors'][$index]['key'] ?? null, $setup['eligibleReceiverKeys'], true), 'Ineligible receiver.');
                $this->check($index >= 0 && $index !== $initial && ($actors[$index]['side'] === $context->side) === ($catch !== null), 'Catch side mismatch.');
                $at = $this->sample($frames, $event[1]);
                $contact = $event[5]['contact'] ?? null;
                $this->check(is_array($contact) && array_is_list($contact) && count($contact) === 3 && $this->integer($contact[0], 0, 12000)
                    && $this->integer($contact[1], 44, 256) && $this->integer($contact[2], 0, 5333), 'Missing or invalid actual catch contact.');
                $catchPoint = [$contact[0], $contact[2]];
                $catchExact = $this->exact($event[5]['contactExact'] ?? null, $contact);
                $this->check($catchExact[0] > 0 && $catchExact[0] < 120 && $catchExact[2] > 0 && $catchExact[2] < 53.33, 'Catch contact outside field.');
                $this->check($this->distance($catchPoint, $at[3][$index]) <= ($catch ? 1.73 : 1.33), 'Catch beyond participant reach.');
                $this->check(($contact[0] - $release[3][$initial][0]) * $r['direction'] > 0, 'Backward or lateral catch.');
                if ($catch && FieldPosition::official($catchExact[0], $context->state) >= 100 - 1e-8) {
                    $this->check($reason === 'touchdown' && $event[1] === $last[1], 'Recording continues after touchdown catch.');
                }
                $this->check(! $this->out($at[3][$index][1] / 100) && $at[3][$index][0] > 0 && $at[3][$index][0] < 12000, 'Catch out of bounds.');
                $prior = $this->sample($frames, max($throw[1], $event[1] - 16667));
                $this->check($this->distance([$prior[4][0], $prior[4][2]], $catchPoint) <= 1.6, 'Impossible catch contact.');
                if ($catch) {
                    $receiver = $index;
                    $this->check($carrier === $index, 'Receiver control mismatch.');
                } else {
                    $interceptor = $index;
                    $this->check($reason === 'interception' && $event[1] === $last[1] && $last[4][4] === $index, 'Interception must end at catch.');
                }
            }
        } else {
            $this->check(! $catch && ! $intercept && ! isset($byType['incomplete']) && $carrier === ($handoff ? $handoff[4] : $initial), 'Possession changed without pass or handoff.');
        }
        foreach ($frames as $frameIndex => $f) {
            // Contact samples can precede the terminal sample within the same tick.
            $isLast = $frameIndex === array_key_last($frames);
            $holder = $catch && $f[1] >= $catch[1] ? $receiver : ($handoff && $f[1] >= $handoff[1] ? $handoff[4] : $initial);
            $flight = $throw && $f[0] >= $throw[0] && (! $catch || $f[1] < $catch[1]);
            if (! $isLast) {
                $this->check($f[2] === $holder, 'Unexpected control change.');
                $this->check($flight ? ($f[4][3] === 1 && $f[4][4] === -1) : ($f[4][3] === 0 && $f[4][4] === $holder), 'Illegal possession or flight transition.');
            } else {
                $expected = $interceptor ?? ($reason === 'incomplete' ? -1 : $holder);
                $this->check($f[4][3] === 2 && $f[4][4] === $expected, 'Invalid terminal ball ownership.');
                if ($expected >= 0) {
                    $this->check($this->distance([$f[4][0], $f[4][2]], $f[3][$expected]) <= .02, 'Terminal ball detached from holder.');
                }
            }
        }
        $point = $last[3][$carrier];
        $exactPoint = $terminalExact;
        if ($interceptor !== null || ($catch && $catch[1] === $last[1] && $reason === 'touchdown')) {
            $point = $catchPoint;
            $exactPoint = [$catchExact[0], $catchExact[2]];
        }
        $continuous = FieldPosition::official($exactPoint[0], $context->state);
        $boundary = $continuous >= 100 - 1e-8 ? 'touchdown' : ($continuous <= 1e-8 ? 'safety' : null);
        $outcome = $reason;
        if (in_array($reason, ['tackle', 'sack'], true) || ($reason === 'safety' && isset($byType['tackle']))) {
            $event = $byType['tackle'][0] ?? null;
            $this->check($event !== null && $event[1] === $last[1] && $event[3] === $carrier && $event[4] >= 0, 'Missing actual tackle participant.');
            $tackler = $event[4];
            $this->check($actors[$tackler]['side'] !== $context->side && $this->distance($point, $last[3][$tackler]) <= .93 && $boundary !== 'touchdown' && ! $this->out($exactPoint[1]), 'Invalid tackle contact.');
            $this->check($reason === 'safety' || ($reason === 'sack') === ($r['mode'] === 'passing' && ! $throw && $continuous <= $context->state['spot']), 'Invalid sack classification.');
            $outcome = $boundary ?? $reason;
        } elseif ($reason === 'incomplete') {
            $this->check($throw !== null && isset($byType['incomplete']) && ! $catch && ! $intercept, 'Invalid incompletion.');
            $cause = $byType['incomplete'][0][5]['cause'] ?? null;
            if ($cause === 'drop') {
                $drop = $byType['incomplete'][0];
                $index = $drop[3];
                $this->check(in_array($r['actors'][$index]['key'] ?? null, $setup['eligibleReceiverKeys'], true), 'Ineligible target.');
                $targetReceiver = $index;
                $contact = $drop[5]['contact'] ?? null;
                $attempt = array_values(array_filter($byType['catch_attempt'] ?? [], fn ($event) => $event[1] === $drop[1] && $event[3] === $index && ($event[5]['success'] ?? null) === false));
                $this->check($index >= 0 && $actors[$index]['side'] === $context->side && count($attempt) === 1
                    && is_array($contact) && array_is_list($contact) && count($contact) === 3 && $this->integer($contact[0], 0, 12000) && $this->integer($contact[1], 44, 256) && $this->integer($contact[2], 0, 5333)
                    && $this->distance([$contact[0], $contact[2]], $last[3][$index]) <= 1.73, 'Invalid dropped catch evidence.');
            }
            if ($cause === 'breakup') {
                $breakup = $byType['pass_breakup'][0] ?? null;
                $index = $breakup[3] ?? -1;
                $contact = $breakup[5]['contact'] ?? null;
                $this->check(count($byType['pass_breakup'] ?? []) === 1 && $breakup[1] === $last[1] && $index >= 0 && $actors[$index]['side'] !== $context->side && $breakup[4] === -1
                    && is_array($contact) && array_is_list($contact) && count($contact) === 3 && $this->integer($contact[0], 0, 12000) && $this->integer($contact[1], 44, 256) && $this->integer($contact[2], 0, 5333), 'Invalid pass breakup.');
                $this->exact($breakup[5]['contactExact'] ?? null, $contact);
                $this->check($this->distance([$contact[0], $contact[2]], $last[3][$index]) <= 1.33 && $this->distance([$contact[0], $contact[2]], [$last[4][0], $last[4][2]]) <= .02 && abs($contact[1] - $last[4][1]) <= 1, 'Breakup beyond actual ball reach.');
                $attempt = array_filter($byType['catch_attempt'] ?? [], fn ($e) => $e[1] === $last[1] && $e[3] === $index && ($e[5]['success'] ?? null) === false);
                $this->check(count($attempt) === 1 && $byType['incomplete'][0][3] === $index, 'Missing breakup attempt.');
            }
            $this->check(($cause === 'ground' && $last[4][1] <= 1) || ($cause === 'out' && ($this->out($last[4][2] / 100) || $last[4][0] <= 0 || $last[4][0] >= 12000))
                || ($cause === 'drop' && isset($byType['catch_attempt'])) || $cause === 'breakup', 'No incompletion evidence.');
            $continuous = $context->state['spot'];
        } elseif ($reason === 'interception') {
            $this->check($interceptor !== null, 'Missing interception.');
        } else {
            $this->check(($boundary === 'touchdown' && $reason === 'touchdown') || ($reason === 'safety' && $boundary === 'safety' && ($this->out($exactPoint[1]) || $exactPoint[0] <= 1e-8 || $exactPoint[0] >= 120 - 1e-8)) || ($reason === 'out_of_bounds' && $this->out($exactPoint[1])), 'No boundary crossing.');
            $outcome = $boundary ?? 'tackle';
        }
        foreach (array_slice($frames, 0, -1) as $f) {
            if ($f[4][3] === 0) {
                $p = $f[3][$f[2]];
                // A 0.01-yard recording can round the last live sample onto the
                // boundary. Permit only the adjacent sample of that actual finish.
                $adjacent = $last[0] - $f[0] <= 2;
                $goalRounding = $adjacent && $reason === 'touchdown' && abs(FieldPosition::official($p[0] / 100, $context->state) - 100) < .005;
                $sideRounding = $adjacent && in_array($reason, ['out_of_bounds', 'safety'], true) && ($p[1] === 0 || $p[1] === 5333);
                $backRounding = $adjacent && $reason === 'safety' && ($p[0] === 0 || $p[0] === 12000);
                $this->check((FieldPosition::boundary($p[0] / 100, $context->state) !== 'touchdown' || $goalRounding)
                    && (($p[0] > 0 && $p[0] < 12000) || $backRounding) && (! $this->out($p[1] / 100) || $sideRounding), 'Recording continues after dead ball.');
            }
            $this->check($f[4][3] !== 2, 'Recording continues after dead ball.');
        }
        $spot = match ($outcome) {
            'touchdown' => 100,'safety' => 0,'incomplete' => $context->state['spot'], default => ($interceptor !== null && $continuous >= 100 - 1e-8 ? 100 : ($interceptor !== null && $continuous <= 1e-8 ? 0 : max(1, min(99, (int) round($continuous)))))
        };
        $gain = $spot - $context->state['spot'];
        $scramble = $r['mode'] === 'passing' && ! $throw && $reason !== 'sack' && ! ($reason === 'safety' && $tackler !== null && $continuous <= $context->state['spot']);
        $role = $actors[$receiver ?? $targetReceiver ?? $carrier]['role'];
        if ($throw && $receiver === null && $targetReceiver === null) {
            $role = '';
        }
        $participants = [];
        $allParticipants = array_values($actors);
        foreach (['carrier' => $interceptor ?? $carrier, 'passer' => $r['mode'] === 'passing' ? $initial : null, 'receiver' => $receiver, 'target' => $targetReceiver ?? $receiver, 'tackler' => $tackler, 'interceptor' => $interceptor] as $key => $i) {
            $participants[$key] = $i === null ? null : $actors[$i];
        }
        $r['result'] = ['reason' => $reason, 'yards' => $gain, 'time' => $duration, 'passOutcome' => $throw ? ($receiver !== null ? 'complete' : $reason) : null];

        return ['source' => 'arcade', 'outcome' => $outcome, 'gain' => $gain, 'flip' => $interceptor !== null, 'call' => $setup['call'], 'kind' => $setup['kind'], 'carrier' => $role,
            'qb_designed_run' => $r['mode'] === 'running' && $role === 'QB', 'scramble' => $scramble, 'out_of_bounds' => $reason === 'out_of_bounds', 'target' => $catch ? FieldPosition::official($catchExact[0], $context->state) - $context->state['spot'] : 0,
            'throwaway' => $throw !== null && $receiver === null && $targetReceiver === null, 'duration' => $duration, 'spot' => $spot, 'continuous_spot' => $continuous, 'elapsed_seconds' => (int) ceil($duration), 'participants' => $participants, 'arcade_participants' => $participants, 'arcade_on_field' => $allParticipants,
            'animation' => $this->animation($context, $r, $actors), 'recording' => $r, 'summary' => 'Arcade '.str_replace('_', ' ', $outcome).' ('.$gain.' yards)'];
    }

    private function animation(ArcadeContext $context, array $r, array $actors): array
    {
        $tracks = [];
        $ball = [];
        $holders = [];
        foreach ($actors as $i => $a) {
            $path = [];
            foreach ($r['frames'] as $f) {
                $p = $f[3][$i];
                $path[] = [$f[1] / 1000000, $p[0] / 100, 0, $p[1] / 100];
            }
            $tracks[] = $r['actors'][$i] + ['team' => $a['side'] === $context->side ? 'offense' : 'defense', 'path' => $path];
        }
        foreach ($r['frames'] as $f) {
            $b = $f[4];
            $ball[] = [$f[1] / 1000000, $b[0] / 100, $b[1] / 100, $b[2] / 100];
            $a = $actors[$b[4]] ?? null;
            $holders[] = [$f[1] / 1000000, $a ? ($a['side'] === $context->side ? 'offense' : 'defense') : null, $a['role'] ?? null];
        }

        return ['source' => 'arcade', 'world_oriented' => true, 'duration' => end($r['frames'])[1] / 1000000, 'players' => $tracks, 'ball' => $ball, 'ballHolders' => $holders, 'events' => array_map(fn ($event) => [$event[1] / 1000000, ucfirst(str_replace('_', ' ', $event[2]))], $r['events']),
            'line' => $r['field']['startX'], 'direction' => $r['direction'], 'recording' => $r];
    }

    private function sample(array $frames, int $time): array
    {
        $left = $frames[0];
        foreach ($frames as $right) {
            if ($right[1] <= $time) {
                $left = $right;

                continue;
            }
            if ($right[1] > $time) {
                if ($right[1] === $left[1]) {
                    return $right;
                }
                $fraction = ($time - $left[1]) / ($right[1] - $left[1]);
                $result = $left;
                foreach ($left[3] as $i => $p) {
                    foreach ([0, 1] as $j) {
                        $result[3][$i][$j] = $p[$j] + ($right[3][$i][$j] - $p[$j]) * $fraction;
                    }
                }
                foreach ([0, 1, 2] as $j) {
                    $result[4][$j] = $left[4][$j] + ($right[4][$j] - $left[4][$j]) * $fraction;
                }

                return $result;
            }
            $left = $right;
        }

        return $left;
    }

    /** Precise physical evidence may only refine its recorded centiyard cell. */
    private function exact(mixed $point, array $quantized): array
    {
        $this->check(is_array($point) && array_is_list($point) && count($point) === count($quantized), 'Missing precise physical point.');
        foreach ($point as $i => $value) {
            $this->check((is_int($value) || is_float($value)) && is_finite((float) $value)
                && abs($value - $quantized[$i] / 100) <= .005001, 'Precise point disagrees with recorded movement.');
        }

        return $point;
    }

    private function distance(array $a, array $b): float
    {
        return hypot($a[0] - $b[0], $a[1] - $b[1]) / 100;
    }

    private function integer(mixed $v, int $min, int $max): bool
    {
        return is_int($v) && $v >= $min && $v <= $max;
    }

    private function out(float $z): bool
    {
        return $z <= 0 || $z >= 53.33;
    }

    private function check(bool $valid, string $message): void
    {
        if (! $valid) {
            throw new DomainException($message);
        }
    }
}
