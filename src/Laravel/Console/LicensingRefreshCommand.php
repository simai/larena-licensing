<?php

declare(strict_types=1);

namespace Larena\Licensing\Laravel\Console;

use Illuminate\Console\Command;
use Larena\Licensing\Site\SiteLicensing;
use Larena\Licensing\Site\SnapshotRefused;
use Larena\Licensing\Site\SnapshotSource;
use Larena\Licensing\Site\SnapshotStore;

/**
 * `larena:licensing:refresh`: asks the update source for this site's signed snapshot with the site key and keeps it
 * when it verifies. Prints the state and reason codes only — never the key, the snapshot or the signature. A failure
 * keeps the last good snapshot.
 */
final class LicensingRefreshCommand extends Command
{
    protected $signature = 'larena:licensing:refresh';

    protected $description = 'Fetch the signed entitlement snapshot of this site and keep it.';

    public function handle(SiteLicensing $licensing, SnapshotSource $source): int
    {
        try {
            $snapshot = $licensing->accept($source->fetch(), SnapshotStore::REFRESH);
        } catch (SnapshotRefused $refused) {
            $this->error('licensing_refresh_refused_snapshot: ' . $refused->reason());

            return self::FAILURE;
        } catch (\RuntimeException $failure) {
            try {
                $licensing->markSeen();
            } catch (\RuntimeException) {
                // The kept snapshot stays as it is; the refresh failure is what matters here.
            }
            $this->error($failure->getMessage());

            return self::FAILURE;
        }
        $status = $licensing->status();
        $this->info('state=' . $status->state->value . ' reason=' . $status->reasonCode . ' licence=' . $snapshot->licenceStatus
            . ' capabilities=' . count($snapshot->capabilities));

        return self::SUCCESS;
    }
}
