<?php

declare(strict_types=1);

namespace Larena\Licensing\Laravel;

use Closure;
use Illuminate\Http\Request;
use Larena\Admin\Alerts\AdminAlert;
use Larena\Admin\Alerts\AdminAlertLevel;
use Larena\Admin\Contracts\AdminAlertSource;
use Larena\Licensing\Site\LicensingAdminSignal;
use Larena\Licensing\Site\SiteLicensing;

/**
 * The licence in the admin's alert bar (owner decision 2026-11-05), for people who may change the site's settings:
 * «копия для разработки», «не удалось проверить лицензию», «лицензия закончилась», a refused snapshot. Read from the
 * kept snapshot only; nothing is fetched on a page.
 */
final readonly class LicensingAdminAlertSource implements AdminAlertSource
{
    /**
     * @param Closure(): SiteLicensing $licensing
     * @param Closure(Request): bool $mayAct
     * @param Closure(string, array<string,string>): string $text
     */
    public function __construct(
        private Closure $licensing,
        private bool $hasSiteKey,
        private Closure $mayAct,
        private Closure $text,
    ) {
    }

    public function alerts(Request $request): array
    {
        if (!($this->mayAct)($request)) {
            return [];
        }
        $signal = LicensingAdminSignal::for(($this->licensing)()->status(), $this->hasSiteKey);
        if ($signal === null) {
            return [];
        }

        return [new AdminAlert(
            $signal->id,
            $signal->level === 'info' ? AdminAlertLevel::Info : AdminAlertLevel::Warning,
            ($this->text)($signal->textKey, $signal->replace),
            ($this->text)('source', []),
        )];
    }
}
