<?php

declare(strict_types=1);

namespace Larena\Licensing\Contracts;

use Larena\Licensing\Enums\LicenseDecisionStatus;

interface LicenseDecision
{
    public function status(): LicenseDecisionStatus;

    public function capabilityKey(): string;

    public function reasonCode(): string;

    public function isAllowed(): bool;
}
