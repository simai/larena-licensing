<?php

declare(strict_types=1);

namespace Larena\Licensing\Contracts;

/**
 * An entitlement snapshot that knows which scope it applies to.
 *
 * It extends the existing snapshot rather than replacing it, so every caller that
 * only needs the old contract keeps working. A site-level snapshot stays valid for
 * a single-site installation with no organization — that installation is the
 * common case, and requiring an operator level would tax it for nothing.
 */
interface ScopedEntitlementSnapshot extends EntitlementSnapshot
{
    /**
     * The ScopeRef this snapshot applies to, or null for the installation as a
     * whole.
     */
    public function scopeRef(): ?string;

    /**
     * The number of nodes this entitlement admits. Null means one.
     */
    public function nodeLimit(): ?int;

    /**
     * Whether this is the operator overlay, which is the ceiling for every scope.
     */
    public function isOperatorLevel(): bool;

    /**
     * The environment a node must present to be admitted, or null when the
     * snapshot is not bound to one.
     */
    public function environmentFingerprint(): ?string;
}
