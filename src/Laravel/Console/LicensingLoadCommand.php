<?php

declare(strict_types=1);

namespace Larena\Licensing\Laravel\Console;

use Illuminate\Console\Command;
use Larena\Licensing\Site\SiteLicensing;
use Larena\Licensing\Site\SnapshotRefused;
use Larena\Licensing\Site\SnapshotStore;
use Larena\Licensing\Site\SnapshotVerifier;

/**
 * `larena:licensing:load <file>`: a site without network takes its snapshot from a file (downloaded from the
 * customer account). The file must verify, name this site's address and not be older than the kept snapshot; it may
 * stay fresh up to a year. Prints codes only.
 */
final class LicensingLoadCommand extends Command
{
    protected $signature = 'larena:licensing:load {file : the snapshot file (the signed envelope, JSON)}';

    protected $description = 'Load a signed entitlement snapshot from a file (sites without network).';

    public function handle(SiteLicensing $licensing): int
    {
        $path = (string) $this->argument('file');
        if ($path === '' || is_link($path) || !is_file($path) || (int) filesize($path) > SnapshotVerifier::MAX_BYTES) {
            $this->error('licensing_file_unreadable');

            return self::FAILURE;
        }
        $bytes = @file_get_contents($path, false, null, 0, SnapshotVerifier::MAX_BYTES + 1);
        try {
            $snapshot = $licensing->accept(is_string($bytes) ? $bytes : '', SnapshotStore::FILE);
        } catch (SnapshotRefused $refused) {
            $this->error($refused->reason());

            return self::FAILURE;
        }
        $status = $licensing->status();
        $this->info('state=' . $status->state->value . ' reason=' . $status->reasonCode . ' licence=' . $snapshot->licenceStatus
            . ' fresh_until=' . $snapshot->freshUntil()->format(DATE_ATOM));

        return self::SUCCESS;
    }
}
