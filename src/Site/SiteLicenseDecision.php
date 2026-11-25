<?php

declare(strict_types=1);

namespace Larena\Licensing\Site;

/** One answer of {@see SiteLicensing}: allowed or not, why, and the state behind it. */
final readonly class SiteLicenseDecision
{
    public function __construct(
        public string $subject,
        public bool $allowed,
        public string $reasonCode,
        public LicensingState $state,
    ) {
    }

    /** @return array{subject: string, decision: string, reason_code: string, state: string} */
    public function toArray(): array
    {
        return [
            'subject' => $this->subject,
            'decision' => $this->allowed ? 'allowed' : 'denied',
            'reason_code' => $this->reasonCode,
            'state' => $this->state->value,
        ];
    }
}
