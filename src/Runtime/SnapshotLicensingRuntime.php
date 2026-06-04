<?php

declare(strict_types=1);

namespace Larena\Licensing\Runtime;

use DateTimeImmutable;
use Larena\Licensing\Contracts\Capability;
use Larena\Licensing\Contracts\EntitlementSnapshot;
use Larena\Licensing\Contracts\LicenseDecision;
use Larena\Licensing\Contracts\LicensingRuntime;
use Larena\Licensing\Enums\EntitlementStatus;

final readonly class SnapshotLicensingRuntime implements LicensingRuntime
{
    public function __construct(private ?DateTimeImmutable $now = null)
    {
    }

    public function decide(Capability $capability, ?EntitlementSnapshot $snapshot = null): LicenseDecision
    {
        if ($capability->isFreeByDefault()) {
            return RuntimeLicenseDecision::allowed($capability->key(), 'capability_free_by_default');
        }

        if ($snapshot === null) {
            return $this->lockUnknown($capability, 'entitlement_snapshot_missing');
        }

        if (!$snapshot->status()->canUnlockPaidCapabilities()) {
            return RuntimeLicenseDecision::locked($capability->key(), 'entitlement_' . $snapshot->status()->value);
        }

        if ($this->isExpired($snapshot)) {
            return RuntimeLicenseDecision::locked($capability->key(), 'entitlement_expired');
        }

        if (!in_array($capability->key(), $snapshot->capabilityKeys(), true)) {
            return $this->lockUnknown($capability, 'capability_not_entitled');
        }

        return match ($snapshot->status()) {
            EntitlementStatus::Trial => RuntimeLicenseDecision::limited($capability->key(), 'entitlement_trial'),
            EntitlementStatus::Grace => RuntimeLicenseDecision::limited($capability->key(), 'entitlement_grace'),
            default => RuntimeLicenseDecision::allowed($capability->key(), 'entitlement_active'),
        };
    }

    private function lockUnknown(Capability $capability, string $reasonCode): LicenseDecision
    {
        if ($capability->failClosedWhenUnknown()) {
            return RuntimeLicenseDecision::locked($capability->key(), $reasonCode);
        }

        return RuntimeLicenseDecision::denied($capability->key(), $reasonCode);
    }

    private function isExpired(EntitlementSnapshot $snapshot): bool
    {
        $expiresAt = $snapshot->expiresAt();

        if ($expiresAt === null) {
            return false;
        }

        return $expiresAt <= ($this->now ?? new DateTimeImmutable());
    }
}
