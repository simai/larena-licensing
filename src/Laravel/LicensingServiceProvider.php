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
use Larena\Licensing\Site\DevelopmentAccess;
use Larena\Licensing\Site\FileSnapshotStore;
use Larena\Licensing\Site\SiteLicensing;
use Larena\Licensing\Site\SiteLicensingRuntime;
use Larena\Licensing\Site\SnapshotSource;
use Larena\Licensing\Site\SnapshotStore;
use Larena\Licensing\Site\SnapshotVerifier;
use Larena\Licensing\Site\TestAddresses;
use Larena\Licensing\Site\TrustedKeys;

/**
 * Licensing in a Larena site (owner decisions 2026-11-25): configuration `larena-licensing`, the site's signed
 * snapshot as the default {@see LicensingRuntime} and as {@see SiteLicensing}, the shipped capability catalog and its
 * read model {@see SiteCapabilities}, the commands
 * `larena:licensing:refresh|load|status`, a daily refresh through the scheduler, and the licence in the admin's
 * alert bar where the admin has one. No table, no route, nothing on the public site.
 */
final class LicensingServiceProvider extends ServiceProvider
{
    /** The public entitlement keys the distribution ships: the site's `config/larena-entitlement-keys.php`. */
    public const string ENTITLEMENT_KEYS_CONFIG = 'larena-entitlement-keys';

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
        $this->app->bind(SnapshotSource::class, static function (Application $app): SnapshotSource {
            $config = $app->make(ConfigRepository::class);
            $sources = $config->get('update-client.sources', []);
            $siteKey = $config->get('update-client.site_key');
            $download = (array) $config->get('update-client.download', []);

            return new SnapshotSource(
                array_values(array_filter(is_array($sources) ? $sources : [], 'is_string')),
                is_string($siteKey) ? $siteKey : null,
                SnapshotSource::curl(),
                is_numeric($download['timeout'] ?? null) ? (int) $download['timeout'] : 60,
                is_numeric($download['connect_timeout'] ?? null) ? (int) $download['connect_timeout'] : 15,
            );
        });

        // The licence in the admin's alert bar, where the admin has one (owner decision 2026-11-05).
        if (interface_exists(\Larena\Admin\Contracts\AdminAlertSource::class) && class_exists(\Larena\Admin\Alerts\AdminAlerts::class)) {
            $app = $this->app;
            $this->callAfterResolving(\Larena\Admin\Alerts\AdminAlerts::class, static function (\Larena\Admin\Alerts\AdminAlerts $alerts) use ($app): void {
                $siteKey = $app->make(ConfigRepository::class)->get('update-client.site_key');
                $alerts->register('larena/licensing', new LicensingAdminAlertSource(
                    static fn (): SiteLicensing => $app->make(SiteLicensing::class),
                    is_string($siteKey) && trim($siteKey) !== '',
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
            $this->commands([LicensingRefreshCommand::class, LicensingLoadCommand::class, LicensingStatusCommand::class]);
        }

        // A daily refresh while the site has a site key; without one there is nothing to ask.
        $config = $this->app->make(ConfigRepository::class);
        $siteKey = $config->get('update-client.site_key');
        $at = $config->get('larena-licensing.refresh.daily_at', '04:17');
        if (is_string($siteKey) && trim($siteKey) !== '' && class_exists(\Illuminate\Console\Scheduling\Schedule::class)) {
            $this->callAfterResolving(\Illuminate\Console\Scheduling\Schedule::class, static function (\Illuminate\Console\Scheduling\Schedule $schedule) use ($at): void {
                $schedule->command('larena:licensing:refresh')
                    ->dailyAt(is_string($at) && preg_match('/\A([01]\d|2[0-3]):[0-5]\d\z/D', $at) === 1 ? $at : '04:17')
                    ->withoutOverlapping();
            });
        }
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
