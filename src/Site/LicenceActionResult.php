<?php

declare(strict_types=1);

namespace Larena\Licensing\Site;

/**
 * What one licence action of the admin came to («Обновить сейчас», a snapshot file, a site key, a coupon): done or
 * not, with a reason code said in words by `larena-licensing::licensing.licence.reasons.<code>`. `requested` tells
 * whether a server was asked; `detail` is the verifier's own code for an answer that did not verify. Nothing here
 * carries the key, the coupon, a signature or the snapshot.
 */
final readonly class LicenceActionResult
{
    /** The lang key holding a reason's words in ru and en. */
    public const string WORDS = 'larena-licensing::licensing.licence.reasons.';

    public function __construct(
        public bool $done,
        public string $reasonCode,
        public bool $requested = false,
        public string $detail = '',
        /** A coupon's offer and end, from the server's unsigned note (words only). */
        public ?string $offer = null,
        public ?\DateTimeImmutable $endsAt = null,
    ) {
    }

    public function wordsKey(): string
    {
        return self::WORDS . $this->reasonCode;
    }

    /** @return array{done: bool, reason_code: string, requested: bool, detail: string, offer: ?string, ends_at: ?string} */
    public function toArray(): array
    {
        return ['done' => $this->done, 'reason_code' => $this->reasonCode, 'requested' => $this->requested, 'detail' => $this->detail,
            'offer' => $this->offer, 'ends_at' => $this->endsAt?->format(DATE_ATOM)];
    }
}
