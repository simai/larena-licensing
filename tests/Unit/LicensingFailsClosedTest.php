<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/Contracts/Capability.php';
require_once __DIR__ . '/../../src/Contracts/EntitlementSnapshot.php';
require_once __DIR__ . '/../../src/Contracts/LicensingRuntime.php';
require_once __DIR__ . '/../../src/Contracts/LicenseDecision.php';
require_once __DIR__ . '/../../src/Enums/EntitlementStatus.php';
require_once __DIR__ . '/../../src/Enums/LicenseDecisionStatus.php';

use Larena\Licensing\Contracts\Capability;
use Larena\Licensing\Contracts\EntitlementSnapshot;
use Larena\Licensing\Contracts\LicenseDecision;
use Larena\Licensing\Contracts\LicensingRuntime;
use Larena\Licensing\Enums\EntitlementStatus;
use Larena\Licensing\Enums\LicenseDecisionStatus;

function requireTrue(bool $actual, string $message): void
{
    if ($actual !== true) {
        throw new RuntimeException($message);
    }
}

function requireSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message);
    }
}

$entitlementFailedClosed = false;

try {
    EntitlementStatus::from('trusted_without_signature');
} catch (ValueError) {
    $entitlementFailedClosed = true;
}

requireTrue($entitlementFailedClosed, 'unknown entitlement status must fail closed');

$decisionFailedClosed = false;

try {
    LicenseDecisionStatus::from('allow_unknown_paid');
} catch (ValueError) {
    $decisionFailedClosed = true;
}

requireTrue($decisionFailedClosed, 'unknown license decision status must fail closed');

$capability = new class implements Capability {
    public function id(): string
    {
        return 'workflow.high_volume';
    }

    public function packageId(): string
    {
        return 'larena/workflow';
    }

    public function edition(): string
    {
        return 'enterprise';
    }

    public function paid(): bool
    {
        return true;
    }

    public function defaultFallback(): string
    {
        return 'locked_with_explain';
    }
};

$snapshot = new class implements EntitlementSnapshot {
    public function snapshotId(): string
    {
        return 'snapshot-1';
    }

    public function siteId(): string
    {
        return 'site-1';
    }

    public function status(): EntitlementStatus
    {
        return EntitlementStatus::Expired;
    }

    public function issuedAt(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-06-01T00:00:00+00:00');
    }

    public function expiresAt(): ?DateTimeImmutable
    {
        return new DateTimeImmutable('2026-06-02T00:00:00+00:00');
    }

    public function signatureId(): string
    {
        return 'sig-1';
    }

    public function enabledCapabilities(): array
    {
        return [];
    }
};

$runtime = new class implements LicensingRuntime {
    public function decide(Capability $capability, EntitlementSnapshot $snapshot): LicenseDecision
    {
        return new class($capability) implements LicenseDecision {
            public function __construct(private readonly Capability $capability)
            {
            }

            public function status(): LicenseDecisionStatus
            {
                return LicenseDecisionStatus::DeniedExpired;
            }

            public function allowed(): bool
            {
                return false;
            }

            public function capabilityId(): string
            {
                return $this->capability->id();
            }

            public function reason(): string
            {
                return 'entitlement expired';
            }

            public function adminExplanation(): string
            {
                return 'Capability workflow.high_volume requires an active enterprise entitlement.';
            }
        };
    }
};

$decision = $runtime->decide($capability, $snapshot);

requireSame(LicenseDecisionStatus::DeniedExpired, $decision->status(), 'expired entitlement must deny paid capability');
requireSame(false, $decision->allowed(), 'expired entitlement must fail closed');
requireSame('workflow.high_volume', $decision->capabilityId(), 'decision must identify blocked capability');
requireSame('entitlement expired', $decision->reason(), 'decision reason is required');
requireSame(
    'Capability workflow.high_volume requires an active enterprise entitlement.',
    $decision->adminExplanation(),
    'admin explain text is required'
);

echo "Licensing fail-closed test passed.\n";
