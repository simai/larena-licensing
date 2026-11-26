<?php

declare(strict_types=1);

namespace Larena\Licensing\Site;

use Closure;
use DateTimeImmutable;
use DateTimeInterface;
use Larena\Licensing\Catalog\CapabilityCatalog;
use RuntimeException;

/**
 * The closed addons «Дополнения» shows (owner task 2026-11-26): installed and available, with «Попробовать 14 дней»,
 * «Купить» (the external shop's page) and the trial dates.
 *
 * The list comes from Larena Server's `GET <source>/addons` (`larena.addon-list` schema_version 1, shape of 2026-11-26:
 * `addons[]` of `{offer, title, capabilities, packages, state: held|trial|available, until, shop_url}`), kept in one file
 * (`storage/app/larena/licensing/addons.json`) so the page never waits for the network; {@see refresh()} asks again.
 * The list is not signed: what the site may run stays the signed snapshot, so each addon's state is decided here again
 * from {@see SiteLicensing} (held: every capability allowed; trial: allowed through a running trial; available: not).
 * Without a kept list the paid, trial-eligible functions of the shipped catalog stand in, one addon per package.
 */
final readonly class SiteAddons
{
    public const string HELD = 'held';
    public const string TRIAL = 'trial';
    public const string AVAILABLE = 'available';

    private const string SCHEMA = 'larena.addon-list';

    /**
     * @param Closure(): SnapshotSource $source
     * @param Closure(string): bool     $installed whether a package is installed in this site
     */
    public function __construct(
        private SiteLicensing $licensing,
        private CapabilityCatalog $catalog,
        private Closure $source,
        private string $path,
        private Closure $installed,
        private ?string $shopUrl = null,
    ) {
    }

    /**
     * @return array{source: string, fetched_at: ?string, addons: list<array{offer: string, title: ?string, title_key: ?string,
     *     capabilities: list<string>, packages: list<string>, state: string, until: ?string, shop_url: ?string, installed: bool,
     *     trial_capability: ?string, trial_days: ?int}>}
     */
    public function list(): array
    {
        $kept = $this->kept();
        $offers = $kept['addons'] ?? $this->fromCatalog();
        $addons = [];
        foreach ($offers as $offer) {
            $addons[] = $this->decide($offer);
        }

        return ['source' => $kept === null ? 'catalog' : 'server', 'fetched_at' => $kept['fetched_at'] ?? null, 'addons' => $addons];
    }

    /** One addon by its offer id, decided now; null when the list has none. */
    public function get(string $offer): ?array
    {
        foreach ($this->list()['addons'] as $addon) {
            if ($addon['offer'] === $offer) {
                return $addon;
            }
        }

        return null;
    }

    /** Asks the server for the list again and keeps it when it reads. */
    public function refresh(): LicenceActionResult
    {
        try {
            $body = ($this->source)()->addons();
        } catch (RuntimeException $failure) {
            $code = $failure->getMessage();

            return new LicenceActionResult(false, in_array($code, ['licensing_site_key_missing', 'licensing_source_missing'], true) ? $code : 'addons_request_failed',
                $code !== 'licensing_site_key_missing');
        }
        $addons = self::parse($body);
        if ($addons === null) {
            return new LicenceActionResult(false, 'addons_list_invalid', true);
        }
        $this->write(['schema' => 'larena.licensing-kept-addons', 'fetched_at' => gmdate(DATE_ATOM), 'addons' => $addons]);

        return new LicenceActionResult(true, 'addons_refreshed', true);
    }

    /**
     * Reads a `larena.addon-list` answer; null when it is not one. Unknown fields are ignored, bad entries dropped.
     *
     * @return list<array{offer: string, title: ?string, capabilities: list<string>, packages: list<string>, until: ?string, shop_url: ?string}>|null
     */
    public static function parse(string $body): ?array
    {
        if ($body === '' || strlen($body) > SnapshotVerifier::MAX_BYTES) {
            return null;
        }
        $data = json_decode($body, true, 8);
        if (!is_array($data) || ($data['schema'] ?? null) !== self::SCHEMA || ($data['schema_version'] ?? null) !== 1 || !is_array($data['addons'] ?? null)) {
            return null;
        }
        $addons = [];
        foreach ($data['addons'] as $addon) {
            $offer = is_array($addon) ? ($addon['offer'] ?? null) : null;
            if (!is_string($offer) || preg_match('/\A[a-z0-9][a-z0-9._-]{0,63}\z/D', $offer) !== 1) {
                continue;
            }
            $capabilities = array_values(array_filter(is_array($addon['capabilities'] ?? null) ? $addon['capabilities'] : [],
                static fn (mixed $id): bool => is_string($id) && preg_match(SnapshotVerifier::CAPABILITY, $id) === 1));
            $packages = array_values(array_filter(is_array($addon['packages'] ?? null) ? $addon['packages'] : [],
                static fn (mixed $name): bool => is_string($name) && preg_match('/\A[a-z0-9][a-z0-9_.-]{0,99}\/[a-z0-9][a-z0-9_.-]{0,99}\z/D', $name) === 1));
            $title = is_string($addon['title'] ?? null) ? mb_substr(trim($addon['title']), 0, 120) : null;
            $until = is_string($addon['until'] ?? null) && DateTimeImmutable::createFromFormat(DATE_ATOM, $addon['until']) !== false ? $addon['until'] : null;
            $shop = is_string($addon['shop_url'] ?? null) && self::httpsUrl($addon['shop_url']) ? $addon['shop_url'] : null;
            $addons[] = ['offer' => $offer, 'title' => $title !== '' ? $title : null, 'capabilities' => $capabilities, 'packages' => $packages,
                'until' => $until, 'shop_url' => $shop];
        }

        return $addons;
    }

    /** @param array{offer: string, title?: ?string, title_key?: ?string, capabilities: list<string>, packages: list<string>, until?: ?string, shop_url?: ?string} $offer */
    private function decide(array $offer): array
    {
        $capabilities = $offer['capabilities'];
        $allowed = $capabilities !== [] && array_reduce($capabilities, fn (bool $all, string $id): bool => $all && $this->licensing->allows($id), true);
        $trialEnd = null;
        foreach ($capabilities as $id) {
            $end = $this->licensing->trialUntil($id);
            if ($end !== null && ($trialEnd === null || $end < $trialEnd)) {
                $trialEnd = $end;
            }
        }
        $development = $this->licensing->status()->state === LicensingState::Development;
        $state = !$allowed ? self::AVAILABLE : ($trialEnd !== null && !$development ? self::TRIAL : self::HELD);
        $until = $state === self::TRIAL ? $trialEnd?->format(DateTimeInterface::ATOM) : ($state === self::HELD ? $this->updatesUntil($offer['packages']) : null);
        // A trial is offered on the first trial-eligible function of an addon the site does not hold.
        $trial = null;
        $days = null;
        if ($state === self::AVAILABLE) {
            foreach ($capabilities as $id) {
                $entry = $this->catalog->get($id);
                if ($entry !== null && $entry->trialEligible && !$entry->isFree()) {
                    $trial = $id;
                    $days = $entry->trialDays;
                    break;
                }
            }
        }
        $installed = false;
        foreach ($offer['packages'] as $package) {
            $installed = $installed || ($this->installed)($package);
        }

        return [
            'offer' => $offer['offer'],
            'title' => $offer['title'] ?? null,
            'title_key' => $offer['title_key'] ?? null,
            'capabilities' => $capabilities,
            'packages' => $offer['packages'],
            'state' => $state,
            'until' => $until,
            'shop_url' => ($offer['shop_url'] ?? null) ?? $this->shopUrl,
            'installed' => $installed,
            'trial_capability' => $trial,
            'trial_days' => $days,
        ];
    }

    /** @param list<string> $packages The earliest «updates until» of the addon's packages, from the signed snapshot. */
    private function updatesUntil(array $packages): ?string
    {
        $earliest = null;
        foreach ($packages as $package) {
            $date = $this->licensing->updatesUntil($package);
            if ($date !== null && ($earliest === null || $date < $earliest)) {
                $earliest = $date;
            }
        }

        return $earliest?->format(DateTimeInterface::ATOM);
    }

    /** @return list<array{offer: string, title_key: string, capabilities: list<string>, packages: list<string>}> */
    private function fromCatalog(): array
    {
        $offers = [];
        foreach ($this->catalog->all() as $capability) {
            if ($capability->isFree() || $capability->tier !== 'paid' || !$capability->trialEligible) {
                continue;
            }
            $offer = str_replace('/', '-', $capability->package);
            if (!isset($offers[$offer])) {
                $offers[$offer] = ['offer' => $offer, 'title_key' => $capability->titleKey, 'capabilities' => [], 'packages' => [$capability->package]];
            }
            $offers[$offer]['capabilities'][] = $capability->id;
        }

        return array_values($offers);
    }

    /** @return array{fetched_at: string, addons: list<array<string, mixed>>}|null */
    private function kept(): ?array
    {
        if (is_link($this->path) || !is_file($this->path)) {
            return null;
        }
        $data = json_decode((string) @file_get_contents($this->path, false, null, 0, SnapshotVerifier::MAX_BYTES * 2), true, 8);
        if (!is_array($data) || ($data['schema'] ?? null) !== 'larena.licensing-kept-addons' || !is_array($data['addons'] ?? null) || !is_string($data['fetched_at'] ?? null)) {
            return null;
        }

        return ['fetched_at' => $data['fetched_at'], 'addons' => array_values(array_filter($data['addons'], static fn (mixed $addon): bool
            => is_array($addon) && is_string($addon['offer'] ?? null) && is_array($addon['capabilities'] ?? null) && is_array($addon['packages'] ?? null)))];
    }

    /** @param array<string, mixed> $data */
    private function write(array $data): void
    {
        $directory = dirname($this->path);
        if ((!is_dir($directory) && !@mkdir($directory, 0750, true) && !is_dir($directory)) || is_link($this->path)) {
            return;
        }
        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $temporary = $directory . '/.addons-' . bin2hex(random_bytes(6)) . '.tmp';
        if ($json === false || @file_put_contents($temporary, $json, LOCK_EX) !== strlen($json) || !@rename($temporary, $this->path)) {
            @unlink($temporary);
        }
    }

    private static function httpsUrl(string $url): bool
    {
        return strlen($url) <= 500 && strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https' && is_string(parse_url($url, PHP_URL_HOST))
            && preg_match('/[\s"<>]/', $url) !== 1;
    }
}
