<?php

declare(strict_types=1);

namespace Larena\Licensing\Contracts;

/**
 * What an installation may do with one capability in one scope, and why.
 *
 * The explain payload names the scope, whether an operator overlay was in play,
 * the effective tier and what to do about a refusal. It never carries a key id, a
 * signature or any other signing material: someone debugging an entitlement should
 * not end up holding the means to forge one.
 */
final readonly class ScopeEntitlementDecision
{
    public function __construct(
        public string $capabilityKey,
        public ?string $scopeRef,
        public bool $allowed,
        public string $reasonCode,
        public bool $operatorOverlayPresent = false,
        public ?string $effectiveTier = null,
        public ?int $nodeLimit = null,
        public ?string $remediation = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'capability_key' => $this->capabilityKey,
            'scope_ref' => $this->scopeRef,
            'decision' => $this->allowed ? 'allowed' : 'locked',
            'reason_code' => $this->reasonCode,
            'operator_overlay_present' => $this->operatorOverlayPresent,
            'effective_tier' => $this->effectiveTier,
            'node_limit' => $this->nodeLimit,
            'remediation' => $this->remediation,
        ];
    }
}
