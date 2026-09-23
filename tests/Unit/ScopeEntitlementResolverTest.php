<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/scope-entitlement-fixtures.php';

use Larena\Licensing\Enums\EntitlementStatus;
use Larena\Licensing\Runtime\OperatorOverlayEntitlementResolver;

// The free path never reads a snapshot. The source below throws if consulted, so
// this is proof rather than observation.
$free = new OperatorOverlayEntitlementResolver(
    licensing_forbidden_snapshot(),
    licensing_forbidden_snapshot(),
);

$decision = $free->resolve(licensing_free_capability());
licensing_assert($decision->allowed, 'a free capability is allowed');
licensing_assert($decision->reasonCode === 'capability_free_by_default');
licensing_assert($decision->effectiveTier === 'free');
licensing_assert($free->resolve(licensing_free_capability(), 'site:main')->allowed, 'in any scope');

// A single-site installation with no operator level stands on its own snapshot.
$siteOnly = new OperatorOverlayEntitlementResolver(
    null,
    static fn (?string $scope): mixed => licensing_snapshot(['distributed.remote_operations'], 'site:main'),
);
$resolved = $siteOnly->resolve(licensing_paid_capability(), 'site:main');
licensing_assert($resolved->allowed, 'a site snapshot needs no operator level');
licensing_assert($resolved->operatorOverlayPresent === false);
licensing_assert($resolved->reasonCode === 'entitlement_active');

// With neither snapshot, a paid capability is locked and says what to do.
$nothing = new OperatorOverlayEntitlementResolver();
$locked = $nothing->resolve(licensing_paid_capability(), 'site:main');
licensing_assert(!$locked->allowed);
licensing_assert($locked->reasonCode === 'snapshot_missing');
licensing_assert($locked->remediation !== null, 'a refusal says what to do about it');

// An inactive or expired entitlement is locked whichever side it is on.
foreach ([EntitlementStatus::Expired, EntitlementStatus::Revoked, EntitlementStatus::Tampered] as $status) {
    $inactive = new OperatorOverlayEntitlementResolver(
        null,
        static fn (): mixed => licensing_snapshot(['distributed.remote_operations'], 'site:main', false, null, $status),
    );
    licensing_assert(
        $inactive->resolve(licensing_paid_capability(), 'site:main')->reasonCode === 'entitlement_inactive',
        $status->value . ' is inactive',
    );
}

$expired = new OperatorOverlayEntitlementResolver(
    null,
    static fn (): mixed => licensing_snapshot(
        ['distributed.remote_operations'],
        'site:main',
        false,
        null,
        EntitlementStatus::Active,
        null,
        new DateTimeImmutable('2020-01-01'),
    ),
    new DateTimeImmutable('2026-09-23'),
);
licensing_assert($expired->resolve(licensing_paid_capability(), 'site:main')->reasonCode === 'entitlement_inactive');

// verify_operator distinguishes "no operator level" from "operator level broken".
licensing_assert($nothing->verifyOperator()->reasonCode === 'no_operator_overlay');
licensing_assert($nothing->verifyOperator()->allowed, 'having no operator level is not a failure');

$withOverlay = new OperatorOverlayEntitlementResolver(
    static fn (): mixed => licensing_snapshot(['distributed.remote_operations'], null, true, 5),
    null,
);
$operator = $withOverlay->verifyOperator();
licensing_assert($operator->allowed && $operator->reasonCode === 'operator_overlay_active');
licensing_assert($operator->nodeLimit === 5);

// A snapshot that claims to be the overlay but is not operator level is not one.
$notAnOverlay = new OperatorOverlayEntitlementResolver(
    static fn (): mixed => licensing_snapshot(['distributed.remote_operations'], 'org:acme', false),
    null,
);
licensing_assert($notAnOverlay->verifyOperator()->reasonCode === 'no_operator_overlay');

// The explain payload carries no signing material. A capability key and a
// remediation are safe; a key id or a signature would hand a debugger the means to
// forge an entitlement.
$explained = $siteOnly->explain(licensing_paid_capability(), 'site:main');
$rendered = json_encode($explained);
licensing_assert(is_string($rendered));
foreach (['key-1', 'signature-1', 'key_id', 'signature'] as $forbidden) {
    licensing_assert(!str_contains($rendered, $forbidden), 'explain must not carry ' . $forbidden);
}
licensing_assert(array_key_exists('scope_ref', $explained));
licensing_assert(array_key_exists('operator_overlay_present', $explained));
licensing_assert(array_key_exists('remediation', $explained));

echo "Scope entitlement resolution passed.\n";
