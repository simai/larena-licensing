# Batch 5 wave A — smoke

This wave has no database surface and no HTTP surface, so its smoke is the specs
side: the capability declarations have to regenerate deterministically, or the
registry and the contract would drift.

```
npm run generate:commercial-capabilities
```

| Measure | Before | After |
| --- | --- | --- |
| Capabilities | 47 | 49 |
| Pro tier | 14 | 15 |
| Enterprise tier | 3 | 4 |
| Generation status | passed | passed |

```
node scripts/validate-commercial-capability-adapter-ecosystem.mjs   # passed
node scripts/validate.mjs                                           # 44 packages, 258 features
```

Both entries in the regenerated registry:

| Registry id | Tier | Kind | Package |
| --- | --- | --- | --- |
| `larena.capability.core.distributed_remote_operations.v1` | pro | extension_family | larena/core |
| `larena.capability.core.distributed_cluster.v1` | enterprise | extension_family | larena/core |

The package test cross-checks this file rather than a copy of it, so a divergence
between the runtime enum and the registry fails the package gate.

## Not claimed

No push, release or deployment. No entitlement was minted or signed. No network
transport and no cluster runtime exist — the capability is declared, the gate is
nameable, and nothing became passable.
