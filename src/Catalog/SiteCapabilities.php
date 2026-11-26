<?php

declare(strict_types=1);

namespace Larena\Licensing\Catalog;

use Larena\Licensing\Site\LicensingState;
use Larena\Licensing\Site\SiteLicensing;

/**
 * The read model a screen shows (owner decision 2026-11-25, «Возможности сайта»): every function of the catalog with
 * its words, package, tier and trial, and the site's decision for it now:
 *
 * - `allowed`: free, or the site's licence lists it;
 * - `development`: a paid function open because the site has a test address;
 * - `needs_licence`: paid, and the licence does not allow it.
 *
 * `trial_until` is the end of a trial («Попробовать бесплатно») that allows a paid function now, else null: the
 * snapshot's `trials` end for that function, else (licence status `trial`) its `fresh_until`. `trial_days` is how long
 * a trial of the function lasts, from the Specs registry (null when it has no trial).
 */
final readonly class SiteCapabilities
{
    public const string ALLOWED = 'allowed';
    public const string DEVELOPMENT = 'development';
    public const string NEEDS_LICENCE = 'needs_licence';

    public function __construct(private CapabilityCatalog $catalog, private SiteLicensing $licensing)
    {
    }

    public function catalog(): CapabilityCatalog
    {
        return $this->catalog;
    }

    /** @return list<array{id:string, title_key:string, description_key:string, package:string, kind:string, tier:string, free:bool, trial_eligible:bool, trial_days:?int, area:string, always_on:bool, requires:list<string>, decision:string, reason_code:string, trial_until:?string}> */
    public function all(): array
    {
        return array_map($this->entry(...), $this->catalog->all());
    }

    /** @return array{id:string, title_key:string, description_key:string, package:string, kind:string, tier:string, free:bool, trial_eligible:bool, trial_days:?int, area:string, always_on:bool, requires:list<string>, decision:string, reason_code:string, trial_until:?string}|null */
    public function get(string $id): ?array
    {
        $capability = $this->catalog->get($id);

        return $capability === null ? null : $this->entry($capability);
    }

    /** Whether the site may switch the function on now. */
    public function allows(string $id): bool
    {
        $entry = $this->get($id);

        return $entry !== null && $entry['decision'] !== self::NEEDS_LICENCE;
    }

    /** @return array{id:string, title_key:string, description_key:string, package:string, kind:string, tier:string, free:bool, trial_eligible:bool, trial_days:?int, area:string, always_on:bool, requires:list<string>, decision:string, reason_code:string, trial_until:?string} */
    private function entry(CatalogCapability $capability): array
    {
        $free = $this->licensing->isFree($capability->id);
        if ($free) {
            [$decision, $reason] = [self::ALLOWED, 'capability_free'];
        } else {
            $answer = $this->licensing->decide($capability->id);
            $decision = match (true) {
                $answer->state === LicensingState::Development => self::DEVELOPMENT,
                $answer->allowed => self::ALLOWED,
                default => self::NEEDS_LICENCE,
            };
            $reason = $answer->reasonCode;
        }
        $trialUntil = !$free && $decision === self::ALLOWED ? $this->licensing->trialUntil($capability->id) : null;

        return [
            'id' => $capability->id,
            'title_key' => $capability->titleKey,
            'description_key' => $capability->descriptionKey,
            'package' => $capability->package,
            'kind' => $capability->kind,
            'tier' => $capability->tier,
            'free' => $free,
            'trial_eligible' => $capability->trialEligible,
            'trial_days' => $capability->trialDays,
            'area' => $capability->area,
            'always_on' => $capability->alwaysOn,
            'requires' => $capability->requires,
            'decision' => $decision,
            'reason_code' => $reason,
            'trial_until' => $trialUntil?->format(\DateTimeInterface::ATOM),
        ];
    }
}
