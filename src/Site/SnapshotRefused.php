<?php

declare(strict_types=1);

namespace Larena\Licensing\Site;

use RuntimeException;

/**
 * A snapshot that is not taken, with a stable reason code as the message. The message never carries the snapshot,
 * the signature, a key or the site key.
 */
final class SnapshotRefused extends RuntimeException
{
    public function reason(): string
    {
        return $this->getMessage();
    }
}
