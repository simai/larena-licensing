<?php

declare(strict_types=1);

use Larena\Licensing\Enums\EntitlementStatus;
use Larena\Licensing\Runtime\SnapshotLicensingRuntime;
use Larena\Licensing\Runtime\StaticCapability;
use Larena\Licensing\Runtime\StaticEntitlementSnapshot;

require_once __DIR__ . '/../../vendor/autoload.php';

function assert_licensing_fail_closed_true(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$runtime = new SnapshotLicensingRuntime(new DateTimeImmutable('2026-06-04T00:00:00+00:00'));
$paidCapability = new StaticCapability('visibility.autopilot', 'larena/visibility');

$missingSnapshot = $runtime->decide($paidCapability);

assert_licensing_fail_closed_true(!$missingSnapshot->isAllowed(), 'Missing snapshot must lock paid capability.');
assert_licensing_fail_closed_true($missingSnapshot->reasonCode() === 'entitlement_snapshot_missing', 'Missing snapshot must expose safe reason.');

foreach ([EntitlementStatus::InvalidSignature, EntitlementStatus::Tampered, EntitlementStatus::Expired, EntitlementStatus::Revoked] as $status) {
    $decision = $runtime->decide(
        $paidCapability,
        new StaticEntitlementSnapshot(
            status: $status,
            keyId: 'key-1',
            signature: 'signature-fixture',
            capabilityKeys: ['visibility.autopilot'],
        ),
    );

    assert_licensing_fail_closed_true(!$decision->isAllowed(), $status->value . ' snapshot must fail closed.');
    assert_licensing_fail_closed_true($decision->reasonCode() === 'entitlement_' . $status->value, $status->value . ' decision must explain entitlement status.');
}

$expiredByDate = $runtime->decide(
    $paidCapability,
    new StaticEntitlementSnapshot(
        status: EntitlementStatus::Active,
        keyId: 'key-1',
        signature: 'signature-fixture',
        capabilityKeys: ['visibility.autopilot'],
        expiresAt: new DateTimeImmutable('2026-06-03T00:00:00+00:00'),
    ),
);

assert_licensing_fail_closed_true(!$expiredByDate->isAllowed(), 'Past expiresAt must fail closed.');
assert_licensing_fail_closed_true($expiredByDate->reasonCode() === 'entitlement_expired', 'Past expiresAt must expose entitlement_expired.');

$notEntitled = $runtime->decide(
    $paidCapability,
    new StaticEntitlementSnapshot(
        status: EntitlementStatus::Active,
        keyId: 'key-1',
        signature: 'signature-fixture',
        capabilityKeys: ['different.capability'],
    ),
);

assert_licensing_fail_closed_true(!$notEntitled->isAllowed(), 'Snapshot without capability key must fail closed.');
assert_licensing_fail_closed_true($notEntitled->reasonCode() === 'capability_not_entitled', 'Missing capability key must expose safe reason.');

$invalidCapabilityFailed = false;

try {
    new StaticCapability('', 'larena/visibility');
} catch (InvalidArgumentException) {
    $invalidCapabilityFailed = true;
}

assert_licensing_fail_closed_true($invalidCapabilityFailed, 'Empty capability key must fail closed.');

echo "LicensingCapabilityDecisionFailsClosedTest passed.\n";
