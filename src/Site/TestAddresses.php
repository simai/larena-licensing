<?php

declare(strict_types=1);

namespace Larena\Licensing\Site;

/**
 * Test addresses are free (owner decision 2026-11-25): `localhost` (with `127.0.0.1`, `::1` and `*.localhost`),
 * `*.test`, `*.local`, `dev.*`, `staging.*` and `*.larena.dev`. The host is the site's own configured address
 * (APP_URL), never a request's Host header, which a visitor chooses.
 */
final readonly class TestAddresses implements DevelopmentAccess
{
    public const string REASON = 'development_address';

    public function reason(string $siteHost): ?string
    {
        return self::isTestAddress($siteHost) ? self::REASON : null;
    }

    public static function isTestAddress(string $host): bool
    {
        $host = SnapshotVerifier::host(trim($host, '[]'));
        if ($host === '') {
            return false;
        }

        return in_array($host, ['localhost', '127.0.0.1', '::1'], true)
            || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.test')
            || str_ends_with($host, '.local')
            || str_ends_with($host, '.larena.dev')
            || (str_starts_with($host, 'dev.') && strlen($host) > 4)
            || (str_starts_with($host, 'staging.') && strlen($host) > 8);
    }
}
