<?php

declare(strict_types=1);

namespace Larena\Licensing\Enums;

/**
 * The two capabilities that make an installation distributed.
 *
 * They exist here as runtime keys. The commercial registry knows them by a longer
 * id derived from the key, and `DistributedCapabilityRegistry` is where the two
 * names are tied together — a rule rather than a lookup table, so a new capability
 * cannot be half-declared.
 */
enum DistributedCapability: string
{
    /** Crossing a node boundary at all. Pro. */
    case RemoteOperations = 'distributed.remote_operations';

    /** Running one installation as a cluster of admitted nodes. Enterprise. */
    case Cluster = 'distributed.cluster';

    public function tier(): string
    {
        return match ($this) {
            self::RemoteOperations => 'pro',
            self::Cluster => 'enterprise',
        };
    }

    public function owningPackage(): string
    {
        return 'larena/core';
    }
}
