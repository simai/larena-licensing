<?php

declare(strict_types=1);

namespace Larena\Licensing\Contracts;

use Larena\Licensing\Enums\NodeAdmissionStatus;

/**
 * Whether a node may join, and why not.
 *
 * A refusal never says how many nodes are admitted, except when the limit is the
 * reason — otherwise probing admission would map the cluster.
 */
final readonly class NodeAdmissionDecision
{
    public function __construct(
        public NodeAdmissionStatus $status,
        public string $reasonCode,
        public ?int $nodeLimit = null,
    ) {
    }

    public static function admitted(): self
    {
        return new self(NodeAdmissionStatus::Admitted, 'admitted');
    }

    public static function refused(string $reasonCode, ?int $nodeLimit = null): self
    {
        return new self(NodeAdmissionStatus::Refused, $reasonCode, $nodeLimit);
    }

    public function isAdmitted(): bool
    {
        return $this->status->isAdmitted();
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'status' => $this->status->value,
            'reason_code' => $this->reasonCode,
            'node_limit' => $this->nodeLimit,
        ], static fn (mixed $value): bool => $value !== null);
    }
}
