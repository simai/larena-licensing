# Batch 5 wave A — review feedback

No independent review yet.

1. **The snapshot is trusted as handed in.** Nothing here verifies a signature, so
   `status()` is whatever the caller says it is. That is the existing package's
   design and this wave did not change it, but a reviewer should confirm where
   verification is meant to happen before node admission is used for real.
2. **The mapping rule is the binding.** If someone adds a distributed capability to
   the enum without declaring it in the G4.1 contract, the cross-check fails — which
   is the intent. If someone declares it in the contract only, nothing fails. A
   reviewer should decide whether the specs side needs the mirror check.
3. **The environment fingerprint is an opaque string here.** How it is computed
   belongs to the environment profile in wave B. Admission compares equality and
   nothing more, which is deliberate but means the strength of the binding is
   decided elsewhere.
4. **Per-scope metered limits are not implemented.** Only the node limit is. Record
   count, storage volume and AI budget need a meter, and none exists.
5. **`verifyOperator` returns allowed with `no_operator_overlay`.** A single-site
   installation legitimately has none, so "no overlay" is not a failure. A reviewer
   should confirm no caller reads `allowed` as "an operator overlay is present".
