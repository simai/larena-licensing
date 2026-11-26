<?php

declare(strict_types=1);

namespace Larena\Licensing\Site;

use Closure;
use DateTimeImmutable;
use RuntimeException;

/**
 * A coupon entered on «Дополнения» (owner 2026-11-26: sales through an external shop, CRM or invoice, plus coupons),
 * in the shape Larena Server proposed on 2026-11-26 (msg-2cbd7406bf21401f811f), behind {@see SnapshotSource::redeem()}:
 *
 * - `POST <update server>/r/<product>[/<channel>]/coupon`, the site key as `Authorization: Bearer`, JSON `{"code": "<coupon>"}`
 *   (the server accepts spaces and lower case);
 * - 200: the same signed envelope as `/entitlement`, already with what the coupon gave (an addon's capabilities and
 *   `updates_until[<package>]`, or the main licence), plus an unsigned note `coupon: {mode: granted|renewed, offer, ends_at}`;
 * - otherwise `{"error": code}`, one of {@see SERVER_REFUSALS}.
 *
 * The envelope is verified exactly like a refresh and kept only when it verifies; the unsigned note only feeds the words.
 * Answered here without a request: a coupon that cannot be one (`coupon_malformed`), no site key (`site_key_required`).
 * Unlike a trial, a coupon is redeemed on a test address too: it belongs to the site key's licence, which the live site
 * shares. The coupon is never written anywhere: not in a reason, the audit or a log.
 */
final readonly class SiteCoupon
{
    /** What a coupon may look like before it is sent: printable, 4 to 128 characters (spaces allowed). */
    public const string FORMAT = '/\A[\x21-\x7e](?:[\x20-\x7e]{2,126})[\x21-\x7e]\z/D';

    /** What the server may answer instead of a snapshot. */
    public const array SERVER_REFUSALS = [
        'site_key_required', 'site_key_unknown', 'product_unknown', 'coupon_unknown', 'coupon_already_redeemed',
        'coupon_product_mismatch', 'license_revoked', 'rate_limited', 'registration_unavailable', 'license_signature_invalid',
    ];

    /** Every reason a coupon can end with, each with words in ru and en. */
    public const array REASONS = [
        'coupon_granted', 'coupon_renewed', 'coupon_malformed', 'licensing_source_missing', 'coupon_request_failed',
        'coupon_answer_refused', ...self::SERVER_REFUSALS,
    ];

    /** @param Closure(): SnapshotSource $source */
    public function __construct(private SiteLicensing $licensing, private Closure $source)
    {
    }

    public function redeem(#[\SensitiveParameter] string $coupon): LicenceActionResult
    {
        $coupon = trim($coupon);
        if (preg_match(self::FORMAT, $coupon) !== 1) {
            return new LicenceActionResult(false, 'coupon_malformed');
        }
        $source = ($this->source)();
        if (!$source->hasSiteKey()) {
            return new LicenceActionResult(false, 'site_key_required');
        }
        try {
            $answer = $source->redeem($coupon);
        } catch (CouponRefused $refused) {
            return new LicenceActionResult(false, $refused->reason(), true);
        } catch (RuntimeException $failure) {
            return new LicenceActionResult(false, $failure->getMessage() === 'licensing_source_missing' ? 'licensing_source_missing' : 'coupon_request_failed', true);
        }
        try {
            $this->licensing->accept($answer, SnapshotStore::REFRESH);
        } catch (SnapshotRefused $refused) {
            return new LicenceActionResult(false, 'coupon_answer_refused', true, $refused->reason());
        } catch (RuntimeException) {
            return new LicenceActionResult(false, 'coupon_answer_refused', true, 'licensing_store_unwritable');
        }
        $note = self::note($answer);

        return new LicenceActionResult(true, $note['mode'] === 'renewed' ? 'coupon_renewed' : 'coupon_granted', true, '', $note['offer'], $note['ends_at']);
    }

    /**
     * The server's unsigned `coupon` note, read only for the words: mode, offer and end (an invalid part is dropped).
     *
     * @return array{mode: string, offer: ?string, ends_at: ?DateTimeImmutable}
     */
    private static function note(string $answer): array
    {
        $data = json_decode($answer, true, 8);
        $note = is_array($data) && is_array($data['coupon'] ?? null) ? $data['coupon'] : [];
        $offer = is_string($note['offer'] ?? null) && preg_match('/\A[a-z0-9][a-z0-9._-]{0,63}\z/D', $note['offer']) === 1 ? $note['offer'] : null;
        $ends = is_string($note['ends_at'] ?? null) ? DateTimeImmutable::createFromFormat(DATE_ATOM, $note['ends_at']) : false;

        return ['mode' => ($note['mode'] ?? null) === 'renewed' ? 'renewed' : 'granted', 'offer' => $offer, 'ends_at' => $ends === false ? null : $ends];
    }
}
