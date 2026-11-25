# Larena Licensing

Runtime licensing layer that decides which package, bundle and capability features are available to a site, with signed entitlement snapshots, trial restrictions, subscription/expiration policies, environment-aware licenses, limits and explainable locks.

## The site's licence (owner decisions 2026-11-25)

Code asks for a right to a function, `SiteLicensing::allows('telephony.ai_voice')` (or the `LicensingRuntime`
contract), never for a plan. Paid or free is catalog data: the signed snapshot lists what the site has, the shipped
capability catalog makes its `free` functions free for everyone, and `larena-licensing.free_capabilities` adds the
site's own.

- **Catalog.** `resources/catalog/capabilities.json` is generated from the Specs capability registry
  (`specs/capabilities/registry.json`: id, package, tier, trial) and `resources/catalog/presentation.json` (area, what a
  function needs, always on) by `composer run catalog:refresh`; sites have no Specs checkout. `composer run catalog:check`
  (in the quality gate) checks it, its words in `resources/lang/{en,ru}/capabilities.php`, and that it matches a fresh
  build when Specs is found (skipped without it). `Catalog\SiteCapabilities` is the read model for screens: every
  function with its words, package, tier, trial and the site's decision (`allowed`, `development`, `needs_licence`).

- **Snapshot.** The registration server's envelope `{"entitlement": "<exact bytes>", "signature": "{\"key_id\",\"signature\"}"}`,
  Ed25519 over the exact bytes; the update server hands the same envelope to the site at `GET <source>/entitlement`.
  The entitlement is `larena.entitlement` schema_version 1 (`snapshot_key`, `site`, `product`, `status`,
  `capabilities`, `issued_at`, `valid_until`) with optional site fields: `site_hosts` (addresses the snapshot is for),
  `updates_until` (`{"<package>|*": "<ATOM date>"}`) and `fresh_until` (how long it stays fresh; at most a year).
- **Keys.** `config/larena-entitlement-keys.php` of the site plus `LARENA_LICENSING_TRUSTED_KEYS` (`id=base64,…`);
  revoked ids (`LARENA_LICENSING_REVOKED_KEY_IDS`, and the update client's) win. Not the release keys: the snapshot
  key is online, the release key offline.
- **Rules.** A test address (`localhost`, `*.test`, `*.local`, `dev.*`, `staging.*`, `*.larena.dev`) allows everything,
  state `development`. Otherwise a verified snapshot that lists the capability allows it: fresh (`active`), past its
  30 days or with the clock set back (`stale`), after the licence ended (`expired`: installed code keeps working;
  updates only for releases up to `updates_until`). Anything else is denied with a reason code (`snapshot_missing`,
  `licensing_signature_invalid`, `licensing_key_revoked`, `licence_revoked`, `capability_not_entitled`, …).
- **Store.** One file, `storage/app/larena/licensing/snapshot.json`; no table, no migration.
- **Commands.** `larena:licensing:refresh` (daily through the scheduler while the site has a site key; the update
  client's sources, site key and timeouts), `larena:licensing:load <file>` (sites without network; the file must name
  the site's address), `larena:licensing:status`. They print codes only, never a key.
- **Admin.** The state goes to the admin's alert bar (`larena/licensing`); the public site never changes.

Canonical specifications are in `simai/larena-specs`.
