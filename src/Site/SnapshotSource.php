<?php

declare(strict_types=1);

namespace Larena\Licensing\Site;

use RuntimeException;

/**
 * Fetches the site's snapshot envelope the way larena/update's ReleaseSource::entitlement() does: `GET
 * <source>/entitlement` on each HTTPS update source in order (not github.com, not a folder), with the site key as
 * `Authorization: Bearer`, no redirects, at most 64 KiB, within the update client's timeouts. The update server
 * asks the registration server and returns its signed envelope as is.
 *
 * The site key never goes into an address, a reason or an error. Failures are a RuntimeException with a stable code:
 * `licensing_site_key_missing`, `licensing_source_missing`, `licensing_refresh_refused` (401/403),
 * `licensing_refresh_not_found` (404), `licensing_refresh_failed`.
 */
final readonly class SnapshotSource
{
    private ?string $siteKey;

    /**
     * @param list<string> $sources
     * @param \Closure(string, array<string,string>, int, int, int): array{0:int,1:string} $get url, headers, timeout,
     *        connect timeout, byte limit → status and body; throws on a network failure
     */
    public function __construct(
        private array $sources,
        #[\SensitiveParameter] ?string $siteKey,
        private \Closure $get,
        private int $timeoutSeconds = 60,
        private int $connectTimeoutSeconds = 15,
    ) {
        $siteKey = $siteKey === null ? '' : trim($siteKey);
        $this->siteKey = $siteKey !== '' && preg_match('/\A[\x21-\x7e]{1,4096}\z/D', $siteKey) === 1 ? $siteKey : null;
    }

    public function hasSiteKey(): bool
    {
        return $this->siteKey !== null;
    }

    public function fetch(): string
    {
        if ($this->siteKey === null) {
            throw new RuntimeException('licensing_site_key_missing');
        }
        $failure = null;
        foreach ($this->sources as $source) {
            $source = rtrim(trim($source), '/');
            $host = strtolower((string) parse_url($source, PHP_URL_HOST));
            if (strtolower((string) parse_url($source, PHP_URL_SCHEME)) !== 'https' || $host === '' || $host === 'github.com' || str_ends_with($host, '.github.com')) {
                continue;
            }
            try {
                [$status, $body] = ($this->get)($source . '/entitlement', ['Authorization' => 'Bearer ' . $this->siteKey],
                    min(60, max(1, $this->timeoutSeconds)), max(1, $this->connectTimeoutSeconds), SnapshotVerifier::MAX_BYTES);
            } catch (\Throwable) {
                $failure ??= 'licensing_refresh_failed';
                continue;
            }
            if ($status >= 200 && $status < 300) {
                return $body;
            }
            $failure = match ($status) {
                401, 403 => 'licensing_refresh_refused',
                404 => $failure ?? 'licensing_refresh_not_found',
                default => $failure ?? 'licensing_refresh_failed',
            };
        }

        throw new RuntimeException($failure ?? 'licensing_source_missing');
    }

    /**
     * The real transport over ext-curl: HTTPS only, TLS verified, no redirects, the body cut at the limit.
     *
     * @return \Closure(string, array<string,string>, int, int, int): array{0:int,1:string}
     */
    public static function curl(string $userAgent = 'larena-licensing'): \Closure
    {
        return static function (string $url, array $headers, int $timeout, int $connectTimeout, int $limit) use ($userAgent): array {
            if (!function_exists('curl_init')) {
                throw new RuntimeException('licensing_http_unavailable');
            }
            $lines = ['User-Agent: ' . $userAgent, 'Accept: application/json'];
            foreach ($headers as $name => $value) {
                if (preg_match('/\A[A-Za-z0-9-]{1,64}\z/D', $name) !== 1 || preg_match('/[\r\n]/', $value) === 1) {
                    throw new RuntimeException('licensing_http_header_invalid');
                }
                $lines[] = $name . ': ' . $value;
            }
            $body = '';
            $tooLarge = false;
            $handle = curl_init($url);
            curl_setopt_array($handle, [
                CURLOPT_HTTPGET => true,
                CURLOPT_HTTPHEADER => $lines,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_CONNECTTIMEOUT => $connectTimeout,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_MAXFILESIZE => $limit,
                CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use ($limit, &$body, &$tooLarge): int {
                    if (strlen($body) + strlen($chunk) > $limit) {
                        $tooLarge = true;

                        return 0;
                    }
                    $body .= $chunk;

                    return strlen($chunk);
                },
            ]);
            $ok = curl_exec($handle);
            $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            $error = curl_errno($handle);
            if ($tooLarge || $ok === false || $error !== 0) {
                throw new RuntimeException('licensing_refresh_failed');
            }

            return [$status, $body];
        };
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['sources' => $this->sources, 'site_key' => $this->siteKey === null ? null : '[set]'];
    }
}
