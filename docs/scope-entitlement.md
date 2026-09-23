# Scope entitlement and node admission

## An entitlement names its scope

```php
$decision = $resolver->resolve($capability, 'org:acme');
$decision->allowed;
$decision->reasonCode;
```

A site-level snapshot is valid on its own: a single-site installation is not
required to invent an operator level.

## The overlay is a ceiling

An operator overlay holds the capabilities an installation may use at all. A scope
snapshot **narrows** it — the effective set is the intersection and the effective
node limit is the minimum. A scope can never add a capability the operator lacks,
because then an organization could sell itself a licence.

The refusal says which side refused:

| Reason | Meaning |
| --- | --- |
| `capability_not_in_operator_overlay` | the operator does not hold it; fix the operator entitlement |
| `capability_not_entitled` | the operator holds it, this scope does not; fix the scope entitlement |

## Free stays free, offline

A capability declared free by default is allowed **without reading a snapshot at
all**. The local transport, single-node REST, MCP and headless operation never
depend on licensing infrastructure being reachable.

## Node admission

```php
$decision = $admission->admit($nodeId, $environmentFingerprint, $snapshot, $admittedNodeCount);
```

Refused unless all of it holds: an active snapshot, the `distributed.cluster`
capability, an environment fingerprint equal to the one the snapshot was issued
for, and room under the node limit. No snapshot means refused — a node asking to be
admitted is by definition not the single node.

A refusal never reveals how many nodes are admitted, except when the limit is the
reason, because that is what an operator needs to act on.

## The two distributed capabilities

| Runtime key | Registry id | Tier |
| --- | --- | --- |
| `distributed.remote_operations` | `larena.capability.core.distributed_remote_operations.v1` | Pro |
| `distributed.cluster` | `larena.capability.core.distributed_cluster.v1` | Enterprise |

The id is derived from the key by rule, and a test cross-checks the real registry.
Declaring a capability ships no implementation: the gate becomes nameable, not
passable.
