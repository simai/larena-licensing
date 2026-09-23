<?php

declare(strict_types=1);

namespace Larena\Licensing\Contracts;

interface ScopeEntitlementResolver
{
    /**
     * Whether a capability is available in a scope.
     *
     * A capability declared free by default is allowed without reading a snapshot
     * at all: the free path must not depend on licensing infrastructure being
     * present, or an offline installation would lose behaviour it was promised.
     */
    public function resolve(Capability $capability, ?string $scopeRef = null): ScopeEntitlementDecision;

    /**
     * The operator overlay, if the installation has one.
     */
    public function verifyOperator(): ScopeEntitlementDecision;

    /**
     * The effective node limit for a scope: the minimum of the overlay and the
     * scope snapshot, or one when nothing says otherwise.
     */
    public function nodeLimit(?string $scopeRef = null): int;

    /**
     * @return array<string, mixed>
     */
    public function explain(Capability $capability, ?string $scopeRef = null): array;
}
