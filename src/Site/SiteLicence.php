<?php

declare(strict_types=1);

namespace Larena\Licensing\Site;

use Closure;
use DateTimeInterface;
use RuntimeException;

/**
 * The licence as the admin shows and changes it («Лицензия и обновления» → «Лицензия», owner task 2026-11-26): the
 * state in words, until when it runs and until when updates come (per package), whether a site key is set and where it
 * comes from, the test-address mark; and the actions: «Обновить сейчас» (the same request as `larena:licensing:refresh`),
 * a snapshot file for a closed network (as `larena:licensing:load`) and entering the site key.
 *
 * The site key is written to {@see SiteKeyStore} and never shown again: {@see overview()} says only `key_set` and
 * `key_source`. A key set in the configuration (`LARENA_UPDATE_SITE_KEY`) wins, and the admin cannot replace it
 * (`licensing_site_key_from_config`).
 */
final readonly class SiteLicence
{
    public const string KEY_FROM_CONFIG = 'config';
    public const string KEY_STORED = 'stored';

    /** Every reason an action can end with, each with words in ru and en. */
    public const array REASONS = [
        'licence_refreshed', 'licence_loaded', 'site_key_saved', 'site_key_saved_not_checked',
        'licensing_site_key_missing', 'licensing_site_key_invalid', 'licensing_site_key_unwritable', 'licensing_site_key_from_config',
        'licensing_source_missing', 'licensing_refresh_refused', 'licensing_refresh_not_found', 'licensing_refresh_failed',
        'licensing_file_unreadable', 'licensing_snapshot_refused',
    ];

    /**
     * @param Closure(?string): SnapshotSource $source the update sources with the site key, or with the given key
     */
    public function __construct(
        private SiteLicensing $licensing,
        private SiteKeyStore $keys,
        private Closure $source,
        private ?string $configuredKey,
    ) {
    }

    /** Where the site key comes from: the configuration, the admin, or nowhere. */
    public function keySource(): ?string
    {
        if ($this->configuredKey !== null && trim($this->configuredKey) !== '') {
            return self::KEY_FROM_CONFIG;
        }

        return $this->keys->has() ? self::KEY_STORED : null;
    }

    /**
     * What the page shows. Dates are ATOM; `updates_until` is package (or `*`) => date. Never the key.
     *
     * @return array{state:string, reason_code:string, licence_status:?string, licence_until:?string, issued_at:?string,
     *     fresh_until:?string, updates_until:array<string,string>, trials:array<string,string>, capabilities:int,
     *     development:bool, clock_set_back:bool, key_set:bool, key_source:?string}
     */
    public function overview(): array
    {
        $status = $this->licensing->status();
        $snapshot = $status->snapshot;
        $atom = static fn (?\DateTimeImmutable $date): ?string => $date?->format(DateTimeInterface::ATOM);

        return [
            'state' => $status->state->value,
            'reason_code' => $status->reasonCode,
            'licence_status' => $snapshot?->licenceStatus,
            'licence_until' => $atom($snapshot?->licenceUntil),
            'issued_at' => $atom($snapshot?->issuedAt),
            'fresh_until' => $atom($snapshot?->freshUntil()),
            'updates_until' => $snapshot === null ? [] : array_map(static fn (\DateTimeImmutable $date): string => $date->format(DateTimeInterface::ATOM), $snapshot->updatesUntil),
            'trials' => $snapshot === null ? [] : array_map(static fn (\DateTimeImmutable $date): string => $date->format(DateTimeInterface::ATOM), $snapshot->trials),
            'capabilities' => $snapshot === null ? 0 : count($snapshot->capabilities),
            'development' => $status->state === LicensingState::Development,
            'clock_set_back' => $status->clockSetBack,
            'key_set' => $this->keySource() !== null,
            'key_source' => $this->keySource(),
        ];
    }

    /** «Обновить сейчас»: the site's signed snapshot from the update server, kept when it verifies. */
    public function refresh(): LicenceActionResult
    {
        return $this->fetch(($this->source)(null), 'licence_refreshed');
    }

    /** A snapshot file for a site without network: it must verify and name this site's address. */
    public function load(string $bytes): LicenceActionResult
    {
        if ($bytes === '' || strlen($bytes) > SnapshotVerifier::MAX_BYTES) {
            return new LicenceActionResult(false, 'licensing_file_unreadable');
        }
        try {
            $this->licensing->accept($bytes, SnapshotStore::FILE);
        } catch (SnapshotRefused $refused) {
            return new LicenceActionResult(false, 'licensing_snapshot_refused', false, $refused->reason());
        }

        return new LicenceActionResult(true, 'licence_loaded');
    }

    /**
     * Keeps the site key and asks for the snapshot with it at once. A key the server refuses is still kept (the person
     * may enter the right one later) and the answer says so; a malformed key is not kept.
     */
    public function enterKey(#[\SensitiveParameter] string $key): LicenceActionResult
    {
        if ($this->keySource() === self::KEY_FROM_CONFIG) {
            return new LicenceActionResult(false, 'licensing_site_key_from_config');
        }
        try {
            $this->keys->save($key);
        } catch (RuntimeException $failure) {
            return new LicenceActionResult(false, in_array($failure->getMessage(), ['licensing_site_key_invalid', 'licensing_site_key_unwritable'], true)
                ? $failure->getMessage() : 'licensing_site_key_unwritable');
        }
        $fetched = $this->fetch(($this->source)(trim($key)), 'site_key_saved');
        if ($fetched->done) {
            return $fetched;
        }

        return new LicenceActionResult(false, $fetched->reasonCode === 'licensing_source_missing' ? 'site_key_saved_not_checked' : $fetched->reasonCode,
            $fetched->requested, $fetched->detail);
    }

    private function fetch(SnapshotSource $source, string $done): LicenceActionResult
    {
        try {
            $envelope = $source->fetch();
        } catch (RuntimeException $failure) {
            try {
                $this->licensing->markSeen();
            } catch (RuntimeException) {
                // The kept snapshot stays as it is; the failure of the request is what the person sees.
            }
            $code = $failure->getMessage();

            return new LicenceActionResult(false, in_array($code, self::REASONS, true) ? $code : 'licensing_refresh_failed', $code !== 'licensing_site_key_missing');
        }
        try {
            $this->licensing->accept($envelope, SnapshotStore::REFRESH);
        } catch (SnapshotRefused $refused) {
            return new LicenceActionResult(false, 'licensing_snapshot_refused', true, $refused->reason());
        }

        return new LicenceActionResult(true, $done, true);
    }
}
