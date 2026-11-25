<?php

declare(strict_types=1);

namespace Larena\Licensing\Site;

/**
 * Whether this installation is a copy for development, which gets every capability without a snapshot (owner
 * decision 2026-11-25). The default is {@see TestAddresses}; a partner developer key will be another implementation
 * of this seam.
 */
interface DevelopmentAccess
{
    /** The reason code when the site is a development copy, else null. */
    public function reason(string $siteHost): ?string;
}
