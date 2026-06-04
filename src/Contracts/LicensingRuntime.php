<?php

declare(strict_types=1);

namespace Larena\Licensing\Contracts;

interface LicensingRuntime
{
    public function decide(Capability $capability, ?EntitlementSnapshot $snapshot = null): LicenseDecision;
}
