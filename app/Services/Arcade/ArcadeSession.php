<?php

namespace App\Services\Arcade;

use DomainException;

/**
 * In-memory lifecycle ONLY. Caller-owned sessions must never be client-hydrated.
 * The caller must enforce a single active session for a saved game's play version.
 * Persistent unique receipts, transactions/locking and an authorized HTTP adapter
 * are deferred to Phase 4B. There is NO cross-request or concurrency guarantee.
 */
final class ArcadeSession
{
    private bool $cancelled = false;

    private bool $expired = false;

    private ?array $receipt = null;

    private ?string $acceptedHash = null;

    private function __construct(
        public readonly string $id,
        public readonly string $resultId,
        public readonly ArcadeContext $context,
        public readonly int $createdAt,
        public readonly int $expiresAt,
    ) {}

    public static function start(ArcadeContext $context, int $now, int $ttl = 120): self
    {
        $context->assertPlayable();
        if ($ttl < 1 || $ttl > 300 || $now < 0) {
            throw new DomainException('Invalid session lifetime.');
        }

        return new self(bin2hex(random_bytes(16)), bin2hex(random_bytes(16)), $context, $now, $now + $ttl);
    }

    public function envelope(): array
    {
        return $this->context->binding() + ['session_id' => $this->id, 'result_id' => $this->resultId, 'expires_at' => $this->expiresAt];
    }

    public function cancel(): void
    {
        if ($this->receipt === null) {
            $this->cancelled = true;
        }
    }

    public function accept(array $envelope, ArcadeContext $current, int $now): array
    {
        // Principal identity is rechecked even on a retry, not persisted access authorization.
        // A future HTTP caller must reauthorize access on every request, including retries.
        // An accepted identical retry survives expiry/state advancement without mutation,
        // but a different principal cannot retrieve its receipt.
        if ([$current->gameId, $current->userId, $current->worldId, $current->side]
            !== [$this->context->gameId, $this->context->userId, $this->context->worldId, $this->context->side]) {
            throw new DomainException('Session principal mismatch.');
        }
        $hash = ArcadeContext::hash($envelope);
        if ($this->receipt !== null) {
            if (! hash_equals($this->acceptedHash, $hash)) {
                throw new DomainException('Result ID already used for different evidence.');
            }

            return $this->receipt;
        }
        if ($now >= $this->expiresAt) {
            $this->expired = true;
        }
        if ($this->cancelled || $this->expired || $now < $this->createdAt) {
            throw new DomainException('Session cancelled or expired.');
        }
        $current->assertPlayable();
        if ($current->binding() !== $this->context->binding()) {
            throw new DomainException('Stale session snapshot.');
        }
        $receipt = (new ArcadeResultContract)->derive($this, $envelope);
        $this->acceptedHash = $hash;
        $this->receipt = $receipt;

        return $receipt;
    }
}
