<?php

declare(strict_types=1);

namespace Larena\Licensing\Contracts;

use Larena\Licensing\Enums\EntitlementStatus;

interface EntitlementSnapshot
{
    public function status(): EntitlementStatus;

    public function keyId(): string;

    public function signature(): string;

    /**
     * @return list<string>
     */
    public function capabilityKeys(): array;

    public function expiresAt(): ?\DateTimeImmutable;
}
