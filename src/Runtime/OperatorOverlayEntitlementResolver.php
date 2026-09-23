<?php

declare(strict_types=1);

namespace Larena\Licensing\Runtime;

use Larena\Licensing\Contracts\Capability;
use Larena\Licensing\Contracts\ScopedEntitlementSnapshot;
use Larena\Licensing\Contracts\ScopeEntitlementDecision;
use Larena\Licensing\Contracts\ScopeEntitlementResolver;
use Larena\Licensing\Enums\EntitlementStatus;

/**
 * Resolves a capability in a scope against an operator overlay.
 *
 * The overlay is a ceiling, never a floor. A scope snapshot can take capabilities
 * away and lower a node limit; it cannot add a capability the operator does not
 * hold, because then an organization could sell itself a licence.
 *
 * The free path is the other half of the design. A capability declared free by
 * default is allowed without reading any snapshot at all — not "read the snapshot
 * and notice it is free", but never read it. An offline installation, or one whose
 * licensing service is down, keeps every behaviour it was promised for free.
 */
final class OperatorOverlayEntitlementResolver implements ScopeEntitlementResolver
{
    public const DEFAULT_NODE_LIMIT = 1;

    /**
     * The two sources are typed `mixed` rather than as a snapshot-returning
     * callable on purpose: they come from outside this package and may return
     * anything, so the resolver checks what it gets instead of trusting a type it
     * cannot enforce at the boundary.
     *
     * @param callable(): mixed|null $operatorOverlay
     * @param callable(?string): mixed|null $scopeSnapshot
     */
    public function __construct(
        private readonly mixed $operatorOverlay = null,
        private readonly mixed $scopeSnapshot = null,
        private readonly ?\DateTimeImmutable $now = null,
    ) {
    }

    public function resolve(Capability $capability, ?string $scopeRef = null): ScopeEntitlementDecision
    {
        // Free first, and without touching a snapshot. The order is the point.
        if ($capability->isFreeByDefault()) {
            return new ScopeEntitlementDecision(
                capabilityKey: $capability->key(),
                scopeRef: $scopeRef,
                allowed: true,
                reasonCode: 'capability_free_by_default',
                effectiveTier: 'free',
            );
        }

        $overlay = $this->overlay();
        $scoped = $this->scoped($scopeRef);

        if ($overlay === null && $scoped === null) {
            return $this->locked($capability, $scopeRef, 'snapshot_missing', false, 'obtain an entitlement for this scope');
        }

        foreach ([$overlay, $scoped] as $snapshot) {
            if ($snapshot === null) {
                continue;
            }

            if (!$snapshot->status()->canUnlockPaidCapabilities()) {
                return $this->locked(
                    $capability,
                    $scopeRef,
                    'entitlement_inactive',
                    $overlay !== null,
                    'renew the entitlement',
                );
            }

            if ($this->isExpired($snapshot)) {
                return $this->locked($capability, $scopeRef, 'entitlement_inactive', $overlay !== null, 'renew the entitlement');
            }
        }

        // The overlay is the ceiling: a capability it lacks is absent everywhere,
        // whatever a scope snapshot claims.
        if ($overlay !== null && !in_array($capability->key(), $overlay->capabilityKeys(), true)) {
            return $this->locked(
                $capability,
                $scopeRef,
                'capability_not_in_operator_overlay',
                true,
                'add the capability to the operator entitlement',
            );
        }

        $effective = $this->effectiveCapabilities($overlay, $scoped);

        if (!in_array($capability->key(), $effective, true)) {
            return $this->locked(
                $capability,
                $scopeRef,
                'capability_not_entitled',
                $overlay !== null,
                'add the capability to the entitlement for this scope',
            );
        }

        return new ScopeEntitlementDecision(
            capabilityKey: $capability->key(),
            scopeRef: $scopeRef,
            allowed: true,
            reasonCode: 'entitlement_active',
            operatorOverlayPresent: $overlay !== null,
            effectiveTier: 'entitled',
            nodeLimit: $this->nodeLimit($scopeRef),
        );
    }

    public function verifyOperator(): ScopeEntitlementDecision
    {
        $overlay = $this->overlay();

        if ($overlay === null) {
            // Not an error: a single-site installation has no operator level, and
            // saying so is different from failing.
            return new ScopeEntitlementDecision(
                capabilityKey: 'operator',
                scopeRef: null,
                allowed: true,
                reasonCode: 'no_operator_overlay',
                remediation: null,
            );
        }

        if (!$overlay->status()->canUnlockPaidCapabilities() || $this->isExpired($overlay)) {
            return new ScopeEntitlementDecision(
                capabilityKey: 'operator',
                scopeRef: $overlay->scopeRef(),
                allowed: false,
                reasonCode: 'entitlement_inactive',
                operatorOverlayPresent: true,
                remediation: 'renew the operator entitlement',
            );
        }

        return new ScopeEntitlementDecision(
            capabilityKey: 'operator',
            scopeRef: $overlay->scopeRef(),
            allowed: true,
            reasonCode: 'operator_overlay_active',
            operatorOverlayPresent: true,
            nodeLimit: $overlay->nodeLimit() ?? self::DEFAULT_NODE_LIMIT,
        );
    }

    public function nodeLimit(?string $scopeRef = null): int
    {
        $limits = [];

        $overlay = $this->overlay();
        if ($overlay !== null) {
            $limits[] = $overlay->nodeLimit() ?? self::DEFAULT_NODE_LIMIT;
        }

        $scoped = $this->scoped($scopeRef);
        if ($scoped !== null) {
            $limits[] = $scoped->nodeLimit() ?? self::DEFAULT_NODE_LIMIT;
        }

        // The minimum, because narrowing is the only direction a scope may move.
        return $limits === [] ? self::DEFAULT_NODE_LIMIT : min($limits);
    }

    /**
     * @return array<string, mixed>
     */
    public function explain(Capability $capability, ?string $scopeRef = null): array
    {
        return $this->resolve($capability, $scopeRef)->toArray();
    }

    /**
     * The intersection of the overlay and the scope snapshot.
     *
     * @return list<string>
     */
    private function effectiveCapabilities(?ScopedEntitlementSnapshot $overlay, ?ScopedEntitlementSnapshot $scoped): array
    {
        if ($overlay === null) {
            // A single-site installation stands on its own snapshot.
            return $scoped === null ? [] : $scoped->capabilityKeys();
        }

        if ($scoped === null) {
            return $overlay->capabilityKeys();
        }

        return array_values(array_intersect($overlay->capabilityKeys(), $scoped->capabilityKeys()));
    }

    private function locked(
        Capability $capability,
        ?string $scopeRef,
        string $reasonCode,
        bool $overlayPresent,
        ?string $remediation,
    ): ScopeEntitlementDecision {
        return new ScopeEntitlementDecision(
            capabilityKey: $capability->key(),
            scopeRef: $scopeRef,
            allowed: false,
            reasonCode: $reasonCode,
            operatorOverlayPresent: $overlayPresent,
            effectiveTier: null,
            nodeLimit: null,
            remediation: $remediation,
        );
    }

    private function overlay(): ?ScopedEntitlementSnapshot
    {
        if (!is_callable($this->operatorOverlay)) {
            return null;
        }

        $snapshot = ($this->operatorOverlay)();

        return $snapshot instanceof ScopedEntitlementSnapshot && $snapshot->isOperatorLevel() ? $snapshot : null;
    }

    private function scoped(?string $scopeRef): ?ScopedEntitlementSnapshot
    {
        if (!is_callable($this->scopeSnapshot)) {
            return null;
        }

        $snapshot = ($this->scopeSnapshot)($scopeRef);

        return $snapshot instanceof ScopedEntitlementSnapshot ? $snapshot : null;
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
