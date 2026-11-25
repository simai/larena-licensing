<?php

declare(strict_types=1);

namespace Larena\Licensing\Site;

use DateTimeImmutable;

final readonly class KeptSnapshot
{
    /** @param array{entitlement: string, signature: string} $envelope */
    public function __construct(
        public array $envelope,
        public string $origin,
        public DateTimeImmutable $keptAt,
        public DateTimeImmutable $seenAt,
    ) {
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['origin' => $this->origin, 'kept_at' => $this->keptAt->format(DATE_ATOM)];
    }
}
