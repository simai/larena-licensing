<?php

declare(strict_types=1);

require_once __DIR__ . '/../../src/Contracts/Capability.php';

use Larena\Licensing\Contracts\Capability;

function requireSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message);
    }
}

$capability = new class implements Capability {
    public function id(): string
    {
        return 'ai.webmaster.content';
    }

    public function packageId(): string
    {
        return 'larena/ai';
    }

    public function edition(): string
    {
        return 'pro';
    }

    public function paid(): bool
    {
        return true;
    }

    public function defaultFallback(): string
    {
        return 'locked_with_explain';
    }
};

requireSame('ai.webmaster.content', $capability->id(), 'capability id is required');
requireSame('larena/ai', $capability->packageId(), 'package id is required');
requireSame('pro', $capability->edition(), 'edition is required');
requireSame(true, $capability->paid(), 'paid flag is required');
requireSame('locked_with_explain', $capability->defaultFallback(), 'fallback policy is required');

echo "Capability contract test passed.\n";
