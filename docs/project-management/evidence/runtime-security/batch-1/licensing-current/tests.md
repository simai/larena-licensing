# Tests

Status: `passed`

Executed commands:

```bash
PATH=/opt/homebrew/opt/php@8.3/bin:$PATH /Applications/ServBay/package/bin/composer validate --strict
PATH=/opt/homebrew/opt/php@8.3/bin:$PATH /Applications/ServBay/package/bin/composer dump-autoload
PATH=/opt/homebrew/opt/php@8.3/bin:$PATH /Applications/ServBay/package/bin/composer run quality:gate
git diff --check
```

Observed results:

- `composer.json is valid`
- Composer autoload files generated successfully.
- `validate-larena-package`: `Larena Licensing coding launch context is valid.`
- PHP lint checked scripts, tools, `src` and `tests` with no syntax errors.
- PHPStan analysed scripts, tools, `src` and `tests` with no errors.
- `CapabilityContractTest passed.`
- `LicensingFailsClosedTest passed.`
- Evidence contract passed for the current repository state.
- Scope check passed for launch allowed files and evidence path.
- `git diff --check` passed.

Semantic checks:

- commercial capability can declare fail-closed behavior;
- active/trial/grace entitlement can unlock paid capability only as represented state;
- invalid signature, revoked and unknown states cannot unlock paid capability;
- limited decision may permit bounded execution while unknown does not;
- scope check rejects changes outside launch allowed files and evidence path.
