# Smoke

Smoke expectations:

- Free capability is allowed without entitlement snapshot.
- Paid capability is allowed by active entitlement snapshot containing the
  capability key.
- Trial entitlement produces a limited but executable decision.
- Missing snapshot fails closed.
- Invalid, tampered, expired and revoked snapshots fail closed.
- Snapshot without the capability key fails closed.

Result: passed.

Evidence:

- `LicensingCapabilityDecisionRuntimeTest` proves free capability defaults,
  active paid entitlement and trial limited decisions.
- `LicensingCapabilityDecisionFailsClosedTest` proves missing, invalid,
  tampered, expired, revoked and not-entitled paths fail closed.
- `LicensingCapabilityDecisionFailsClosedTest` proves invalid capability
  descriptors fail closed.
