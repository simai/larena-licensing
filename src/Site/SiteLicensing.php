<?php

declare(strict_types=1);

namespace Larena\Licensing\Site;

use Closure;
use DateTimeImmutable;
use DateTimeZone;

/**
 * The site's one answer to «may this run?» (owner decisions 2026-11-25): code asks for a right to a function,
 * `allows('telephony.ai_voice')`, never for a plan. Paid or free is catalog data: the signed snapshot lists what the
 * site has; the shipped catalog makes its `free` capabilities free for everyone, and the configuration may add more.
 *
 * - a test address ({@see DevelopmentAccess}) → allowed, state `development`;
 * - a capability the catalog makes free → allowed;
 * - a verified snapshot listing the capability → allowed: while fresh (`active`), past its lifetime or with the
 *   clock set back (`stale`), and after the licence ended (`expired`: installed code keeps working);
 * - anything else → denied with the reason: no snapshot, a snapshot that does not verify, a revoked licence, a
 *   capability not listed.
 *
 * Time only moves forward here: «now» is the latest of the clock, the latest time the site has seen and the
 * snapshot's own issue date, so setting the clock back gains nothing and is reported. The public site never asks
 * this for its looks: warnings belong to the admin only.
 */
final class SiteLicensing
{
    /** A long-running worker reads the kept snapshot again after this long. */
    private const int REREAD_SECONDS = 300;

    private ?SiteLicenseStatus $status = null;

    private int $evaluatedAt = 0;

    /**
     * @param list<string>                     $freeCapabilities catalog data: capabilities free for every site
     * @param (Closure(): DateTimeImmutable)|null $clock
     */
    public function __construct(
        private readonly SnapshotVerifier $verifier,
        private readonly SnapshotStore $store,
        private readonly DevelopmentAccess $development,
        private readonly string $siteHost,
        private readonly array $freeCapabilities = [],
        private readonly ?Closure $clock = null,
    ) {
    }

    public function allows(string $capability): bool
    {
        return $this->decide($capability)->allowed;
    }

    /** Whether the catalog (or the site's configuration) makes the capability free for every site. */
    public function isFree(string $capability): bool
    {
        return in_array($capability, $this->freeCapabilities, true);
    }

    public function decide(string $capability): SiteLicenseDecision
    {
        $status = $this->status();
        if ($status->state === LicensingState::Development) {
            return new SiteLicenseDecision($capability, true, $status->reasonCode, $status->state);
        }
        if (in_array($capability, $this->freeCapabilities, true)) {
            return new SiteLicenseDecision($capability, true, 'capability_free', $status->state);
        }
        if (!$status->capabilitiesCount() || $status->snapshot === null) {
            return new SiteLicenseDecision($capability, false, $status->reasonCode, $status->state);
        }
        if (!in_array($capability, $status->snapshot->capabilities, true)) {
            return new SiteLicenseDecision($capability, false, 'capability_not_entitled', $status->state);
        }

        return new SiteLicenseDecision($capability, true, $status->reasonCode, $status->state);
    }

    /**
     * Whether a release of a package published at `$releasedAt` may be installed: updates come only for releases
     * up to the snapshot's «updates until» date of that package (or `*`). A snapshot that names no date gives no
     * paid update.
     */
    public function allowsUpdate(string $package, DateTimeImmutable $releasedAt): SiteLicenseDecision
    {
        $status = $this->status();
        if ($status->state === LicensingState::Development) {
            return new SiteLicenseDecision($package, true, $status->reasonCode, $status->state);
        }
        if (!$status->capabilitiesCount() || $status->snapshot === null) {
            return new SiteLicenseDecision($package, false, $status->reasonCode, $status->state);
        }
        $until = $status->snapshot->updatesUntil($package);
        if ($until === null) {
            return new SiteLicenseDecision($package, false, 'updates_until_missing', $status->state);
        }
        if ($releasedAt > $until) {
            return new SiteLicenseDecision($package, false, 'updates_period_ended', $status->state);
        }

        return new SiteLicenseDecision($package, true, 'updates_within_period', $status->state);
    }

    public function updatesUntil(string $package): ?DateTimeImmutable
    {
        $status = $this->status();

        return $status->capabilitiesCount() ? $status->snapshot?->updatesUntil($package) : null;
    }

    /**
     * When the site's trial ends: the kept snapshot's `fresh_until` (Larena Server keeps it within the trial end) while
     * the licence status is `trial` and the snapshot counts. Null without a trial.
     */
    public function trialUntil(): ?DateTimeImmutable
    {
        $status = $this->status();
        if (!$status->capabilitiesCount() || $status->snapshot === null || $status->snapshot->licenceStatus !== 'trial') {
            return null;
        }

        return $status->snapshot->declaredFreshUntil ?? $status->snapshot->freshUntil();
    }

    public function status(): SiteLicenseStatus
    {
        if ($this->status === null || time() - $this->evaluatedAt > self::REREAD_SECONDS) {
            $this->status = $this->evaluate();
            $this->evaluatedAt = time();
        }

        return $this->status;
    }

    /**
     * Takes a snapshot from the server (`refresh`) or a file (`file`) after checking it: signature, key, format,
     * product, not issued in the future, this site's address when it names addresses (a file must name it), and
     * not older than the snapshot already kept. Returns it; the kept one stays when it is refused.
     */
    public function accept(string $envelopeJson, string $origin): VerifiedSnapshot
    {
        $now = $this->now();
        $envelope = SnapshotVerifier::envelope($envelopeJson);
        $snapshot = $this->verifier->verify($envelope, $now);
        $this->checkSite($snapshot, $origin);
        $kept = $this->keptVerified();
        if ($kept !== null && $snapshot->issuedAt < $kept->issuedAt) {
            throw new SnapshotRefused('licensing_snapshot_older_than_kept');
        }
        $this->store->keep($envelope, $origin, $now);
        $this->status = null;

        return $snapshot;
    }

    /** Notes the current time as seen, so a clock set back later is caught. */
    public function markSeen(): void
    {
        $this->store->seen($this->now());
    }

    private function evaluate(): SiteLicenseStatus
    {
        $development = $this->development->reason($this->siteHost);
        if ($development !== null) {
            return new SiteLicenseStatus(LicensingState::Development, $development);
        }
        try {
            $kept = $this->store->load();
            if ($kept === null) {
                return new SiteLicenseStatus(LicensingState::Missing, 'snapshot_missing');
            }
            $snapshot = $this->verifier->verify($kept->envelope);
            $this->checkSite($snapshot, $kept->origin);
        } catch (SnapshotRefused $refused) {
            return new SiteLicenseStatus(LicensingState::Invalid, $refused->reason());
        }

        $clock = $this->now();
        $latest = max($kept->seenAt, $snapshot->issuedAt);
        $clockSetBack = $clock->getTimestamp() + SnapshotVerifier::CLOCK_SKEW_SECONDS < $latest->getTimestamp();
        $now = $clock > $latest ? $clock : $latest;

        return match ($snapshot->licenceStatus) {
            'missing' => new SiteLicenseStatus(LicensingState::Missing, 'licence_missing', $snapshot, $clockSetBack),
            'revoked' => new SiteLicenseStatus(LicensingState::Expired, 'licence_revoked', $snapshot, $clockSetBack),
            'suspended' => new SiteLicenseStatus(LicensingState::Expired, 'licence_suspended', $snapshot, $clockSetBack),
            'expired' => new SiteLicenseStatus(LicensingState::Expired, 'licence_expired', $snapshot, $clockSetBack),
            default => match (true) {
                $now > $snapshot->freshUntil() => new SiteLicenseStatus(LicensingState::Stale, 'snapshot_not_refreshed', $snapshot, $clockSetBack),
                $clockSetBack => new SiteLicenseStatus(LicensingState::Stale, 'clock_set_back', $snapshot, true),
                default => new SiteLicenseStatus(LicensingState::Active, 'licence_' . $snapshot->licenceStatus, $snapshot),
            },
        };
    }

    /** A snapshot that names addresses must name this one; a snapshot loaded from a file must name addresses. */
    private function checkSite(VerifiedSnapshot $snapshot, string $origin): void
    {
        if ($snapshot->siteHosts === null) {
            if ($origin === SnapshotStore::FILE) {
                throw new SnapshotRefused('licensing_snapshot_not_bound');
            }

            return;
        }
        if (!SnapshotVerifier::names($snapshot, $this->siteHost)) {
            throw new SnapshotRefused('licensing_snapshot_other_site');
        }
    }

    private function keptVerified(): ?VerifiedSnapshot
    {
        try {
            $kept = $this->store->load();

            return $kept === null ? null : $this->verifier->verify($kept->envelope);
        } catch (SnapshotRefused) {
            return null;
        }
    }

    private function now(): DateTimeImmutable
    {
        return ($this->clock !== null ? ($this->clock)() : new DateTimeImmutable())->setTimezone(new DateTimeZone('UTC'));
    }
}
