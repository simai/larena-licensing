<?php

declare(strict_types=1);

namespace Larena\Licensing\Runtime;

use InvalidArgumentException;
use Larena\Licensing\Contracts\Capability;

final readonly class StaticCapability implements Capability
{
    public function __construct(
        private string $key,
        private string $package,
        private bool $freeByDefault = false,
        private bool $failClosedWhenUnknown = true,
    ) {
        foreach ([
            'key' => $this->key,
            'package' => $this->package,
        ] as $field => $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException("Capability {$field} must not be empty.");
            }
        }
    }

    public function key(): string
    {
        return $this->key;
    }

    public function package(): string
    {
        return $this->package;
    }

    public function isFreeByDefault(): bool
    {
        return $this->freeByDefault;
    }

    public function failClosedWhenUnknown(): bool
    {
        return $this->failClosedWhenUnknown;
    }
}
