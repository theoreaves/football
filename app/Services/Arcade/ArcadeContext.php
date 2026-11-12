<?php

namespace App\Services\Arcade;

use DomainException;

/**
 * Server-only input. The caller must authorize the current owner/user/world/game,
 * load the complete saved state and roster under a lock, and check every pending
 * decision and queued-game ownership. Never construct this from request JSON.
 * Explicit human controls are required; a future adapter must normalize legacy
 * missing controls with the existing coach policy before creating this context.
 */
final readonly class ArcadeContext
{
    public function __construct(
        public int $gameId,
        public int $userId,
        public int $worldId,
        public string $side,
        public array $state,
        public array $roster,
        public array $actors,
        public ?int $queuedOwnerId = null,
        public bool $pendingDecision = false,
        public string $rulesVersion = 'scrimmage-1',
        public string $tuningVersion = 'arcade-1',
    ) {}

    public function assertPlayable(): void
    {
        $s = $this->state;
        if (min($this->gameId, $this->userId, $this->worldId) < 1
            || ! in_array($this->side, ['home', 'away'], true)
            || ($s['possession'] ?? null) !== $this->side
            || ($s['controls'][$this->side] ?? null) !== 'human'
            || ($s['status'] ?? null) !== 'playing' || ($s['phase'] ?? null) !== 'scrimmage'
            || ! is_int($s['version'] ?? null) || $s['version'] < 0
            || ! is_int($s['quarter'] ?? null) || $s['quarter'] < 1
            || (! is_int($s['spot'] ?? null) && ! is_float($s['spot'] ?? null))
            || ! is_finite((float) $s['spot']) || $s['spot'] <= 0 || $s['spot'] >= 100
            || $this->pendingDecision || ($s['penalty_pending'] ?? false)
            || ($s['coin_toss']['pending'] ?? false) || ($s['overtime']['toss']['pending'] ?? false)
            || ($s['overtime']['toss']['call_pending'] ?? false)
            || ($this->queuedOwnerId !== null && $this->queuedOwnerId !== $this->userId)
            || $this->rulesVersion !== 'scrimmage-1' || $this->tuningVersion !== 'arcade-1') {
            throw new DomainException('Arcade context is not playable.');
        }
        if (count($this->actors) < 1 || count($this->actors) > 22) {
            throw new DomainException('Invalid actor assignments.');
        }
        $seen = [];
        foreach ($this->actors as $id => $actor) {
            if (! is_string($id) || ! preg_match('/^[a-zA-Z][a-zA-Z0-9_-]{0,63}$/D', $id)
                || ! is_array($actor) || ! is_int($actor['player_id'] ?? null)
                || ! in_array($actor['side'] ?? null, ['home', 'away'], true)
                || ! is_string($actor['role'] ?? null)
                || ($this->roster[$actor['side']]['players'][$actor['role']]['id'] ?? null) !== $actor['player_id']) {
                throw new DomainException('Actor must identify a saved roster role and side.');
            }
            $key = $actor['player_id'];
            if (isset($seen[$key])) {
                throw new DomainException('Duplicate saved actor.');
            }
            $seen[$key] = true;
            ArcadeResultContract::position([$actor['x'] ?? null, $actor['y'] ?? null]);
        }
    }

    public function binding(): array
    {
        return [
            'game_id' => $this->gameId, 'user_id' => $this->userId, 'world_id' => $this->worldId,
            'side' => $this->side, 'state_version' => $this->state['version'],
            'snapshot_hash' => self::hash([$this->state, $this->roster, $this->actors, $this->queuedOwnerId, $this->pendingDecision]),
            'rules_version' => $this->rulesVersion, 'tuning_version' => $this->tuningVersion,
            'schema_version' => 1,
        ];
    }

    public static function hash(array $value): string
    {
        // Hash the identical canonical JSON incrementally. Full-game histories
        // contain large recording tuples: sorting a recursive copy and encoding
        // that whole copy can exceed the web worker memory limit during submit.
        $hash = hash_init('sha256');
        $flags = JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION;
        $write = function (mixed $data, int $depth = 1) use (&$write, $hash, $flags): void {
            if (! is_array($data)) {
                hash_update($hash, json_encode($data, $flags, 513 - $depth));

                return;
            }
            if ($depth > 512) {
                throw new \JsonException('Maximum stack depth exceeded', JSON_ERROR_DEPTH);
            }
            $list = array_is_list($data);
            if (! $list) {
                ksort($data);
                $list = array_is_list($data);
            }
            hash_update($hash, $list ? '[' : '{');
            $first = true;
            foreach ($data as $key => $item) {
                if (! $first) {
                    hash_update($hash, ',');
                }
                $first = false;
                if (! $list) {
                    hash_update($hash, json_encode((string) $key, $flags).':');
                }
                $write($item, $depth + 1);
            }
            hash_update($hash, $list ? ']' : '}');
        };
        $write($value);

        return hash_final($hash);
    }
}
