<?php

declare(strict_types=1);

namespace Larena\Licensing\Runtime;

use DateTimeImmutable;
use InvalidArgumentException;
use Larena\Licensing\Contracts\ScopedEntitlementSnapshot;
use Larena\Licensing\Enums\EntitlementStatus;

/**
 * A snapshot that knows its scope, its node limit and the environment it was
 * issued for.
 *
 * It is `Static` in the same sense as the existing snapshot: the values are handed
 * in. Nothing here verifies a signature or mints anything — that is a server-side
 * concern, and this package consumes what it is given.
 */
final readonly class StaticScopedEntitlementSnapshot implements ScopedEntitlementSnapshot
{
    /**
     * @param list<string> $capabilityKeys
     */
    public function __construct(
        private EntitlementStatus $status,
        private string $keyId,
        private string $signature,
        private array $capabilityKeys = [],
        private ?DateTimeImmutable $expiresAt = null,
        private ?string $scopeRef = null,
        private ?int $nodeLimit = null,
        private bool $operatorLevel = false,
        private ?string $environmentFingerprint = null,
    ) {
        foreach (['keyId' => $this->keyId, 'signature' => $this->signature] as $field => $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException("Entitlement snapshot {$field} must not be empty.");
            }
        }

        if ($this->nodeLimit !== null && $this->nodeLimit < 1) {
            throw new InvalidArgumentException('A node limit must be a positive integer.');
        }

        foreach ($this->capabilityKeys as $capabilityKey) {
            if (trim($capabilityKey) === '') {
                throw new InvalidArgumentException('Entitlement snapshot capability keys must not contain empty values.');
            }
        }
    }

    public function status(): EntitlementStatus
    {
        return $this->status;
    }

    public function keyId(): string
    {
        return $this->keyId;
    }

    public function signature(): string
    {
        return $this->signature;
    }

    /** @return list<string> */
    public function capabilityKeys(): array
    {
        return $this->capabilityKeys;
    }

    public function expiresAt(): ?DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function scopeRef(): ?string
    {
        return $this->scopeRef;
    }

    public function nodeLimit(): ?int
    {
        return $this->nodeLimit;
    }

    public function isOperatorLevel(): bool
    {
        return $this->operatorLevel;
    }

    public function environmentFingerprint(): ?string
    {
        return $this->environmentFingerprint;
    }
}
