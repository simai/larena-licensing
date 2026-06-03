<?php

declare(strict_types=1);

namespace Larena\Licensing\Contracts;

use DateTimeImmutable;
use Larena\Licensing\Enums\EntitlementStatus;

interface EntitlementSnapshot
{
    public function snapshotId(): string;

    public function siteId(): string;

    public function status(): EntitlementStatus;

    public function issuedAt(): DateTimeImmutable;

    public function expiresAt(): ?DateTimeImmutable;

    public function signatureId(): string;

    /**
     * @return list<string>
     */
    public function enabledCapabilities(): array;
}
