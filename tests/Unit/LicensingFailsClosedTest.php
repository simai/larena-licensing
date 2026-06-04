<?php

declare(strict_types=1);

use Larena\Licensing\Enums\EntitlementStatus;
use Larena\Licensing\Enums\LicenseDecisionStatus;

require_once __DIR__ . '/../../vendor/autoload.php';

if (LicenseDecisionStatus::Unknown->permitsExecution()) {
    fwrite(STDERR, "Unknown license decision must fail closed.\n");
    exit(1);
}

if (EntitlementStatus::Revoked->canUnlockPaidCapabilities()) {
    fwrite(STDERR, "Revoked entitlement must not unlock paid capabilities.\n");
    exit(1);
}

echo "LicensingFailsClosedTest passed.\n";
