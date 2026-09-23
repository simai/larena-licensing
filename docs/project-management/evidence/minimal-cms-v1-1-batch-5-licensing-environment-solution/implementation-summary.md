# Batch 5 wave A — Licensing scope entitlement and node admission

Repository: `simai/larena-licensing`
Branch: `feature/minimal-cms-v1-1-batch-5-licensing-scope-entitlement` from `main` at `52d1215`
Launch record: `docs/project-management/launch-records/minimal-cms-v1-1-batch-5-wave-a-licensing-scope-entitlement.json`
Specs side: `simai/larena-specs` at `3900954a` declares the two capabilities and carries the regenerated registry.

## What exists now

An entitlement snapshot knows which scope it applies to, how many nodes it admits
and which environment it was issued for. `ScopedEntitlementSnapshot` extends the
existing contract rather than replacing it, so every caller that only needs the
old one keeps working.

**The overlay is a ceiling, never a floor.** A scope snapshot can take capabilities
away and lower a node limit. It cannot add a capability the operator does not hold
— that would let an organization sell itself a licence. The effective capability
set is the intersection; the effective node limit is the minimum. A refusal says
which side refused: `capability_not_in_operator_overlay` points at the operator,
`capability_not_entitled` points at the scope.

**The free path never reads a snapshot.** Not "reads it and notices the capability
is free" — never reads it. The test proves this with a snapshot source that throws
if consulted, so the wrong behaviour cannot pass unnoticed. An offline
installation, or one whose licensing service is down, keeps every behaviour it was
promised for free: the local transport, single-node REST, MCP and headless
operation.

**Node admission refuses by default.** No snapshot means refused, not "assume a
single node" — a node asking to be admitted is by definition not the single node.
An inactive entitlement, a missing `distributed.cluster`, an environment that does
not match the one the snapshot was issued for, one node too many: each has its own
reason code. A snapshot issued for one environment must not admit a node in
another, or one entitlement would licence every copy of it.

A refusal never says how many nodes are admitted. The single exception is the
limit itself, because that is the reason and an operator needs it to act. Without
that rule, probing admission would map the cluster.

## The two capabilities, and how they are bound

`distributed.remote_operations` (Pro) and `distributed.cluster` (Enterprise) are
declared in the G4.1 contract and appear in the regenerated
`specs/capabilities/registry.json`. The Batch 3 transport already refused a network
call without the first; now the capability it refuses against exists on the books.

The runtime knows a capability by its key; the registry knows it by a longer id.
The registry's entry object is closed — `additionalProperties: false` — so the
binding cannot be a field on the entry. It is a **rule** the id is derived from:

```
larena.capability.<package slug>.<runtime key with dots as underscores>.v1
```

A rule is checkable where a comment is not, and a test computes the id for every
runtime key the enum declares and asserts the registry carries it with the frozen
tier. Adding a field instead would have meant a schema version bump and a migration
of every existing entry, for a binding two lines of arithmetic already express.

## What this wave does not do

It mints nothing and signs nothing: verification consumes a snapshot it is handed,
and issuance is a server-side concern. It ships no network transport and no cluster
runtime — declaring a capability makes the gate nameable, not passable. It adds no
table and no HTTP surface.

## Gates

`composer quality:gate` passes: lint, PHPStan, nine package tests, metadata sync,
evidence contract, scope check. On the specs side,
`generate:commercial-capabilities` regenerates deterministically and
`validate-commercial-capability-adapter-ecosystem` passes.
