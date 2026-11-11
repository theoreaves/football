<?php

namespace App\Services\Arcade;

use DomainException;

/**
 * Schema 1 accepts bounded per-tick evidence, never client scores, yards or state.
 * Minimal run/pass only: passes stop at catch/interception/incompletion. No returns,
 * kicks, tries, fumbles, laterals or post-catch continuation are represented.
 * Runs use final-position stops only: pushed-back forward-progress rulings are
 * deferred and must not be submitted by a future adapter. Passes are forward only
 * and end at the catch; ball-flight validation is bounded evidence, not a physics
 * replay or proof of client honesty. Only ArcadeSession::accept applies lifecycle.
 * This is an outcome PROPOSAL, not an official play or an anti-cheat replay engine.
 */
final class ArcadeResultContract
{
    public const MAX_TICKS = 1800;

    public const WIDTH = 160 / 3;

    private const PLAYER_STEP = 0.2; // 12 yards/sec at exactly 60 ticks/sec.

    private const BALL_STEP = 1.5;

    private const CONTACT = 1.5;

    public function derive(ArcadeSession $session, array $envelope): array
    {
        $binding = $session->envelope();
        self::keys($envelope, [...array_keys($binding), 'evidence']);
        foreach ($binding as $key => $value) {
            self::require(($envelope[$key] ?? null) === $value, 'Envelope binding mismatch: '.$key);
        }
        $e = $envelope['evidence'];
        self::require(is_array($e), 'Missing evidence.');
        self::keys($e, ['kind', 'carrier', 'receiver', 'tackler', 'interceptor', 'release_tick', 'end', 'frames']);
        self::require(in_array($e['kind'], ['run', 'pass'], true), 'Unsupported play kind.');
        self::require(in_array($e['end'], ['tackle', 'out', 'crossing', 'complete', 'intercepted', 'incomplete'], true), 'Unsupported ending.');
        $context = $session->context;
        $actors = $context->actors;
        $carrier = $this->actor($actors, $e['carrier'], $context->side);
        foreach (['receiver', 'tackler', 'interceptor'] as $role) {
            if ($e[$role] !== null) {
                $participant = $this->actor($actors, $e[$role], $role === 'receiver' ? $context->side : ($context->side === 'home' ? 'away' : 'home'));
                if ($role !== 'receiver') {
                    self::require(preg_match('/^(DE[12]|DT[12]|LB[1-5]|CB[1-4]|S[12])$/D', $participant['role']) === 1, 'Invalid defensive role.');
                }
            }
        }
        if ($e['kind'] === 'run') {
            self::require(in_array($carrier['role'], ['QB', 'RB', 'FB'], true), 'Invalid runner role.');
            self::require($e['receiver'] === null && $e['interceptor'] === null && $e['release_tick'] === null, 'Run cannot include a pass.');
            self::require(in_array($e['end'], ['tackle', 'out', 'crossing'], true), 'Unsupported run ending.');
            self::require(($e['end'] === 'tackle') === ($e['tackler'] !== null), 'Tackle needs exactly one defender.');
        } else {
            self::require($carrier['role'] === 'QB' && $e['tackler'] === null, 'Invalid passer or deferred post-catch play.');
            self::require(in_array($e['end'], ['complete', 'intercepted', 'incomplete'], true), 'Unsupported pass ending.');
            self::require(($e['end'] === 'complete') === ($e['receiver'] !== null), 'Completion needs a receiver.');
            self::require(($e['end'] === 'intercepted') === ($e['interceptor'] !== null), 'Interception needs a defender.');
            if ($e['receiver'] !== null) {
                self::require(preg_match('/^(WR[1-5]?|TE[12]?|RB|FB)$/D', $actors[$e['receiver']]['role']) === 1, 'Invalid receiving role.');
            }
        }
        $frames = $e['frames'];
        self::require(is_array($frames) && array_is_list($frames) && count($frames) >= 2 && count($frames) <= self::MAX_TICKS + 1, 'Invalid trace size.');
        $ticks = count($frames) - 1;
        if ($e['kind'] === 'pass') {
            self::require(is_int($e['release_tick']) && $e['release_tick'] >= 1 && $e['release_tick'] < $ticks, 'Invalid release tick.');
        }
        $previous = null;
        foreach ($frames as $tick => $frame) {
            self::require(is_array($frame), 'Invalid frame.');
            self::keys($frame, ['tick', 'actors', 'ball']);
            self::require($frame['tick'] === $tick && is_array($frame['actors']), 'Ticks must be consecutive from zero.');
            self::keys($frame['actors'], array_keys($actors));
            foreach ($actors as $id => $actor) {
                $position = $frame['actors'][$id];
                self::position($position);
                if ($tick === 0) {
                    self::require(self::distance($position, [$actor['x'], $actor['y']]) < 0.000001, 'Actor start mismatch.');
                } else {
                    self::require(self::distance($position, $previous['actors'][$id]) <= self::PLAYER_STEP + 0.000001, 'Impossible actor movement.');
                }
            }
            self::require(is_array($frame['ball']) && array_is_list($frame['ball']) && count($frame['ball']) === 3, 'Ball needs x, y and height.');
            self::position(array_slice($frame['ball'], 0, 2));
            $z = $frame['ball'][2];
            self::require((is_int($z) || is_float($z)) && is_finite((float) $z) && $z >= 0 && $z <= 40, 'Invalid ball height.');
            if ($previous !== null) {
                self::require(self::distance($frame['ball'], $previous['ball']) <= self::BALL_STEP + 0.000001, 'Impossible ball movement.');
            }
            $holding = $e['kind'] === 'run' || $tick <= $e['release_tick'];
            if ($holding) {
                self::require(self::distance(array_slice($frame['ball'], 0, 2), $frame['actors'][$e['carrier']]) < 0.000001 && abs($z - 1.5) < 0.000001, 'Ball must follow its carrier.');
                if ($tick < $ticks) {
                    self::require(FieldPosition::boundary($frame['ball'][0], $context->state) === null && ! self::out($frame['ball']), 'Trace continues after dead ball.');
                }
            } else {
                $release = $frames[$e['release_tick']]['ball'];
                $releaseSpot = FieldPosition::official($release[0], $context->state);
                self::require($releaseSpot <= $context->state['spot'], 'Forward pass released beyond the line of scrimmage.');
                self::require(FieldPosition::official($frame['ball'][0], $context->state) > $releaseSpot
                    && FieldPosition::official($frame['ball'][0], $context->state) >= FieldPosition::official($previous['ball'][0], $context->state), 'Backward and lateral passes are deferred.');
                if ($tick < $ticks) {
                    self::require($z > 0 && ! self::out($frame['ball']), 'Trace continues after incomplete pass.');
                }
            }
            $previous = $frame;
        }
        $last = $frames[$ticks];
        $point = $last['actors'][$e['carrier']];
        $outcome = $e['end'];
        if ($e['kind'] === 'run') {
            $boundary = FieldPosition::boundary($point[0], $context->state);
            if ($boundary !== null) {
                self::require(! self::out($point), 'Simultaneous goal and sideline crossing is ambiguous.');
                self::require($e['end'] === 'crossing', 'Goal crossing must terminate the play.');
                $outcome = $boundary;
            } elseif (self::out($point)) {
                self::require($e['end'] === 'out', 'Sideline must terminate the play.');
            } else {
                self::require($e['end'] === 'tackle', 'No terminal event.');
                self::require(self::distance($point, $last['actors'][$e['tackler']]) <= self::CONTACT, 'Impossible tackle contact.');
            }
        } elseif ($e['end'] === 'incomplete') {
            self::require($last['ball'][2] === 0 || $last['ball'][2] === 0.0 || self::out($last['ball']), 'Incompletion needs ground or sideline evidence.');
            $point = [FieldPosition::physical($context->state['spot'], $context->state), 0];
        } else {
            $target = $e['receiver'] ?? $e['interceptor'];
            $point = $last['actors'][$target];
            self::require($last['ball'][2] > 0 && $last['ball'][2] <= 3 && ! self::out($point) && ! self::out($last['ball'])
                && self::distance($point, array_slice($last['ball'], 0, 2)) <= self::CONTACT, 'Impossible catch contact.');
            // The ball, not the receiver's center, determines the dead spot and goal region.
            $point = array_slice($last['ball'], 0, 2);
            // Returns/touchbacks after interceptions need their own future contract.
            if ($e['end'] === 'intercepted') {
                self::require(FieldPosition::boundary($point[0], $context->state) === null, 'End-zone interceptions are deferred.');
            } else {
                $outcome = FieldPosition::boundary($point[0], $context->state) ?? 'complete';
            }
        }
        $continuous = FieldPosition::official($point[0], $context->state);
        // Determine goal/safety above BEFORE rounding. Non-scoring spots never round into an end zone.
        $spot = match ($outcome) {
            'incomplete' => $context->state['spot'],
            'touchdown' => 100, 'safety' => 0,
            default => max(1, min(99, (int) round($continuous))),
        };

        return $binding + [
            'proposal_only' => true, 'kind' => $e['kind'], 'outcome' => $outcome,
            'spot' => $spot, 'continuous_spot' => $continuous, 'yards' => $spot - $context->state['spot'],
            'elapsed_ticks' => $ticks, 'elapsed_seconds' => intdiv($ticks + 59, 60),
            'participants' => array_map(fn ($id) => $id === null ? null : ['actor_id' => $id] + $actors[$id], array_intersect_key($e, array_flip(['carrier', 'receiver', 'tackler', 'interceptor']))),
        ];
    }

    private function actor(array $actors, mixed $id, string $side): array
    {
        self::require(is_string($id) && isset($actors[$id]) && $actors[$id]['side'] === $side, 'Unknown actor or wrong side.');

        return $actors[$id];
    }

    public static function position(mixed $position): void
    {
        self::require(is_array($position) && array_is_list($position) && count($position) === 2, 'Position needs x and y.');
        foreach ($position as $value) {
            self::require((is_int($value) || is_float($value)) && is_finite((float) $value), 'Non-finite coordinate.');
        }
        self::require($position[0] >= 0 && $position[0] <= 120 && $position[1] >= 0 && $position[1] <= self::WIDTH, 'Position outside field bounds.');
    }

    private static function out(array $position): bool
    {
        return $position[0] <= 0 || $position[0] >= 120 || $position[1] <= 0 || $position[1] >= self::WIDTH;
    }

    private static function distance(array $a, array $b): float
    {
        $sum = 0;
        foreach ($a as $i => $value) {
            $sum += ($value - $b[$i]) ** 2;
        }

        return sqrt($sum);
    }

    private static function keys(array $value, array $expected): void
    {
        $actual = array_keys($value);
        sort($actual);
        sort($expected);
        self::require($actual === $expected, 'Unexpected or missing fields.');
    }

    private static function require(bool $condition, string $message): void
    {
        if (! $condition) {
            throw new DomainException($message);
        }
    }
}
