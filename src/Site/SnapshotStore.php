<?php

declare(strict_types=1);

namespace Larena\Licensing\Site;

use DateTimeImmutable;

/**
 * Where the site keeps its last good snapshot, how it came (`refresh` from the server, `file` loaded by hand) and the
 * latest time the site has seen (the clock may go back; this mark does not).
 */
interface SnapshotStore
{
    public const string REFRESH = 'refresh';
    public const string FILE = 'file';

    /** The kept snapshot, or null when there is none. An unreadable store is a {@see SnapshotRefused}. */
    public function load(): ?KeptSnapshot;

    /** @param array{entitlement: string, signature: string} $envelope */
    public function keep(array $envelope, string $origin, DateTimeImmutable $now): void;

    /** Moves the latest-seen mark forward (never back). */
    public function seen(DateTimeImmutable $now): void;
}
