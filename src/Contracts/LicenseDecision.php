<?php

declare(strict_types=1);

namespace Larena\Licensing\Contracts;

use Larena\Licensing\Enums\LicenseDecisionStatus;

interface LicenseDecision
{
    public function status(): LicenseDecisionStatus;

    public function allowed(): bool;

    public function capabilityId(): string;

    public function reason(): string;

    public function adminExplanation(): string;
}
