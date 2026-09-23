<?php

declare(strict_types=1);

namespace Larena\Licensing\Runtime;

use Larena\Licensing\Enums\DistributedCapability;

/**
 * Ties a runtime capability key to its id in the commercial capability registry.
 *
 * The registry's entry object is closed — `additionalProperties: false` — so an
 * entry cannot carry the runtime key as a field. The binding is therefore a rule
 * the id is derived from, which a test can check; a comment could not. Adding the
 * field instead would mean a schema version bump and a migration of every existing
 * entry, for a binding that two lines of arithmetic already express.
 */
final class DistributedCapabilityRegistry
{
    public const ID_PREFIX = 'larena.capability.';

    public const ID_SUFFIX = '.v1';

    /**
     * `distributed.remote_operations` owned by `larena/core` becomes
     * `larena.capability.core.distributed_remote_operations.v1`.
     */
    public static function registryId(string $runtimeKey, string $owningPackage): string
    {
        $packageSlug = str_replace(['larena/', '-'], ['', '_'], $owningPackage);

        return self::ID_PREFIX . $packageSlug . '.' . str_replace('.', '_', $runtimeKey) . self::ID_SUFFIX;
    }

    public static function idFor(DistributedCapability $capability): string
    {
        return self::registryId($capability->value, $capability->owningPackage());
    }

    /**
     * @return array<string, array{registry_id: string, tier: string, package: string}>
     */
    public static function all(): array
    {
        $declared = [];

        foreach (DistributedCapability::cases() as $capability) {
            $declared[$capability->value] = [
                'registry_id' => self::idFor($capability),
                'tier' => $capability->tier(),
                'package' => $capability->owningPackage(),
            ];
        }

        return $declared;
    }

    /**
     * Whether a snapshot's capability list carries a distributed capability.
     *
     * @param list<string> $capabilityKeys
     */
    public static function granted(array $capabilityKeys, DistributedCapability $capability): bool
    {
        return in_array($capability->value, $capabilityKeys, true);
    }
}
