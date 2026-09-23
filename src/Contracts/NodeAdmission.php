<?php

declare(strict_types=1);

namespace Larena\Licensing\Contracts;

interface NodeAdmission
{
    /**
     * Whether a node may join this installation.
     *
     * The first node of a single-node installation never asks: admission is a
     * cluster concern, and a single node is not a cluster.
     */
    public function admit(
        string $nodeId,
        string $environmentFingerprint,
        ?ScopedEntitlementSnapshot $snapshot,
        int $admittedNodeCount,
    ): NodeAdmissionDecision;
}
