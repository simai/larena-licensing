<?php

declare(strict_types=1);

namespace Larena\Licensing\Contracts;

interface Capability
{
    public function id(): string;

    public function packageId(): string;

    public function edition(): string;

    public function paid(): bool;

    public function defaultFallback(): string;
}
