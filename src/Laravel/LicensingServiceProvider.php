<?php

declare(strict_types=1);

namespace Larena\Licensing\Laravel;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Larena\Licensing\Catalog\CapabilityCatalog;
use Larena\Licensing\Catalog\SiteCapabilities;
use Larena\Licensing\Contracts\LicensingRuntime;
use Larena\Licensing\Laravel\Console\LicensingLoadCommand;
use Larena\Licensing\Laravel\Console\LicensingRefreshCommand;
use Larena\Licensing\Laravel\Console\LicensingStatusCommand;
use Larena\Licensing\Laravel\Console\LicensingTrialCommand;
use Larena\Licensing\Site\DevelopmentAccess;
use Larena\Licensing\Site\FileSnapshotStore;
use Larena\Licensing\Site\SiteAddons;
use Larena\Licensing\Site\SiteCoupon;
use Larena\Licensing\Site\SiteKeyStore;
use Larena\Licensing\Site\SiteLicence;
use Larena\Licensing\Site\SiteLicensing;
use Larena\Licensing\Site\SiteLicensingRuntime;
use Larena\Licensing\Site\SiteTrial;
use Larena\Licensing\Site\SnapshotSource;
use Larena\Licensing\Site\SnapshotStore;
use Larena\Licensing\Site\SnapshotVerifier;
use Larena\Licensing\Site\TestAddresses;
use Larena\Licensing\Site\TrustedKeys;

/**
 * Licensing in a Larena site (owner decisions 2026-11-25): configuration `larena-licensing`, the site's signed
 * snapshot as the default {@see LicensingRuntime} and as {@see SiteLicensing}, the shipped capability catalog and its
 * read model {@see SiteCapabilities}, the commands
 * `larena:licensing:refresh|load|status|trial`, «Попробовать бесплатно» ({@see SiteTrial}), the admin's licence section
 * ({@see SiteLicence}, {@see SiteCoupon}, {@see SiteAddons}), a daily refresh through the scheduler, and the licence in the admin's
 * alert bar where the admin has one. No table, no route, nothing on the public site.
 */
final class LicensingServiceProvider extends ServiceProvider
{
    /** The public entitlement keys the distribution ships: the site's `config/larena-entitlement-keys.php`. */
    public const string ENTITLEMENT_KEYS_CONFIG = 'larena-entitlement-keys';

    /** Set when the update client's site key was copied from the key entered in the admin (not from `.env`). */
    private const string KEY_FROM_ADMIN = 'larena-licensing.site_key_from_admin';

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/larena-licensing.php', 'larena-licensing');

        $this->app->singleton(TrustedKeys::class, static function (Application $app): TrustedKeys {
            $config = $app->make(ConfigRepository::class);

            return TrustedKeys::from(
                [$config->get(self::ENTITLEMENT_KEYS_CONFIG, []), $config->get('larena-licensing.trusted_keys', [])],
                [$config->get('larena-licensing.revoked_key_ids', []), $config->get('update-client.revoked_key_ids', [])],
            );
        });
        $this->app->singleton(SnapshotVerifier::class, static fn (Application $app): SnapshotVerifier => new SnapshotVerifier(
            $app->make(TrustedKeys::class),
            self::product($app->make(ConfigRepository::class)),
        ));
        $this->app->singletonIf(SnapshotStore::class, static function (Application $app): SnapshotStore {
            $path = $app->make(ConfigRepository::class)->get('larena-licensing.snapshot_path');

            return new FileSnapshotStore(is_string($path) && $path !== '' ? $path : $app->storagePath('app/larena/licensing/snapshot.json'));
        });
        $this->app->singletonIf(DevelopmentAccess::class, static fn (): DevelopmentAccess => new TestAddresses());
        // The capability catalog the package ships (generated from the Specs registry): its free functions are free.
        $this->app->singletonIf(CapabilityCatalog::class, static fn (): CapabilityCatalog => CapabilityCatalog::shipped());
        $this->app->singleton(SiteLicensing::class, static function (Application $app): SiteLicensing {
            $config = $app->make(ConfigRepository::class);
            $free = $config->get('larena-licensing.free_capabilities', []);

            return new SiteLicensing(
                $app->make(SnapshotVerifier::class),
                $app->make(SnapshotStore::class),
                $app->make(DevelopmentAccess::class),
                self::siteHost($config),
                array_values(array_unique([
                    ...$app->make(CapabilityCatalog::class)->freeIds(),
                    ...array_values(array_filter(is_array($free) ? $free : [], 'is_string')),
                ])),
            );
        });
        // Every function of the catalog with the site's decision for it, for screens («Возможности сайта»).
        $this->app->singleton(SiteCapabilities::class, static fn (Application $app): SiteCapabilities
            => new SiteCapabilities($app->make(CapabilityCatalog::class), $app->make(SiteLicensing::class)));
        // The default answer of every LicensingRuntime caller: the site's signed snapshot. A site may bind its own.
        $this->app->singletonIf(LicensingRuntime::class, static fn (Application $app): LicensingRuntime => new SiteLicensingRuntime($app->make(SiteLicensing::class)));
        // The site key the admin enters, kept outside the web root; the configuration's key stands over it.
        $this->app->singletonIf(SiteKeyStore::class, static function (Application $app): SiteKeyStore {
            $path = $app->make(ConfigRepository::class)->get('larena-licensing.site_key_path');

            return new SiteKeyStore(is_string($path) && $path !== '' ? $path : $app->storagePath('app/larena/licensing/site-key'));
        });
        $this->app->bind(SnapshotSource::class, static fn (Application $app): SnapshotSource => self::source($app, null));

        // «Лицензия и обновления» in the admin: the licence, its actions, coupons and the addon list.
        $this->app->bind(SiteLicence::class, static fn (Application $app): SiteLicence => new SiteLicence(
            $app->make(SiteLicensing::class),
            $app->make(SiteKeyStore::class),
            static fn (?string $key): SnapshotSource => self::source($app, $key),
            self::configuredKey($app->make(ConfigRepository::class)),
        ));
        $this->app->bind(SiteCoupon::class, static fn (Application $app): SiteCoupon => new SiteCoupon(
            $app->make(SiteLicensing::class),
            static fn (): SnapshotSource => $app->make(SnapshotSource::class),
        ));
        $this->app->bind(SiteAddons::class, static function (Application $app): SiteAddons {
            $config = $app->make(ConfigRepository::class);
            $path = $config->get('larena-licensing.addons_path');
            $shop = $config->get('larena-licensing.shop_url');

            return new SiteAddons(
                $app->make(SiteLicensing::class),
                $app->make(CapabilityCatalog::class),
                static fn (): SnapshotSource => $app->make(SnapshotSource::class),
                is_string($path) && $path !== '' ? $path : $app->storagePath('app/larena/licensing/addons.json'),
                static fn (string $package): bool => class_exists(\Composer\InstalledVersions::class) && \Composer\InstalledVersions::isInstalled($package),
                is_string($shop) && str_starts_with(strtolower(trim($shop)), 'https://') ? trim($shop) : null,
            );
        });

        // «Попробовать бесплатно»: the same sources, settings and site key as the refresh; the admin calls it too.
        $this->app->bind(SiteTrial::class, static fn (Application $app): SiteTrial => new SiteTrial(
            $app->make(SiteLicensing::class),
            $app->make(CapabilityCatalog::class),
            static fn (): SnapshotSource => $app->make(SnapshotSource::class),
        ));

        // The licence in the admin's alert bar, where the admin has one (owner decision 2026-11-05).
        if (interface_exists(\Larena\Admin\Contracts\AdminAlertSource::class) && class_exists(\Larena\Admin\Alerts\AdminAlerts::class)) {
            $app = $this->app;
            $this->callAfterResolving(\Larena\Admin\Alerts\AdminAlerts::class, static function (\Larena\Admin\Alerts\AdminAlerts $alerts) use ($app): void {
                $alerts->register('larena/licensing', new LicensingAdminAlertSource(
                    static fn (): SiteLicensing => $app->make(SiteLicensing::class),
                    self::siteKey($app) !== null,
                    static function (\Illuminate\Http\Request $request) use ($app): bool {
                        if (!class_exists(\Larena\Access\Runtime\AccessOperationAuthorizer::class) || !$app->bound(\Larena\Access\Runtime\AccessOperationAuthorizer::class)) {
                            return false;
                        }
                        try {
                            return $app->make(\Larena\Access\Runtime\AccessOperationAuthorizer::class)->authorize($request, 'admin.site_settings.write')->isAllowed();
                        } catch (\Throwable) {
                            return false;
                        }
                    },
                    static fn (string $key, array $replace): string => (string) $app->make('translator')->get('larena-licensing::licensing.alerts.' . $key, $replace),
                ));
            });
        }
    }

    public function boot(): void
    {
        $this->loadTranslationsFrom(__DIR__ . '/../../resources/lang', 'larena-licensing');
        $this->publishes([__DIR__ . '/../../config/larena-licensing.php' => $this->app->configPath('larena-licensing.php')], 'larena-licensing-config');

        if ($this->app->runningInConsole()) {
            $this->commands([LicensingRefreshCommand::class, LicensingLoadCommand::class, LicensingStatusCommand::class, LicensingTrialCommand::class]);
        }

        // A key entered in the admin serves the update client too (downloads of closed addons), unless `.env` sets one.
        $config = $this->app->make(ConfigRepository::class);
        $siteKey = self::siteKey($this->app);
        if ($siteKey !== null && self::configuredKey($config) === null) {
            $config->set('update-client.site_key', $siteKey);
            $config->set(self::KEY_FROM_ADMIN, true);
        }

        // A daily refresh while the site has a site key; without one there is nothing to ask.
        $at = $config->get('larena-licensing.refresh.daily_at', '04:17');
        if ($siteKey !== null && class_exists(\Illuminate\Console\Scheduling\Schedule::class)) {
            $this->callAfterResolving(\Illuminate\Console\Scheduling\Schedule::class, static function (\Illuminate\Console\Scheduling\Schedule $schedule) use ($at): void {
                $schedule->command('larena:licensing:refresh')
                    ->dailyAt(is_string($at) && preg_match('/\A([01]\d|2[0-3]):[0-5]\d\z/D', $at) === 1 ? $at : '04:17')
                    ->withoutOverlapping();
            });
        }
    }

    /** The update sources with the site key: `$key` when given (a key just entered), else the site's own. */
    private static function source(Application $app, #[\SensitiveParameter] ?string $key): SnapshotSource
    {
        $config = $app->make(ConfigRepository::class);
        $sources = $config->get('update-client.sources', []);
        $download = (array) $config->get('update-client.download', []);

        return new SnapshotSource(
            array_values(array_filter(is_array($sources) ? $sources : [], 'is_string')),
            $key ?? self::siteKey($app),
            SnapshotSource::curl(),
            is_numeric($download['timeout'] ?? null) ? (int) $download['timeout'] : 60,
            is_numeric($download['connect_timeout'] ?? null) ? (int) $download['connect_timeout'] : 15,
        );
    }

    /** The site key of the configuration (`LARENA_UPDATE_SITE_KEY`), or null. */
    private static function configuredKey(ConfigRepository $config): ?string
    {
        $key = $config->get('update-client.site_key');
        if ($config->get(self::KEY_FROM_ADMIN) === true) {
            return null;
        }

        return is_string($key) && trim($key) !== '' ? trim($key) : null;
    }

    /** The site key: the configuration's, else the one entered in the admin; null without either. */
    private static function siteKey(Application $app): ?string
    {
        // A key entered just now is read from its file, not from the copy made at boot.
        return self::configuredKey($app->make(ConfigRepository::class)) ?? $app->make(SiteKeyStore::class)->read();
    }

    private static function product(ConfigRepository $config): string
    {
        $product = $config->get('larena-licensing.product');

        return is_string($product) && preg_match('/\A[a-z][a-z0-9-]{0,63}\z/D', trim($product)) === 1 ? trim($product) : 'larena';
    }

    /** The site's own configured address (never a request's Host header). */
    private static function siteHost(ConfigRepository $config): string
    {
        $url = $config->get('larena-licensing.site_url');
        $url = is_string($url) && trim($url) !== '' ? $url : (string) $config->get('app.url', '');
        $host = parse_url(trim($url), PHP_URL_HOST);

        return is_string($host) ? SnapshotVerifier::host(trim($host, '[]')) : '';
    }
}
