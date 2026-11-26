<?php

declare(strict_types=1);

namespace Larena\Licensing\Site;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;

/**
 * Reads and verifies the envelope the registration server signs (`larena-server-registration`, Registry::snapshot):
 * `{"entitlement": "<exact bytes>", "signature": "{\"key_id\":…,\"signature\":…}"}`, the same envelope the update
 * server hands a site at `<source>/entitlement` (with `schema: larena.entitlement-envelope`). The signature is
 * Ed25519 over the exact entitlement bytes, by a key of {@see TrustedKeys}; a revoked id wins.
 *
 * The entitlement is `larena.entitlement` schema_version 1: `snapshot_key`, `site`, `product`, `status`,
 * `capabilities`, `issued_at`, `valid_until`, and optionally `site_hosts`, `updates_until`, `fresh_until` and `trials`
 * (the site fields; see the package README). `trials` is `{"<capability>": "<ATOM end>"}` for the capabilities held
 * only by a running trial; each must be listed in `capabilities` and end no earlier than the issue date. Any other shape is refused. Every refusal is a {@see SnapshotRefused} with a
 * stable code; nothing of the snapshot or the signature goes into it.
 */
final readonly class SnapshotVerifier
{
    public const int MAX_BYTES = 65536;
    public const string CAPABILITY = '/\A[a-z][a-z0-9_]*(\.[a-z][a-z0-9_-]*){0,7}\z/D';
    private const string PACKAGE = '/\A(\*|[a-z0-9][a-z0-9_.-]{0,99}\/[a-z0-9][a-z0-9_.-]{0,99})\z/D';
    private const string HOST = '/\A[a-z0-9]([a-z0-9-]{0,62}\.)*[a-z0-9-]{1,63}\z/D';
    private const array STATUSES = ['missing', 'active', 'trial', 'grace', 'expired', 'revoked', 'suspended'];

    /** How far ahead of this site's clock a snapshot may have been issued (clocks differ a little). */
    public const int CLOCK_SKEW_SECONDS = 300;

    public function __construct(private TrustedKeys $keys, private string $product)
    {
    }

    /**
     * The envelope as JSON text, from the server or a file: `{entitlement, signature}`, with `signature` as the
     * detached signature text or the same object.
     *
     * @return array{entitlement: string, signature: string}
     */
    public static function envelope(string $json): array
    {
        if ($json === '' || strlen($json) > self::MAX_BYTES) {
            throw new SnapshotRefused('licensing_snapshot_malformed');
        }
        $data = self::json($json, 8);
        if (isset($data['schema']) && ($data['schema'] !== 'larena.entitlement-envelope' || ($data['schema_version'] ?? null) !== 1)) {
            throw new SnapshotRefused('licensing_snapshot_unsupported');
        }
        $signature = $data['signature'] ?? null;
        if (is_array($signature) && !array_is_list($signature)) {
            $signature = json_encode($signature, JSON_UNESCAPED_SLASHES);
        }
        if (!is_string($data['entitlement'] ?? null) || !is_string($signature) || $data['entitlement'] === '') {
            throw new SnapshotRefused('licensing_snapshot_malformed');
        }

        return ['entitlement' => $data['entitlement'], 'signature' => $signature];
    }

    /**
     * Verifies the signature and reads the snapshot. With `$now`, a snapshot issued later than now (plus a small
     * skew) is refused: a date from the future means a wrong clock here or a forged date there.
     *
     * @param array{entitlement: string, signature: string} $envelope
     */
    public function verify(array $envelope, ?DateTimeImmutable $now = null): VerifiedSnapshot
    {
        $bytes = $envelope['entitlement'];
        $signature = self::json($envelope['signature'], 4);
        $keyId = $signature['key_id'] ?? null;
        $encoded = $signature['signature'] ?? null;
        if (!is_string($keyId) || preg_match(TrustedKeys::KEY_ID, $keyId) !== 1 || !is_string($encoded)) {
            throw new SnapshotRefused('licensing_signature_malformed');
        }
        if ($this->keys->isRevoked($keyId)) {
            throw new SnapshotRefused('licensing_key_revoked');
        }
        $key = $this->keys->key($keyId);
        if ($key === null) {
            throw new SnapshotRefused('licensing_key_unknown');
        }
        $raw = base64_decode($encoded, true);
        if (!is_string($raw) || strlen($raw) !== SODIUM_CRYPTO_SIGN_BYTES || !sodium_crypto_sign_verify_detached($raw, $bytes, $key)) {
            throw new SnapshotRefused('licensing_signature_invalid');
        }

        $data = self::json($bytes, 8);
        if (($data['schema'] ?? null) !== 'larena.entitlement' || ($data['schema_version'] ?? null) !== 1) {
            throw new SnapshotRefused('licensing_snapshot_unsupported');
        }
        foreach (['snapshot_key', 'site', 'product', 'status', 'issued_at', 'valid_until'] as $field) {
            if (!is_string($data[$field] ?? null) || trim($data[$field]) === '') {
                throw new SnapshotRefused('licensing_snapshot_malformed');
            }
        }
        if ($data['product'] !== $this->product) {
            throw new SnapshotRefused('licensing_snapshot_other_product');
        }
        if (!in_array($data['status'], self::STATUSES, true)) {
            throw new SnapshotRefused('licensing_snapshot_malformed');
        }
        $capabilities = $data['capabilities'] ?? null;
        if (!is_array($capabilities) || !array_is_list($capabilities)) {
            throw new SnapshotRefused('licensing_snapshot_malformed');
        }
        foreach ($capabilities as $capability) {
            if (!is_string($capability) || preg_match(self::CAPABILITY, $capability) !== 1) {
                throw new SnapshotRefused('licensing_snapshot_malformed');
            }
        }
        $issuedAt = self::date($data['issued_at']);
        $validUntil = self::date($data['valid_until']);
        $freshUntil = isset($data['fresh_until']) ? self::date($data['fresh_until']) : null;
        $licenceUntil = isset($data['licence_until']) ? self::date($data['licence_until']) : null;
        if ($validUntil < $issuedAt || ($freshUntil !== null && $freshUntil < $issuedAt)) {
            throw new SnapshotRefused('licensing_snapshot_malformed');
        }
        if ($now !== null && $issuedAt->getTimestamp() > $now->getTimestamp() + self::CLOCK_SKEW_SECONDS) {
            throw new SnapshotRefused('licensing_snapshot_from_future');
        }

        return new VerifiedSnapshot(
            $keyId,
            $data['snapshot_key'],
            $data['site'],
            $data['product'],
            $data['status'],
            array_values(array_unique($capabilities)),
            $issuedAt,
            $validUntil,
            $freshUntil,
            self::hosts($data['site_hosts'] ?? null, array_key_exists('site_hosts', $data)),
            self::updatesUntil($data['updates_until'] ?? null),
            $envelope['signature'],
            self::trials($data['trials'] ?? null, $capabilities, $issuedAt),
            $licenceUntil,
        );
    }

    /** Whether a snapshot names this site's address. */
    public static function names(VerifiedSnapshot $snapshot, string $host): bool
    {
        return $snapshot->siteHosts !== null && in_array(self::host($host), $snapshot->siteHosts, true);
    }

    public static function host(string $host): string
    {
        return strtolower(rtrim(trim($host), '.'));
    }

    /** @return list<string>|null */
    private static function hosts(mixed $value, bool $present): ?array
    {
        if (!$present) {
            return null;
        }
        if (!is_array($value) || !array_is_list($value) || $value === []) {
            throw new SnapshotRefused('licensing_snapshot_malformed');
        }
        $hosts = [];
        foreach ($value as $host) {
            if (!is_string($host) || preg_match(self::HOST, self::host($host)) !== 1) {
                throw new SnapshotRefused('licensing_snapshot_malformed');
            }
            $hosts[] = self::host($host);
        }

        return array_values(array_unique($hosts));
    }

    /** @return array<string,DateTimeImmutable> */
    private static function updatesUntil(mixed $value): array
    {
        if ($value === null) {
            return [];
        }
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new SnapshotRefused('licensing_snapshot_malformed');
        }
        $until = [];
        foreach ($value as $package => $date) {
            if (!is_string($package) || preg_match(self::PACKAGE, $package) !== 1 || !is_string($date)) {
                throw new SnapshotRefused('licensing_snapshot_malformed');
            }
            $until[$package] = self::date($date);
        }

        return $until;
    }

    /**
     * The `trials` field: capability => end of its running trial. Absent or null: no trial is named.
     *
     * @param list<mixed> $capabilities
     * @return array<string,DateTimeImmutable>
     */
    private static function trials(mixed $value, array $capabilities, DateTimeImmutable $issuedAt): array
    {
        if ($value === null) {
            return [];
        }
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new SnapshotRefused('licensing_snapshot_malformed');
        }
        $trials = [];
        foreach ($value as $capability => $date) {
            if (!is_string($capability) || preg_match(self::CAPABILITY, $capability) !== 1 || !is_string($date)
                || !in_array($capability, $capabilities, true)) {
                throw new SnapshotRefused('licensing_snapshot_malformed');
            }
            $end = self::date($date);
            if ($end < $issuedAt) {
                throw new SnapshotRefused('licensing_snapshot_malformed');
            }
            $trials[$capability] = $end;
        }

        return $trials;
    }

    private static function date(mixed $value): DateTimeImmutable
    {
        $date = is_string($value) ? DateTimeImmutable::createFromFormat(DATE_ATOM, $value) : false;
        if ($date === false || $date->format(DATE_ATOM) !== $value) {
            throw new SnapshotRefused('licensing_snapshot_malformed');
        }

        return $date->setTimezone(new DateTimeZone('UTC'));
    }

    /** @return array<string, mixed> */
    private static function json(string $text, int $depth): array
    {
        try {
            $data = json_decode($text, true, $depth, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new SnapshotRefused('licensing_snapshot_malformed');
        }
        if (!is_array($data) || ($data !== [] && array_is_list($data))) {
            throw new SnapshotRefused('licensing_snapshot_malformed');
        }

        return $data;
    }
}
