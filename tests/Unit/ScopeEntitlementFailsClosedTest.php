<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/scope-entitlement-fixtures.php';

use Larena\Licensing\Enums\DistributedCapability;
use Larena\Licensing\Enums\EntitlementStatus;
use Larena\Licensing\Runtime\OperatorOverlayEntitlementResolver;
use Larena\Licensing\Runtime\SnapshotNodeAdmission;
use Larena\Licensing\Runtime\StaticScopedEntitlementSnapshot;

// Every paid path with nothing behind it is locked, and each says why.
$empty = new OperatorOverlayEntitlementResolver();
foreach ([DistributedCapability::RemoteOperations, DistributedCapability::Cluster] as $capability) {
    $decision = $empty->resolve(licensing_paid_capability($capability->value), 'site:main');
    licensing_assert(!$decision->allowed, $capability->value . ' is locked with no entitlement');
    licensing_assert($decision->reasonCode === 'snapshot_missing');
    licensing_assert($decision->effectiveTier === null, 'a locked decision claims no tier');
    licensing_assert($decision->nodeLimit === null);
}

// A snapshot source that returns something that is not a scoped snapshot is
// treated as absent rather than trusted. The resolver takes `mixed` callables for
// exactly this reason: a source outside this package can return anything, and the
// resolver checks rather than assumes.
$notASnapshot = static fn (): mixed => 'not a snapshot';
$wrongType = new OperatorOverlayEntitlementResolver($notASnapshot, static fn (): mixed => null);
licensing_assert($wrongType->resolve(licensing_paid_capability(), 'site:main')->reasonCode === 'snapshot_missing');
licensing_assert($wrongType->verifyOperator()->reasonCode === 'no_operator_overlay');

// A malformed snapshot cannot be constructed at all.
$rejected = 0;
foreach ([
    ['', 'signature'],
    ['key', ''],
] as [$keyId, $signature]) {
    try {
        new StaticScopedEntitlementSnapshot(EntitlementStatus::Active, $keyId, $signature);
    } catch (InvalidArgumentException) {
        ++$rejected;
    }
}
try {
    new StaticScopedEntitlementSnapshot(EntitlementStatus::Active, 'key', 'signature', [], null, null, 0);
} catch (InvalidArgumentException) {
    ++$rejected;
}
try {
    new StaticScopedEntitlementSnapshot(EntitlementStatus::Active, 'key', 'signature', ['  ']);
} catch (InvalidArgumentException) {
    ++$rejected;
}
licensing_assert($rejected === 4, 'every malformed snapshot is refused at construction');

// The free path stays free even when everything else is broken, which is the
// promise the open-source boundary makes: free behaviour does not depend on
// licensing infrastructure being reachable.
$broken = new OperatorOverlayEntitlementResolver(
    licensing_forbidden_snapshot(),
    licensing_forbidden_snapshot(),
);
licensing_assert($broken->resolve(licensing_free_capability('rest.single_node'))->allowed);
licensing_assert($broken->resolve(licensing_free_capability('mcp.projection'))->allowed);
licensing_assert($broken->resolve(licensing_free_capability('core.transport.local'))->allowed);

// Admission refuses every incomplete case, and never throws: a security decision
// that throws is a security decision somebody catches.
$admission = new SnapshotNodeAdmission();
foreach ([
    [null, 'snapshot_missing'],
    [licensing_snapshot([], 'org:acme', false, 3, EntitlementStatus::Active, 'env'), 'cluster_capability_missing'],
    [licensing_snapshot(['distributed.cluster'], 'org:acme', false, 3, EntitlementStatus::Missing, 'env'), 'entitlement_inactive'],
] as [$snapshot, $expected]) {
    $decision = $admission->admit('node-2', 'env', $snapshot, 1);
    licensing_assert(!$decision->isAdmitted());
    licensing_assert($decision->reasonCode === $expected, 'expected ' . $expected . ', got ' . $decision->reasonCode);
}

echo "Scope entitlement fail-closed boundaries passed.\n";
