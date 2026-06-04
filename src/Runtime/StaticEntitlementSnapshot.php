<?php

declare(strict_types=1);

namespace Larena\Licensing\Runtime;

use DateTimeImmutable;
use InvalidArgumentException;
use Larena\Licensing\Contracts\EntitlementSnapshot;
use Larena\Licensing\Enums\EntitlementStatus;

final readonly class StaticEntitlementSnapshot implements EntitlementSnapshot
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
    ) {
        foreach ([
            'keyId' => $this->keyId,
            'signature' => $this->signature,
        ] as $field => $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException("Entitlement snapshot {$field} must not be empty.");
            }
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

    public function capabilityKeys(): array
    {
        return $this->capabilityKeys;
    }

    public function expiresAt(): ?DateTimeImmutable
    {
        return $this->expiresAt;
    }
}
