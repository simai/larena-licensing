<?php

declare(strict_types=1);

namespace Larena\Licensing\Site;

use Closure;
use Larena\Licensing\Catalog\CapabilityCatalog;
use RuntimeException;

/**
 * «Попробовать бесплатно» for one capability (Larena Server: `POST <update server>/r/<product>[/<channel>]/trial`):
 * the same update sources, settings and site key as `larena:licensing:refresh`, JSON `{"capability": "<id>"}`. The
 * answer is the same signed envelope as `/entitlement`; it is verified exactly like a refresh and kept as the last good
 * snapshot only when it verifies. Then {@see SiteLicensing::allows()} says yes for the capability.
 *
 * Asked only when it makes sense, otherwise answered here without a request:
 * - a test address needs no trial (`not_needed`, `development_address`);
 * - a function the catalog does not know (`capability_unknown`), a free one (`capability_free`), one the catalog does
 *   not mark `trial_eligible` (`trial_not_eligible`), one the licence already allows (`capability_licensed`);
 * - no site key (`site_key_required`).
 *
 * The server's refusals ({@see SERVER_REFUSALS}) come back with their own code; a network failure is
 * `trial_request_failed`, no update server among the sources `licensing_source_missing`, an answer that does not
 * verify `trial_answer_refused` (the verifier's code in `detail`), a verified snapshot without the capability
 * `trial_not_granted`.
 */
final readonly class SiteTrial
{
    /** What the server may answer instead of a snapshot (`{"error": code}`). */
    public const array SERVER_REFUSALS = [
        'site_key_required', 'site_key_unknown', 'capability_unknown', 'trial_not_eligible',
        'trial_used', 'capability_licensed', 'registration_unavailable', 'license_signature_invalid',
    ];

    /** Every reason a trial can end with, each with words in ru and en. */
    public const array REASONS = [
        'trial_started', TestAddresses::REASON, 'capability_free', 'licensing_source_missing', 'trial_request_failed',
        'trial_answer_refused', 'trial_not_granted', ...self::SERVER_REFUSALS,
    ];

    /** @param Closure(): SnapshotSource $source the update sources with the site key, made when asked */
    public function __construct(
        private SiteLicensing $licensing,
        private CapabilityCatalog $catalog,
        private Closure $source,
    ) {
    }

    public function request(string $capability): TrialResult
    {
        $refuse = static fn (string $reason, bool $requested = false, string $detail = ''): TrialResult
            => new TrialResult(TrialResult::REFUSED, $capability, $reason, $requested, null, $detail);
        $entry = $this->catalog->get($capability);
        if ($entry === null) {
            return $refuse('capability_unknown');
        }
        if ($this->licensing->status()->state === LicensingState::Development) {
            return new TrialResult(TrialResult::NOT_NEEDED, $capability, TestAddresses::REASON);
        }
        if ($entry->isFree() || $this->licensing->isFree($capability)) {
            return $refuse('capability_free');
        }
        if (!$entry->trialEligible) {
            return $refuse('trial_not_eligible');
        }
        if ($this->licensing->allows($capability)) {
            return $refuse('capability_licensed');
        }
        $source = ($this->source)();
        if (!$source->hasSiteKey()) {
            return $refuse('site_key_required');
        }

        try {
            $envelope = $source->trial($capability);
        } catch (TrialRefused $refused) {
            return $refuse($refused->reason(), true);
        } catch (RuntimeException $failure) {
            return $refuse($failure->getMessage() === 'licensing_source_missing' ? 'licensing_source_missing' : 'trial_request_failed', true);
        }
        try {
            $snapshot = $this->licensing->accept($envelope, SnapshotStore::REFRESH);
        } catch (SnapshotRefused $refused) {
            return $refuse('trial_answer_refused', true, $refused->reason());
        } catch (RuntimeException) {
            return $refuse('trial_answer_refused', true, 'licensing_store_unwritable');
        }
        if (!in_array($capability, $snapshot->capabilities, true) || !$this->licensing->allows($capability)) {
            return $refuse('trial_not_granted', true);
        }

        return new TrialResult(TrialResult::STARTED, $capability, 'trial_started', true, $snapshot->declaredFreshUntil ?? $snapshot->freshUntil());
    }
}
