<?php

declare(strict_types=1);

namespace Larena\Licensing\Site;

use Larena\Licensing\Contracts\Capability;
use Larena\Licensing\Contracts\EntitlementSnapshot;
use Larena\Licensing\Contracts\LicenseDecision;
use Larena\Licensing\Contracts\LicensingRuntime;
use Larena\Licensing\Runtime\RuntimeLicenseDecision;
use Larena\Licensing\Runtime\SnapshotLicensingRuntime;

/**
 * The {@see LicensingRuntime} contract answered by the site's signed snapshot ({@see SiteLicensing}): the default
 * binding of a Larena site. A caller that passes its own snapshot keeps the old answer of
 * {@see SnapshotLicensingRuntime} for it; a caller that passes none (larena/auth) gets the site's.
 */
final readonly class SiteLicensingRuntime implements LicensingRuntime
{
    public function __construct(private SiteLicensing $site, private SnapshotLicensingRuntime $explicit = new SnapshotLicensingRuntime())
    {
    }

    public function decide(Capability $capability, ?EntitlementSnapshot $snapshot = null): LicenseDecision
    {
        if ($snapshot !== null || $capability->isFreeByDefault()) {
            return $this->explicit->decide($capability, $snapshot);
        }
        $decision = $this->site->decide($capability->key());
        if ($decision->allowed) {
            return RuntimeLicenseDecision::allowed($capability->key(), $decision->reasonCode);
        }

        return $capability->failClosedWhenUnknown()
            ? RuntimeLicenseDecision::locked($capability->key(), $decision->reasonCode)
            : RuntimeLicenseDecision::denied($capability->key(), $decision->reasonCode);
    }
}
