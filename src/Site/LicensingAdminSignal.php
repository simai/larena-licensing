<?php

declare(strict_types=1);

namespace Larena\Licensing\Site;

/**
 * What the admin's alert bar says about the licence, without the admin package: an id, a level (`warning`, `info`),
 * the words' key in `larena-licensing::licensing.alerts.*` and their values. Nothing when the licence is fine, and
 * nothing for a site that never asked for a licence (no site key, no snapshot). The public site never shows any of it.
 */
final readonly class LicensingAdminSignal
{
    /** @param array<string,string> $replace */
    public function __construct(
        public string $id,
        public string $level,
        public string $textKey,
        public array $replace = [],
    ) {
    }

    public static function for(SiteLicenseStatus $status, bool $hasSiteKey): ?self
    {
        $date = $status->issuedAt()?->format('d.m.Y') ?? '';

        return match ($status->state) {
            LicensingState::Active => null,
            LicensingState::Development => new self('licensing:development', 'info', 'development'),
            LicensingState::Stale => $status->reasonCode === 'clock_set_back'
                ? new self('licensing:clock', 'warning', 'clock_set_back', ['date' => $date])
                : new self('licensing:stale', 'warning', 'stale', ['date' => $date]),
            LicensingState::Expired => $status->reasonCode === 'licence_expired'
                ? new self('licensing:expired', 'warning', 'expired')
                : new self('licensing:revoked', 'warning', 'revoked'),
            LicensingState::Missing => $status->reasonCode === 'snapshot_missing' && $hasSiteKey
                ? new self('licensing:missing', 'warning', 'missing')
                : null,
            LicensingState::Invalid => new self('licensing:invalid', 'warning', 'invalid', ['reason' => $status->reasonCode]),
        };
    }
}
