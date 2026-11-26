<?php

declare(strict_types=1);

namespace Larena\Licensing\Site;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * What «Попробовать бесплатно» came to for one capability ({@see SiteTrial}):
 *
 * - `started`: the server gave the trial, its signed snapshot verified and is kept; `endsAt` is the snapshot's
 *   `fresh_until` (the server keeps it within the trial end);
 * - `not_needed`: the site has a test address (`development_address`): every function is open, nothing was asked;
 * - `refused`: no trial, with the reason code (said in words by `larena-licensing::licensing.trial.reasons.<code>`).
 *
 * `requested` tells whether the server was asked at all. Nothing here carries a key, a signature or the site key.
 */
final readonly class TrialResult
{
    public const string STARTED = 'started';
    public const string NOT_NEEDED = 'not_needed';
    public const string REFUSED = 'refused';

    /** The lang key holding a reason's words in ru and en. */
    public const string WORDS = 'larena-licensing::licensing.trial.reasons.';

    public function __construct(
        public string $status,
        public string $capability,
        public string $reasonCode,
        public bool $requested = false,
        public ?DateTimeImmutable $endsAt = null,
        /** For an answer that did not verify: the verifier's own code (`licensing_signature_invalid`, …). */
        public string $detail = '',
    ) {
    }

    public function started(): bool
    {
        return $this->status === self::STARTED;
    }

    /** The lang key of this result's words; a started trial with an end date says it (`:date`). */
    public function wordsKey(): string
    {
        return $this->started() && $this->endsAt !== null ? 'larena-licensing::licensing.trial.started' : self::WORDS . $this->reasonCode;
    }

    /** @return array{status: string, capability: string, reason_code: string, requested: bool, ends_at: ?string, detail: string} */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'capability' => $this->capability,
            'reason_code' => $this->reasonCode,
            'requested' => $this->requested,
            'ends_at' => $this->endsAt?->format(DateTimeInterface::ATOM),
            'detail' => $this->detail,
        ];
    }
}
