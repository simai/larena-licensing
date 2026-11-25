<?php

declare(strict_types=1);

namespace Larena\Licensing\Laravel\Console;

use Illuminate\Console\Command;
use Larena\Licensing\Site\SiteLicensing;

/** `larena:licensing:status`: the state, the reason and the dates as JSON, without keys or signatures. */
final class LicensingStatusCommand extends Command
{
    protected $signature = 'larena:licensing:status';

    protected $description = 'Show the licence state of this site.';

    public function handle(SiteLicensing $licensing): int
    {
        $this->line((string) json_encode($licensing->status()->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
