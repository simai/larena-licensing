<?php

declare(strict_types=1);

namespace Larena\Licensing\Laravel\Console;

use Illuminate\Console\Command;
use Larena\Licensing\Site\SiteTrial;
use Larena\Licensing\Site\TrialResult;

/**
 * `larena:licensing:trial <capability>`: «Попробовать бесплатно» from the console. Asks the update server for a trial
 * of one function with the site key, keeps the signed snapshot it answers when it verifies, and prints the outcome as
 * codes and the reason in words — never the key, the snapshot or the signature. A test address needs no trial: it says
 * so and asks nothing.
 */
final class LicensingTrialCommand extends Command
{
    protected $signature = 'larena:licensing:trial {capability : the function, e.g. larena.capability.chat.base}';

    protected $description = 'Ask for a free trial of one paid function and keep the signed snapshot it gives.';

    public function handle(SiteTrial $trial): int
    {
        $capability = $this->argument('capability');
        $result = $trial->request(is_string($capability) ? trim($capability) : '');
        $line = 'status=' . $result->status . ' capability=' . $result->capability . ' reason=' . $result->reasonCode
            . ($result->endsAt !== null ? ' ends_at=' . $result->endsAt->format(DATE_ATOM) : '')
            . ($result->detail !== '' ? ' detail=' . $result->detail : '');
        $words = $this->laravel->make('translator')->get($result->wordsKey(), ['date' => $result->endsAt?->format('Y-m-d') ?? '']);
        $words = is_string($words) && $words !== $result->wordsKey() ? $words : '';
        if ($result->status === TrialResult::REFUSED) {
            $this->error($line);
            if ($words !== '') {
                $this->line($words);
            }

            return self::FAILURE;
        }
        $this->info($line);
        if ($words !== '') {
            $this->line($words);
        }

        return self::SUCCESS;
    }
}
