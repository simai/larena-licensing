<?php

declare(strict_types=1);

use Larena\Licensing\Enums\EntitlementStatus;
use Larena\Licensing\Enums\LicenseDecisionStatus;
use Larena\Licensing\Runtime\SnapshotLicensingRuntime;
use Larena\Licensing\Runtime\StaticCapability;
use Larena\Licensing\Runtime\StaticEntitlementSnapshot;

require_once __DIR__ . '/../../vendor/autoload.php';

function assert_licensing_runtime_true(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$runtime = new SnapshotLicensingRuntime(new DateTimeImmutable('2026-06-04T00:00:00+00:00'));
$freeCapability = new StaticCapability('core.basic', 'larena/core', freeByDefault: true);
$paidCapability = new StaticCapability('visibility.autopilot', 'larena/visibility');
$snapshot = new StaticEntitlementSnapshot(
    status: EntitlementStatus::Active,
    keyId: 'key-1',
    signature: 'signature-fixture',
    capabilityKeys: ['visibility.autopilot'],
    expiresAt: new DateTimeImmutable('2026-07-04T00:00:00+00:00'),
);

$freeDecision = $runtime->decide($freeCapability);
$paidDecision = $runtime->decide($paidCapability, $snapshot);

assert_licensing_runtime_true($freeDecision->isAllowed(), 'Free capability must be allowed without entitlement snapshot.');
assert_licensing_runtime_true($freeDecision->reasonCode() === 'capability_free_by_default', 'Free capability must explain default access.');
assert_licensing_runtime_true($paidDecision->isAllowed(), 'Paid capability must be allowed by active entitlement snapshot.');
assert_licensing_runtime_true($paidDecision->status() === LicenseDecisionStatus::Allowed, 'Active paid capability must produce allowed decision.');
assert_licensing_runtime_true($paidDecision->reasonCode() === 'entitlement_active', 'Active paid capability must explain entitlement state.');

$trialDecision = $runtime->decide(
    $paidCapability,
    new StaticEntitlementSnapshot(
        status: EntitlementStatus::Trial,
        keyId: 'key-1',
        signature: 'signature-fixture',
        capabilityKeys: ['visibility.autopilot'],
    ),
);

assert_licensing_runtime_true($trialDecision->isAllowed(), 'Trial entitlement may permit bounded execution.');
assert_licensing_runtime_true($trialDecision->status() === LicenseDecisionStatus::Limited, 'Trial entitlement must produce limited decision.');

echo "LicensingCapabilityDecisionRuntimeTest passed.\n";
