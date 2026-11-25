<?php

declare(strict_types=1);

namespace Larena\Licensing\Site;

use DateTimeImmutable;
use Larena\Licensing\Contracts\EntitlementSnapshot;
use Larena\Licensing\Enums\EntitlementStatus;

/**
 * A `larena.entitlement` snapshot whose signature verified over its exact bytes. The fields are the registration
 * server's (schema_version 1) plus the site fields it may add: `site_hosts`, `updates_until`, `fresh_until`.
 */
final readonly class VerifiedSnapshot implements EntitlementSnapshot
{
    /** A snapshot counts as fresh this long after it was issued, unless it says otherwise. */
    public const int DEFAULT_LIFETIME_DAYS = 30;

    /** No snapshot is fresh longer than this, whatever it says (a file for a closed network). */
    public const int MAX_LIFETIME_DAYS = 365;

    /**
     * @param list<string>                     $capabilities
     * @param list<string>|null                $siteHosts     null: the snapshot names no address
     * @param array<string,DateTimeImmutable>  $updatesUntil  package (or `*`) => last release date covered
     */
    public function __construct(
        public string $keyId,
        public string $snapshotKey,
        public string $site,
        public string $product,
        public string $licenceStatus,
        public array $capabilities,
        public DateTimeImmutable $issuedAt,
        public DateTimeImmutable $validUntil,
        public ?DateTimeImmutable $declaredFreshUntil,
        public ?array $siteHosts,
        public array $updatesUntil,
        private string $signatureEnvelope,
    ) {
    }

    /** When the snapshot stops being fresh: its `fresh_until`, else 30 days after issue; never past a year. */
    public function freshUntil(): DateTimeImmutable
    {
        $cap = $this->issuedAt->modify('+' . self::MAX_LIFETIME_DAYS . ' days');
        $fresh = $this->declaredFreshUntil ?? $this->issuedAt->modify('+' . self::DEFAULT_LIFETIME_DAYS . ' days');

        return $fresh > $cap ? $cap : $fresh;
    }

    /** The last release date covered for a package: its own entry, else `*`, else none. */
    public function updatesUntil(string $package): ?DateTimeImmutable
    {
        return $this->updatesUntil[$package] ?? $this->updatesUntil['*'] ?? null;
    }

    public function status(): EntitlementStatus
    {
        return match ($this->licenceStatus) {
            'active' => EntitlementStatus::Active,
            'trial' => EntitlementStatus::Trial,
            'grace' => EntitlementStatus::Grace,
            'expired' => EntitlementStatus::Expired,
            'revoked', 'suspended' => EntitlementStatus::Revoked,
            default => EntitlementStatus::Missing,
        };
    }

    public function keyId(): string
    {
        return $this->keyId;
    }

    public function signature(): string
    {
        return $this->signatureEnvelope;
    }

    public function capabilityKeys(): array
    {
        return $this->capabilities;
    }

    public function expiresAt(): DateTimeImmutable
    {
        return $this->freshUntil();
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['snapshot_key' => $this->snapshotKey, 'status' => $this->licenceStatus, 'issued_at' => $this->issuedAt->format(DATE_ATOM)];
    }
}
