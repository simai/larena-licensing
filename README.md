# Larena Licensing

Runtime licensing layer that decides which package, bundle and capability features are available to a site, with signed entitlement snapshots, trial restrictions, subscription/expiration policies, environment-aware licenses, limits and explainable locks.

## The site's licence (owner decisions 2026-11-25)

Code asks for a right to a function, `SiteLicensing::allows('telephony.ai_voice')` (or the `LicensingRuntime`
contract), never for a plan. Paid or free is catalog data: the signed snapshot lists what the site has, the shipped
capability catalog makes its `free` functions free for everyone, and `larena-licensing.free_capabilities` adds the
site's own.

- **Catalog.** `resources/catalog/capabilities.json` is generated from the Specs capability registry
  (`specs/capabilities/registry.json`: id, package, tier, trial, area, what a function needs, always on; capabilities
  it marks `planned` are left out) by `composer run catalog:refresh`; sites have no Specs checkout. `composer run catalog:check`
  (in the quality gate) checks it, its words in `resources/lang/{en,ru}/capabilities.php`, and that it matches a fresh
  build when Specs is found (skipped without it). `Catalog\SiteCapabilities` is the read model for screens: every
  function with its words, package, tier, trial (and `trial_days`, its length from the registry) and the site's decision (`allowed`, `development`, `needs_licence`).

- **Snapshot.** The registration server's envelope `{"entitlement": "<exact bytes>", "signature": "{\"key_id\",\"signature\"}"}`,
  Ed25519 over the exact bytes; the update server hands the same envelope to the site at `GET <source>/entitlement`.
  The entitlement is `larena.entitlement` schema_version 1 (`snapshot_key`, `site`, `product`, `status`,
  `capabilities`, `issued_at`, `valid_until`) with optional site fields: `site_hosts` (addresses the snapshot is for),
  `updates_until` (`{"<package>|*": "<ATOM date>"}`) and `fresh_until` (how long it stays fresh; at most a year),
  and `trials` (`{"<capability>": "<ATOM end>"}`: the exact end of each trial that alone holds a listed
  capability). The trial end shown for a function is `trials[<capability>]`, else, while the status is `trial`, `fresh_until`.
- **Keys.** `config/larena-entitlement-keys.php` of the site plus `LARENA_LICENSING_TRUSTED_KEYS` (`id=base64,…`);
  revoked ids (`LARENA_LICENSING_REVOKED_KEY_IDS`, and the update client's) win. Not the release keys: the snapshot
  key is online, the release key offline.
- **Rules.** The states (`active`, `stale`, `expired`, `missing`, `invalid`, `development`), licence end (variant C),
  test addresses and the trial are shared rules: they live in Specs only, see [Shared rules](#shared-rules). Here:
  anything not allowed is denied with a reason code (`snapshot_missing`, `licensing_signature_invalid`,
  `licensing_key_revoked`, `licence_revoked`, `capability_not_entitled`, `trial_ended`, …).
- **Store.** Files in `storage/app/larena/licensing/`: `snapshot.json` (the kept snapshot), `site-key` (a key entered in
  the admin, 0600; `LARENA_UPDATE_SITE_KEY` stands over it) and `addons.json` (the addon list); no table, no migration.
- **Commands.** `larena:licensing:refresh` (daily through the scheduler while the site has a site key; the update
  client's sources, site key and timeouts), `larena:licensing:load <file>` (sites without network; the file must name
  the site's address), `larena:licensing:status`, `larena:licensing:trial <capability>`. They print codes only, never a key.
- **Trial («Попробовать бесплатно»).** `SiteTrial::request($capability)` sends `POST <source>/trial` with the refresh's
  sources and site key and JSON `{"capability": "<id>"}`, verifies the answer exactly like a refresh and keeps it only
  when it verifies. Only for a paid function the catalog marks `trial_eligible`; others, a test address (no trial
  needed) and a site without a key are answered without a request. Every reason has words in ru and en
  (`larena-licensing::licensing.trial.reasons.*`). The admin's «Возможности сайта» and the installer call it.
- **Admin.** The state goes to the admin's alert bar (`larena/licensing`); the public site never changes. The admin's
  section «Лицензия и обновления» reads `Site\SiteLicence` (the state and dates, «Обновить сейчас», a snapshot file,
  the site key, never shown back), `Site\SiteCoupon` (`POST <source>/coupon` with `{"code"}`, Larena Server's shape of
  2026-11-26; the answer verified like a refresh) and `Site\SiteAddons` (`GET <source>/addons`, `larena.addon-list`,
  kept in a file; each addon's state decided again from the signed snapshot). Words: `larena-licensing::licensing.licence.*`.

## Shared rules

Shared knowledge lives only in Larena Specs (owner decision 2026-11-26, variant C). `docs/specs-links.json` names the
Specs sections this package relies on, read at one Specs commit; `composer run specs-links:check` (in the quality gate)
checks that each still exists and has not changed since. When Specs changes a linked section, read it, change the
package if needed and move `specs_commit` forward. The main source is `docs/architecture/larena-licensing-updates-purchase.md`.

Canonical specifications are in `simai/larena-specs`.
