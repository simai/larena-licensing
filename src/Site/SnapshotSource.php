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
 * A trial («Попробовать бесплатно») goes the same way: `POST <source>/trial` with JSON `{"capability": "<id>"}`; the
 * answer is the same signed envelope, or `{"error": "<code>"}` ({@see trial()}).
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
     * @param \Closure(string, array<string,string>, int, int, int, ?string=): array{0:int,1:string} $get url, headers,
     *        timeout, connect timeout, byte limit and, for a POST, its JSON body → status and body; throws on a network
     *        failure
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
        foreach ($this->servers() as $source) {
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
     * Asks the update server for a trial of one capability: `POST <source>/trial`, the site key as for the snapshot,
     * JSON `{"capability": "<id>"}`. Returns the envelope text of a 2xx answer (to be verified like a refresh).
     *
     * A refusal the server names (`{"error": code}` with one of {@see SiteTrial::SERVER_REFUSALS}) is the answer: it is
     * a {@see TrialRefused} at once and no other source is asked. A network failure or an unnamed answer tries the next
     * source; when none answers, a RuntimeException `trial_request_failed` (or `licensing_source_missing`,
     * `licensing_site_key_missing`).
     */
    public function trial(string $capability): string
    {
        if ($this->siteKey === null) {
            throw new RuntimeException('licensing_site_key_missing');
        }
        $body = json_encode(['capability' => $capability], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $failure = null;
        foreach ($this->servers() as $source) {
            try {
                [$status, $answer] = ($this->get)($source . '/trial', ['Authorization' => 'Bearer ' . $this->siteKey, 'Content-Type' => 'application/json'],
                    min(60, max(1, $this->timeoutSeconds)), max(1, $this->connectTimeoutSeconds), SnapshotVerifier::MAX_BYTES, $body);
            } catch (\Throwable) {
                $failure = 'trial_request_failed';
                continue;
            }
            if ($status >= 200 && $status < 300) {
                return $answer;
            }
            $code = self::errorCode($answer);
            if ($code !== null && in_array($code, SiteTrial::SERVER_REFUSALS, true)) {
                throw new TrialRefused($code);
            }
            $failure = 'trial_request_failed';
        }

        throw new RuntimeException($failure ?? 'licensing_source_missing');
    }

    /**
     * The update servers among the sources: HTTPS, not github.com (public releases know no site), not a folder.
     *
     * @return list<string>
     */
    private function servers(): array
    {
        $servers = [];
        foreach ($this->sources as $source) {
            $source = rtrim(trim($source), '/');
            $host = strtolower((string) parse_url($source, PHP_URL_HOST));
            if (strtolower((string) parse_url($source, PHP_URL_SCHEME)) !== 'https' || $host === '' || $host === 'github.com' || str_ends_with($host, '.github.com')) {
                continue;
            }
            $servers[] = $source;
        }

        return $servers;
    }

    /** The `error` code of a refusal body `{"error": "<code>"}`, or null. */
    private static function errorCode(string $body): ?string
    {
        if ($body === '' || strlen($body) > 4096) {
            return null;
        }
        $data = json_decode($body, true, 4);
        $code = is_array($data) ? ($data['error'] ?? null) : null;

        return is_string($code) && preg_match('/\A[a-z][a-z0-9_]{0,63}\z/D', $code) === 1 ? $code : null;
    }

    /**
     * The real transport over ext-curl: HTTPS only, TLS verified, no redirects, the body cut at the limit. A body
     * makes it a POST of that JSON; without one it is a GET.
     *
     * @return \Closure(string, array<string,string>, int, int, int, ?string=): array{0:int,1:string}
     */
    public static function curl(string $userAgent = 'larena-licensing'): \Closure
    {
        return static function (string $url, array $headers, int $timeout, int $connectTimeout, int $limit, ?string $post = null) use ($userAgent): array {
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
            curl_setopt_array($handle, ($post !== null ? [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $post] : [CURLOPT_HTTPGET => true]) + [
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
