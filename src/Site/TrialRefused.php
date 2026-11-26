<?php

declare(strict_types=1);

namespace Larena\Licensing\Site;

use RuntimeException;

/**
 * The update server refused a trial and named why (`{"error": code}`, one of {@see SiteTrial::SERVER_REFUSALS}). The
 * message is that code only: never the site key, an address or the answer's body.
 */
final class TrialRefused extends RuntimeException
{
    public function reason(): string
    {
        return $this->getMessage();
    }
}
