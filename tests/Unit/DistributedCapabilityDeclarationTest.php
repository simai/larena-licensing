<?php

declare(strict_types=1);

require_once __DIR__ . '/../support/scope-entitlement-fixtures.php';

use Larena\Licensing\Enums\DistributedCapability;
use Larena\Licensing\Runtime\DistributedCapabilityRegistry;

/**
 * The runtime knows a capability by its key; the commercial registry knows it by a
 * longer id. The registry entry object is closed, so the binding cannot be a field
 * on the entry — it is a rule the id is derived from, and this test is what makes
 * the rule checkable rather than merely written down.
 */
licensing_assert(
    DistributedCapabilityRegistry::registryId('distributed.remote_operations', 'larena/core')
        === 'larena.capability.core.distributed_remote_operations.v1',
    'the mapping rule produces the frozen id',
);
licensing_assert(
    DistributedCapabilityRegistry::registryId('distributed.cluster', 'larena/core')
        === 'larena.capability.core.distributed_cluster.v1',
);

// The rule works for a hyphenated package too, which is how it stays a rule rather
// than two special cases.
licensing_assert(
    DistributedCapabilityRegistry::registryId('file_manager.bulk', 'larena/file-manager')
        === 'larena.capability.file_manager.file_manager_bulk.v1',
);

$declared = DistributedCapabilityRegistry::all();
licensing_assert(count($declared) === 2, 'exactly two distributed capabilities');

licensing_assert($declared['distributed.remote_operations']['tier'] === 'pro');
licensing_assert($declared['distributed.cluster']['tier'] === 'enterprise');
foreach ($declared as $entry) {
    licensing_assert($entry['package'] === 'larena/core', 'both belong to core, which owns the transport port');
}

// The enum and the registry agree, because the registry derives from the enum.
foreach (DistributedCapability::cases() as $capability) {
    licensing_assert(isset($declared[$capability->value]), $capability->value . ' is declared');
    licensing_assert($declared[$capability->value]['registry_id'] === DistributedCapabilityRegistry::idFor($capability));
    licensing_assert($declared[$capability->value]['tier'] === $capability->tier());
}

// The specs registry really carries both entries with the frozen tiers. This is the
// assertion that would fail if someone declared the capability in one place only.
$registryPath = null;
foreach ([
    getenv('LARENA_SPECS_ROOT') ?: '',
    dirname(__DIR__, 5) . '/larena-specs',
    dirname(__DIR__, 4) . '/larena-specs',
    dirname(__DIR__, 3) . '/larena-specs',
] as $candidate) {
    if ($candidate !== '' && is_file($candidate . '/specs/capabilities/registry.json')) {
        $registryPath = $candidate . '/specs/capabilities/registry.json';

        break;
    }
}

if ($registryPath !== null) {
    $registry = json_decode((string) file_get_contents($registryPath), true);
    licensing_assert(is_array($registry), 'the capability registry parses');

    $byId = [];
    foreach ($registry['capabilities'] ?? [] as $entry) {
        $byId[(string) $entry['id']] = $entry;
    }

    foreach ($declared as $runtimeKey => $entry) {
        $id = $entry['registry_id'];
        licensing_assert(isset($byId[$id]), $runtimeKey . ' is missing from the capability registry as ' . $id);
        licensing_assert(
            $byId[$id]['default_tier'] === $entry['tier'],
            $id . ' must be ' . $entry['tier'] . ', registry says ' . $byId[$id]['default_tier'],
        );
        licensing_assert($byId[$id]['package'] === 'larena/core');
        licensing_assert($byId[$id]['kind'] === 'extension_family');
        licensing_assert(
            str_contains((string) $byId[$id]['scope'], $runtimeKey),
            'the registry entry names the runtime key in its scope text, so a reader can find the binding',
        );
    }
} else {
    // The specs repository is not always checked out beside the package. Saying so
    // is better than skipping silently.
    fwrite(STDERR, "note: larena-specs not found beside the package; registry cross-check skipped\n");
}

// Granting is a membership test against a snapshot's capability list.
licensing_assert(DistributedCapabilityRegistry::granted(
    ['distributed.cluster'],
    DistributedCapability::Cluster,
));
licensing_assert(!DistributedCapabilityRegistry::granted(
    ['distributed.remote_operations'],
    DistributedCapability::Cluster,
), 'remote operations is not a cluster');
licensing_assert(!DistributedCapabilityRegistry::granted([], DistributedCapability::RemoteOperations));

echo "Distributed capability declaration passed.\n";
