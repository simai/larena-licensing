<?php

declare(strict_types=1);

namespace Larena\Licensing\Runtime;

use InvalidArgumentException;
use Larena\Licensing\Contracts\LicenseDecision;
use Larena\Licensing\Enums\LicenseDecisionStatus;

final readonly class RuntimeLicenseDecision implements LicenseDecision
{
    private function __construct(
        private LicenseDecisionStatus $status,
        private string $capabilityKey,
        private string $reasonCode,
    ) {
        foreach ([
            'capabilityKey' => $this->capabilityKey,
            'reasonCode' => $this->reasonCode,
        ] as $field => $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException("License decision {$field} must not be empty.");
            }
        }
    }

    public static function allowed(string $capabilityKey, string $reasonCode): self
    {
        return new self(LicenseDecisionStatus::Allowed, $capabilityKey, $reasonCode);
    }

    public static function limited(string $capabilityKey, string $reasonCode): self
    {
        return new self(LicenseDecisionStatus::Limited, $capabilityKey, $reasonCode);
    }

    public static function locked(string $capabilityKey, string $reasonCode): self
    {
        return new self(LicenseDecisionStatus::Locked, $capabilityKey, $reasonCode);
    }

    public static function denied(string $capabilityKey, string $reasonCode): self
    {
        return new self(LicenseDecisionStatus::Denied, $capabilityKey, $reasonCode);
    }

    public function status(): LicenseDecisionStatus
    {
        return $this->status;
    }

    public function capabilityKey(): string
    {
        return $this->capabilityKey;
    }

    public function reasonCode(): string
    {
        return $this->reasonCode;
    }

    public function isAllowed(): bool
    {
        return $this->status->permitsExecution();
    }
}
