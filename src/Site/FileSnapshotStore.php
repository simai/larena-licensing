<?php

declare(strict_types=1);

namespace Larena\Licensing\Site;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;

/**
 * The kept snapshot as one JSON file outside the web root (`storage/app/larena/licensing/snapshot.json` by default):
 * shared `storage` survives every release, and the package needs no table. Written whole to a temporary file next
 * to it and renamed, so a reader never sees half a file; a link in its place is refused.
 */
final readonly class FileSnapshotStore implements SnapshotStore
{
    private const string SCHEMA = 'larena.licensing-kept-snapshot';

    public function __construct(private string $path)
    {
    }

    public function load(): ?KeptSnapshot
    {
        if (is_link($this->path)) {
            throw new SnapshotRefused('licensing_store_unreadable');
        }
        if (!is_file($this->path)) {
            return null;
        }
        $bytes = @file_get_contents($this->path, false, null, 0, SnapshotVerifier::MAX_BYTES * 3);
        try {
            $data = is_string($bytes) ? json_decode($bytes, true, 8, JSON_THROW_ON_ERROR) : null;
        } catch (JsonException) {
            $data = null;
        }
        $envelope = is_array($data) ? ($data['envelope'] ?? null) : null;
        $origin = is_array($data) ? ($data['origin'] ?? null) : null;
        $kept = is_array($data) ? self::date($data['kept_at'] ?? null) : null;
        $seen = is_array($data) ? self::date($data['seen_at'] ?? null) : null;
        if (!is_array($data) || ($data['schema'] ?? null) !== self::SCHEMA || !is_array($envelope)
            || !is_string($envelope['entitlement'] ?? null) || !is_string($envelope['signature'] ?? null)
            || !in_array($origin, [self::REFRESH, self::FILE], true) || $kept === null || $seen === null) {
            throw new SnapshotRefused('licensing_store_unreadable');
        }

        return new KeptSnapshot(['entitlement' => $envelope['entitlement'], 'signature' => $envelope['signature']], $origin, $kept, $seen);
    }

    public function keep(array $envelope, string $origin, DateTimeImmutable $now): void
    {
        $previous = $this->safeLoad();
        $seen = $previous !== null && $previous->seenAt > $now ? $previous->seenAt : $now;
        $this->write($envelope, $origin, $now, $seen);
    }

    public function seen(DateTimeImmutable $now): void
    {
        $kept = $this->safeLoad();
        if ($kept !== null && $now > $kept->seenAt) {
            $this->write($kept->envelope, $kept->origin, $kept->keptAt, $now);
        }
    }

    private function safeLoad(): ?KeptSnapshot
    {
        try {
            return $this->load();
        } catch (SnapshotRefused) {
            return null;
        }
    }

    /** @param array{entitlement: string, signature: string} $envelope */
    private function write(array $envelope, string $origin, DateTimeImmutable $kept, DateTimeImmutable $seen): void
    {
        $directory = dirname($this->path);
        if (!is_dir($directory) && !@mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new SnapshotRefused('licensing_store_unwritable');
        }
        if (is_link($this->path)) {
            throw new SnapshotRefused('licensing_store_unwritable');
        }
        $json = json_encode([
            'schema' => self::SCHEMA,
            'origin' => $origin,
            'kept_at' => $kept->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM),
            'seen_at' => $seen->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM),
            'envelope' => $envelope,
        ], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        $temporary = $directory . '/.snapshot-' . bin2hex(random_bytes(6)) . '.tmp';
        if ($json === false || @file_put_contents($temporary, $json, LOCK_EX) !== strlen($json)) {
            @unlink($temporary);
            throw new SnapshotRefused('licensing_store_unwritable');
        }
        @chmod($temporary, 0640);
        if (!@rename($temporary, $this->path)) {
            @unlink($temporary);
            throw new SnapshotRefused('licensing_store_unwritable');
        }
    }

    private static function date(mixed $value): ?DateTimeImmutable
    {
        $date = is_string($value) ? DateTimeImmutable::createFromFormat(DATE_ATOM, $value) : false;

        return $date === false ? null : $date;
    }
}
