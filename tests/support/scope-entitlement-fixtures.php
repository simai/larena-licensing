<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

use Larena\Licensing\Contracts\Capability;
use Larena\Licensing\Contracts\ScopedEntitlementSnapshot;
use Larena\Licensing\Enums\EntitlementStatus;
use Larena\Licensing\Runtime\StaticCapability;
use Larena\Licensing\Runtime\StaticScopedEntitlementSnapshot;

function licensing_assert(bool $condition, string $message = 'licensing assertion failed'): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function licensing_paid_capability(string $key = 'distributed.remote_operations'): Capability
{
    return new StaticCapability($key, 'larena/core', false, true);
}

function licensing_free_capability(string $key = 'core.base'): Capability
{
    return new StaticCapability($key, 'larena/core', true, false);
}

/**
 * @param list<string> $capabilities
 */
function licensing_snapshot(
    array $capabilities,
    ?string $scopeRef = null,
    bool $operatorLevel = false,
    ?int $nodeLimit = null,
    EntitlementStatus $status = EntitlementStatus::Active,
    ?string $environmentFingerprint = null,
    ?DateTimeImmutable $expiresAt = null,
): ScopedEntitlementSnapshot {
    return new StaticScopedEntitlementSnapshot(
        $status,
        'key-1',
        'signature-1',
        $capabilities,
        $expiresAt,
        $scopeRef,
        $nodeLimit,
        $operatorLevel,
        $environmentFingerprint,
    );
}

/**
 * A snapshot source that fails the test if it is ever consulted.
 *
 * This is how "the free path never reads a snapshot" is proven: not by observing
 * that the answer is right, but by making the wrong behaviour impossible to miss.
 */
function licensing_forbidden_snapshot(): callable
{
    return static function (): ScopedEntitlementSnapshot {
        throw new RuntimeException('the free path must not read a snapshot');
    };
}
