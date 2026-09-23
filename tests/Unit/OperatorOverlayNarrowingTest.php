<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/scope-entitlement-fixtures.php';

use Larena\Licensing\Runtime\OperatorOverlayEntitlementResolver;

/**
 * The overlay is a ceiling, never a floor.
 *
 * An organization that could add a capability the operator does not hold would be
 * selling itself a licence, so every test here is about the direction of the
 * relationship rather than its arithmetic.
 */

// The operator holds one capability; the organization claims two.
$broadening = new OperatorOverlayEntitlementResolver(
    static fn (): mixed => licensing_snapshot(['distributed.remote_operations'], null, true, 3),
    static fn (): mixed => licensing_snapshot(
        ['distributed.remote_operations', 'distributed.cluster'],
        'org:acme',
        false,
        10,
    ),
);

$allowed = $broadening->resolve(licensing_paid_capability('distributed.remote_operations'), 'org:acme');
licensing_assert($allowed->allowed, 'what both hold is allowed');

$refused = $broadening->resolve(licensing_paid_capability('distributed.cluster'), 'org:acme');
licensing_assert(!$refused->allowed, 'what only the organization claims is refused');
licensing_assert(
    $refused->reasonCode === 'capability_not_in_operator_overlay',
    'and the reason names the overlay, not the scope: ' . $refused->reasonCode,
);
licensing_assert($refused->operatorOverlayPresent === true);
licensing_assert(str_contains((string) $refused->remediation, 'operator'), 'the remediation points at the operator');

// The node limit narrows to the minimum, whichever side is lower.
licensing_assert($broadening->nodeLimit('org:acme') === 3, 'the overlay caps the organization');

$lowerScope = new OperatorOverlayEntitlementResolver(
    static fn (): mixed => licensing_snapshot(['distributed.cluster'], null, true, 10),
    static fn (): mixed => licensing_snapshot(['distributed.cluster'], 'org:acme', false, 2),
);
licensing_assert($lowerScope->nodeLimit('org:acme') === 2, 'the organization may narrow further');

// A scope may take capabilities away, which is the legitimate direction.
$narrowing = new OperatorOverlayEntitlementResolver(
    static fn (): mixed => licensing_snapshot(['distributed.remote_operations', 'distributed.cluster'], null, true, 5),
    static fn (): mixed => licensing_snapshot(['distributed.remote_operations'], 'org:acme', false, 5),
);
licensing_assert($narrowing->resolve(licensing_paid_capability('distributed.remote_operations'), 'org:acme')->allowed);
$narrowed = $narrowing->resolve(licensing_paid_capability('distributed.cluster'), 'org:acme');
licensing_assert(!$narrowed->allowed, 'a scope may withhold what the operator holds');
licensing_assert($narrowed->reasonCode === 'capability_not_entitled', 'and the reason is the scope, not the overlay');

// Different scopes narrow independently: one organization's limit does not bind
// another's.
$perScope = new OperatorOverlayEntitlementResolver(
    static fn (): mixed => licensing_snapshot(['distributed.cluster'], null, true, 9),
    static fn (?string $scope): mixed => match ($scope) {
        'org:acme' => licensing_snapshot(['distributed.cluster'], 'org:acme', false, 2),
        'org:other' => licensing_snapshot(['distributed.cluster'], 'org:other', false, 7),
        default => null,
    },
);
licensing_assert($perScope->nodeLimit('org:acme') === 2);
licensing_assert($perScope->nodeLimit('org:other') === 7);
licensing_assert($perScope->nodeLimit('org:unknown') === 9, 'a scope with no snapshot of its own gets the overlay');

// With no overlay at all, the scope snapshot is the whole truth — a single-site
// installation is not required to invent an operator level.
$siteOnly = new OperatorOverlayEntitlementResolver(
    null,
    static fn (): mixed => licensing_snapshot(['distributed.cluster'], 'site:main', false, 4),
);
licensing_assert($siteOnly->resolve(licensing_paid_capability('distributed.cluster'), 'site:main')->allowed);
licensing_assert($siteOnly->nodeLimit('site:main') === 4);

// An overlay with no scope snapshot applies to every scope.
$overlayOnly = new OperatorOverlayEntitlementResolver(
    static fn (): mixed => licensing_snapshot(['distributed.remote_operations'], null, true, 3),
    null,
);
licensing_assert($overlayOnly->resolve(licensing_paid_capability(), 'org:anything')->allowed);
licensing_assert($overlayOnly->nodeLimit('org:anything') === 3);

// An installation that declares nothing about nodes gets one node, and so does a
// snapshot that declares no limit. Both are asserted against the same resolver
// behaviour rather than against a constant, because what matters is that an
// undeclared limit is not an unlimited one.
licensing_assert(
    (new OperatorOverlayEntitlementResolver())->nodeLimit() === OperatorOverlayEntitlementResolver::DEFAULT_NODE_LIMIT,
    'an installation that declares nothing gets the default limit',
);

$undeclaredLimit = new OperatorOverlayEntitlementResolver(
    null,
    static fn (): mixed => licensing_snapshot(['distributed.cluster'], 'site:main', false, null),
);
licensing_assert(
    $undeclaredLimit->nodeLimit('site:main') < 2,
    'a snapshot with no declared limit is one node, not unlimited',
);

echo "Operator overlay narrowing passed.\n";
