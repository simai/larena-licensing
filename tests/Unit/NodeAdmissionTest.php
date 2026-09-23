<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/scope-entitlement-fixtures.php';

use Larena\Licensing\Enums\EntitlementStatus;
use Larena\Licensing\Runtime\SnapshotNodeAdmission;

$admission = new SnapshotNodeAdmission();
$fingerprint = 'env-fingerprint-abc';

$cluster = static fn (?int $limit = 3, ?string $env = 'env-fingerprint-abc', EntitlementStatus $status = EntitlementStatus::Active): mixed
    => licensing_snapshot(['distributed.cluster'], 'org:acme', false, $limit, $status, $env);

// The happy path: a verified snapshot, the right environment, room to spare.
$admitted = $admission->admit('node-2', $fingerprint, $cluster(), 1);
licensing_assert($admitted->isAdmitted(), 'a valid node is admitted');
licensing_assert($admitted->reasonCode === 'admitted');
licensing_assert($admitted->nodeLimit === null, 'an admission names no limit');

// No snapshot means refused. Not "assume a single node": a node asking to be
// admitted is by definition not the single node.
$noSnapshot = $admission->admit('node-2', $fingerprint, null, 1);
licensing_assert(!$noSnapshot->isAdmitted());
licensing_assert($noSnapshot->reasonCode === 'snapshot_missing');

// An inactive entitlement cannot admit.
foreach ([EntitlementStatus::Expired, EntitlementStatus::Revoked, EntitlementStatus::InvalidSignature] as $status) {
    $refused = $admission->admit('node-2', $fingerprint, $cluster(3, $fingerprint, $status), 1);
    licensing_assert($refused->reasonCode === 'entitlement_inactive', $status->value . ' cannot admit');
}

$expired = $admission->admit(
    'node-2',
    $fingerprint,
    licensing_snapshot(
        ['distributed.cluster'],
        'org:acme',
        false,
        3,
        EntitlementStatus::Active,
        $fingerprint,
        new DateTimeImmutable('2020-01-01'),
    ),
    1,
);
licensing_assert($expired->reasonCode === 'entitlement_inactive', 'an expired snapshot cannot admit');

// A snapshot without the cluster capability cannot admit, even when everything
// else is right. Remote operations alone buys crossing a boundary, not a cluster.
$remoteOnly = licensing_snapshot(['distributed.remote_operations'], 'org:acme', false, 3, EntitlementStatus::Active, $fingerprint);
licensing_assert($admission->admit('node-2', $fingerprint, $remoteOnly, 1)->reasonCode === 'cluster_capability_missing');

// A snapshot issued for another environment cannot admit a node here: otherwise
// one entitlement would licence every copy of it.
licensing_assert(
    $admission->admit('node-2', 'a-different-environment', $cluster(), 1)->reasonCode === 'environment_mismatch',
);
licensing_assert(
    $admission->admit('node-2', $fingerprint, $cluster(3, null), 1)->reasonCode === 'environment_mismatch',
    'an unbound snapshot cannot admit either',
);

// An empty node id or fingerprint is refused before anything else is considered.
licensing_assert($admission->admit('', $fingerprint, $cluster(), 1)->reasonCode === 'environment_mismatch');
licensing_assert($admission->admit('node-2', '', $cluster(), 1)->reasonCode === 'environment_mismatch');

// The node limit is enforced, and it is the one refusal that names a number,
// because the operator needs it to act.
$atLimit = $admission->admit('node-4', $fingerprint, $cluster(3), 3);
licensing_assert(!$atLimit->isAdmitted());
licensing_assert($atLimit->reasonCode === 'node_limit_reached');
licensing_assert($atLimit->nodeLimit === 3, 'the limit is named because it is the reason');

$overLimit = $admission->admit('node-9', $fingerprint, $cluster(3), 7);
licensing_assert($overLimit->reasonCode === 'node_limit_reached');

// Every other refusal stays silent about the cluster's size.
foreach ([
    $noSnapshot,
    $admission->admit('node-2', $fingerprint, $remoteOnly, 2),
    $admission->admit('node-2', 'elsewhere', $cluster(), 2),
] as $refusal) {
    licensing_assert($refusal->nodeLimit === null, 'a refusal must not leak the cluster size');
    $rendered = json_encode($refusal->toArray());
    licensing_assert(is_string($rendered) && !str_contains($rendered, 'node_limit'));
}

// A snapshot with no declared limit admits one node, so the second is refused.
$oneNode = licensing_snapshot(['distributed.cluster'], 'org:acme', false, null, EntitlementStatus::Active, $fingerprint);
licensing_assert($admission->admit('node-2', $fingerprint, $oneNode, 1)->reasonCode === 'node_limit_reached');
licensing_assert($admission->admit('node-1', $fingerprint, $oneNode, 0)->isAdmitted(), 'the first node fits');

echo "Node admission passed.\n";
