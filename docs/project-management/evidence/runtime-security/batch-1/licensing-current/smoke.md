# Smoke

Status: `passed_static_only`

Smoke coverage:

- package metadata remains valid;
- PSR-4 autoload loads `Larena\\Licensing\\*` classes;
- contract files are side-effect-free;
- no forbidden update-registration, storage, route, provider or admin UI implementation files exist;
- evidence and graph-sync proposal remain inside package-local evidence path.

No runtime smoke test is expected for this interface-first contract skeleton.
