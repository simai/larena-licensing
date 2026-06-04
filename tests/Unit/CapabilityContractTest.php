<?php

declare(strict_types=1);

use Larena\Licensing\Contracts\Capability;
use Larena\Licensing\Enums\EntitlementStatus;
use Larena\Licensing\Enums\LicenseDecisionStatus;

require_once __DIR__ . '/../../vendor/autoload.php';

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$capability = new class implements Capability {
    public function key(): string
    {
        return 'visibility.autopilot';
    }

    public function package(): string
    {
        return 'larena/visibility';
    }

    public function isFreeByDefault(): bool
    {
        return false;
    }

    public function failClosedWhenUnknown(): bool
    {
        return true;
    }
};

assert_true($capability->failClosedWhenUnknown(), 'commercial capability should fail closed when unknown');
assert_true(EntitlementStatus::Active->canUnlockPaidCapabilities(), 'active entitlement can unlock paid capabilities');
assert_true(!EntitlementStatus::InvalidSignature->canUnlockPaidCapabilities(), 'invalid signature cannot unlock paid capabilities');
assert_true(LicenseDecisionStatus::Limited->permitsExecution(), 'limited decision may permit bounded execution');
assert_true(!LicenseDecisionStatus::Unknown->permitsExecution(), 'unknown decision must not permit execution');

echo "CapabilityContractTest passed.\n";
