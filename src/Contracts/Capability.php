<?php

declare(strict_types=1);

namespace Larena\Licensing\Contracts;

interface Capability
{
    public function key(): string;

    public function package(): string;

    public function isFreeByDefault(): bool;

    public function failClosedWhenUnknown(): bool;
}
