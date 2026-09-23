<?php

declare(strict_types=1);

namespace Larena\Licensing\Runtime;

use Larena\Licensing\Contracts\NodeAdmission;
use Larena\Licensing\Contracts\NodeAdmissionDecision;
use Larena\Licensing\Contracts\ScopedEntitlementSnapshot;
use Larena\Licensing\Enums\DistributedCapability;

/**
 * Whether a node may join this installation.
 *
 * Every path here refuses by default. No snapshot means refused — not "assume a
 * single node", because a node asking to be admitted is by definition not the
 * single node. An inactive entitlement, a missing cluster capability, an
 * environment that does not match the one the snapshot was issued for, or one node
 * too many: each is its own reason code.
 *
 * A refusal never says how many nodes are already admitted. The one exception is
 * the limit itself, because that is the reason and the operator needs it to act.
 * Without that rule, probing admission would map the cluster.
 */
final class SnapshotNodeAdmission implements NodeAdmission
{
    public function __construct(private readonly ?\DateTimeImmutable $now = null)
    {
    }

    public function admit(
        string $nodeId,
        string $environmentFingerprint,
        ?ScopedEntitlementSnapshot $snapshot,
        int $admittedNodeCount,
    ): NodeAdmissionDecision {
        if (trim($nodeId) === '' || trim($environmentFingerprint) === '') {
            return NodeAdmissionDecision::refused('environment_mismatch');
        }

        if ($snapshot === null) {
            return NodeAdmissionDecision::refused('snapshot_missing');
        }

        if (!$snapshot->status()->canUnlockPaidCapabilities() || $this->isExpired($snapshot)) {
            return NodeAdmissionDecision::refused('entitlement_inactive');
        }

        if (!in_array(DistributedCapability::Cluster->value, $snapshot->capabilityKeys(), true)) {
            return NodeAdmissionDecision::refused('cluster_capability_missing');
        }

        $bound = $snapshot->environmentFingerprint();
        if ($bound === null || !hash_equals($bound, $environmentFingerprint)) {
            // A snapshot issued for one environment must not admit a node in
            // another: otherwise one entitlement would licence every copy of it.
            return NodeAdmissionDecision::refused('environment_mismatch');
        }

        $limit = $snapshot->nodeLimit() ?? OperatorOverlayEntitlementResolver::DEFAULT_NODE_LIMIT;

        if ($admittedNodeCount >= $limit) {
            return NodeAdmissionDecision::refused('node_limit_reached', $limit);
        }

        return NodeAdmissionDecision::admitted();
    }

    private function isExpired(ScopedEntitlementSnapshot $snapshot): bool
    {
        $expiresAt = $snapshot->expiresAt();

        if ($expiresAt === null) {
            return false;
        }

        return $expiresAt <= ($this->now ?? new \DateTimeImmutable());
    }
}
