<?php

declare(strict_types=1);

namespace Larena\Licensing\Site;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * The site's licence as the admin banner reads it: the state, why, and the dates that go into its words. It never
 * carries a key, a signature or the site key.
 */
final readonly class SiteLicenseStatus
{
    public function __construct(
        public LicensingState $state,
        public string $reasonCode,
        public ?VerifiedSnapshot $snapshot = null,
        public bool $clockSetBack = false,
    ) {
    }

    /** Whether the snapshot's capabilities count (installed code keeps them when stale or after the licence ended). */
    public function capabilitiesCount(): bool
    {
        return $this->snapshot !== null && match ($this->state) {
            LicensingState::Active, LicensingState::Stale => true,
            LicensingState::Expired => $this->reasonCode === 'licence_expired',
            default => false,
        };
    }

    public function issuedAt(): ?DateTimeImmutable
    {
        return $this->snapshot?->issuedAt;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'state' => $this->state->value,
            'reason_code' => $this->reasonCode,
            'licence_status' => $this->snapshot?->licenceStatus,
            'issued_at' => $this->snapshot?->issuedAt->format(DateTimeInterface::ATOM),
            'fresh_until' => $this->snapshot?->freshUntil()->format(DateTimeInterface::ATOM),
            'capabilities' => $this->snapshot === null ? [] : $this->snapshot->capabilities,
            'clock_set_back' => $this->clockSetBack,
        ];
    }
}
