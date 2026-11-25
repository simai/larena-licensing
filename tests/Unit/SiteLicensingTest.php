<?php

declare(strict_types=1);

use Larena\Licensing\Enums\EntitlementStatus;
use Larena\Licensing\Enums\LicenseDecisionStatus;
use Larena\Licensing\Runtime\StaticCapability;
use Larena\Licensing\Runtime\StaticEntitlementSnapshot;
use Larena\Licensing\Site\LicensingAdminSignal;
use Larena\Licensing\Site\LicensingState;
use Larena\Licensing\Site\SiteLicensingRuntime;
use Larena\Licensing\Site\SnapshotSource;
use Larena\Licensing\Site\SnapshotStore;
use Larena\Licensing\Site\TestAddresses;
use Larena\Licensing\Site\TrustedKeys;

require_once __DIR__ . '/../support/site-licensing-fixtures.php';

$fx = new SiteLicensingFixture();
$fx->key('larena-entitlement-test');
$fx->key('larena-entitlement-old');
$key = 'larena-entitlement-test';

// A good snapshot allows what it lists, and only that.
$store = $fx->folder() . '/snapshot.json';
$site = $fx->site($store);
check($site->status()->state === LicensingState::Missing && $site->status()->reasonCode === 'snapshot_missing', 'nothing kept: missing');
check(!$site->allows('telephony.ai_voice') && $site->decide('telephony.ai_voice')->reasonCode === 'snapshot_missing', 'missing snapshot denies a paid capability');
check($fx->site($store, free: ['backup.basic'])->decide('backup.basic')->reasonCode === 'capability_free', 'a capability the catalog makes free needs no snapshot');
$snapshot = $site->accept($fx->envelope($key), SnapshotStore::REFRESH);
check($snapshot->licenceStatus === 'active' && $snapshot->keyId === $key, 'good snapshot taken');
check($site->status()->state === LicensingState::Active && $site->status()->reasonCode === 'licence_active', 'good snapshot: active');
check($site->allows('telephony.ai_voice'), 'good snapshot allows a listed capability');
$denied = $site->decide('backup.offsite');
check(!$denied->allowed && $denied->reasonCode === 'capability_not_entitled', 'a capability not listed is denied');
check($fx->site($store)->status()->state === LicensingState::Active, 'kept snapshot read back from the file');
check(!str_contains((string) json_encode($site->status()->toArray()), 'signature'), 'status carries no signature');

// The envelope of the update server (`<source>/entitlement`) is the same envelope with its schema.
$wrapped = json_decode($fx->envelope($key, ['issued_at' => '2026-11-25T11:00:00+00:00']), true);
$wrapped = json_encode(['schema' => 'larena.entitlement-envelope', 'schema_version' => 1] + $wrapped, JSON_UNESCAPED_SLASHES);
check($fx->site($store)->accept((string) $wrapped, SnapshotStore::REFRESH)->licenceStatus === 'active', 'update server envelope taken');

// Forged and tampered snapshots are refused.
$forger = new SiteLicensingFixture();
$forger->key($key);
check(refusal(static fn () => $fx->site($fx->folder() . '/s.json')->accept($forger->envelope($key), SnapshotStore::REFRESH)) === 'licensing_signature_invalid', 'forged signature refused');
$tampered = json_decode($fx->envelope($key), true);
$tampered['entitlement'] = str_replace('"telephony.ai_voice"', '"telephony.ai_voice","backup.offsite"', $tampered['entitlement']);
check(refusal(static fn () => $fx->site($fx->folder() . '/s.json')->accept((string) json_encode($tampered), SnapshotStore::REFRESH)) === 'licensing_signature_invalid', 'tampered bytes refused');
$onDisk = $fx->folder() . '/s.json';
$fx->site($onDisk)->accept($fx->envelope($key), SnapshotStore::REFRESH);
file_put_contents($onDisk, str_replace('telephony.ai_voice', 'telephony.ai_voicf', (string) file_get_contents($onDisk)));
$broken = $fx->site($onDisk);
check($broken->status()->state === LicensingState::Invalid && !$broken->allows('telephony.ai_voicf'), 'kept snapshot changed on disk: invalid, denied');
check(refusal(static fn () => $fx->site($fx->folder() . '/s.json')->accept('{"entitlement":"x"}', SnapshotStore::REFRESH)) === 'licensing_snapshot_malformed', 'no signature: refused');
check(refusal(static fn () => $fx->site($fx->folder() . '/s.json')->accept($fx->envelope($key, ['product' => 'larena-panel']), SnapshotStore::REFRESH)) === 'licensing_snapshot_other_product', 'another product refused');
check(refusal(static fn () => $fx->site($fx->folder() . '/s.json')->accept($fx->envelope($key, ['schema_version' => 2]), SnapshotStore::REFRESH)) === 'licensing_snapshot_unsupported', 'unknown schema version refused');

// An unknown key id is refused; one id with two different keys is not trusted at all.
$stranger = new SiteLicensingFixture();
$stranger->key('larena-entitlement-other');
check(refusal(static fn () => $fx->site($fx->folder() . '/s.json')->accept($stranger->envelope('larena-entitlement-other'), SnapshotStore::REFRESH)) === 'licensing_key_unknown', 'unknown key id refused');
check(TrustedKeys::from([[$key => $fx->public[$key]], [$key => $forger->public[$key]]])->key($key) === null, 'one id, two keys: not trusted');
check(TrustedKeys::parse('a=' . $fx->public[$key] . ',bad,b=QUJD,' . $key . '=' . $fx->public[$key]) === ['a' => $fx->public[$key], $key => $fx->public[$key]], 'env list read strictly');

// A revoked key is refused, also for a snapshot already kept.
$revokedStore = $fx->folder() . '/s.json';
check(refusal(static fn () => $fx->site($revokedStore, revoked: [$key])->accept($fx->envelope($key), SnapshotStore::REFRESH)) === 'licensing_key_revoked', 'revoked key refused');
$fx->site($revokedStore)->accept($fx->envelope($key), SnapshotStore::REFRESH);
$afterRevoke = $fx->site($revokedStore, revoked: [$key]);
check($afterRevoke->status()->state === LicensingState::Invalid && $afterRevoke->status()->reasonCode === 'licensing_key_revoked'
    && !$afterRevoke->allows('telephony.ai_voice'), 'kept snapshot of a revoked key: invalid, denied');

// Past its 30 days the snapshot still counts for installed code, and says stale; an ended licence says expired.
$stale = $fx->site($store, now: '2026-12-26T12:00:00+00:00');
check($stale->status()->state === LicensingState::Stale && $stale->status()->reasonCode === 'snapshot_not_refreshed', 'past 30 days: stale');
check($stale->allows('telephony.ai_voice'), 'stale snapshot still allows installed code');
$expiredStore = $fx->folder() . '/s.json';
$fx->site($expiredStore)->accept($fx->envelope($key, ['status' => 'expired']), SnapshotStore::REFRESH);
$expired = $fx->site($expiredStore);
check($expired->status()->state === LicensingState::Expired && $expired->status()->reasonCode === 'licence_expired', 'ended licence: expired');
check($expired->allows('telephony.ai_voice'), 'ended licence still allows installed code');
$revokedLicence = $fx->folder() . '/s.json';
$fx->site($revokedLicence)->accept($fx->envelope($key, ['status' => 'revoked']), SnapshotStore::REFRESH);
check(!$fx->site($revokedLicence)->allows('telephony.ai_voice') && $fx->site($revokedLicence)->decide('telephony.ai_voice')->reasonCode === 'licence_revoked', 'revoked licence denies');
$noLicence = $fx->folder() . '/s.json';
$fx->site($noLicence)->accept($fx->envelope($key, ['status' => 'missing', 'capabilities' => []]), SnapshotStore::REFRESH);
check($fx->site($noLicence)->status()->state === LicensingState::Missing && !$fx->site($noLicence)->allows('telephony.ai_voice'), 'no licence on the server: missing, denied');

// Updates: only releases up to «updates until» of the package.
$updates = $fx->folder() . '/s.json';
$fx->site($updates)->accept($fx->envelope($key, ['status' => 'expired', 'updates_until' => ['larena/telephony' => '2027-01-01T00:00:00+00:00']]), SnapshotStore::REFRESH);
$u = $fx->site($updates);
check($u->allowsUpdate('larena/telephony', new DateTimeImmutable('2026-12-01T00:00:00+00:00'))->allowed, 'release before the date: update allowed');
check($u->allowsUpdate('larena/telephony', new DateTimeImmutable('2027-02-01T00:00:00+00:00'))->reasonCode === 'updates_period_ended', 'release after the date: no update');
check($u->allowsUpdate('larena/backup', new DateTimeImmutable('2026-12-01T00:00:00+00:00'))->reasonCode === 'updates_until_missing', 'package without a date: no paid update');
check($u->updatesUntil('larena/telephony')?->format('Y-m-d') === '2027-01-01', 'updates until is readable');

// Dates from the future are refused; a clock set back gains nothing and is reported; an older snapshot is refused.
check(refusal(static fn () => $fx->site($fx->folder() . '/s.json')->accept($fx->envelope($key, ['issued_at' => '2026-11-25T12:10:00+00:00', 'valid_until' => '2026-11-26T12:10:00+00:00']), SnapshotStore::REFRESH)) === 'licensing_snapshot_from_future', 'future date refused');
check(refusal(static fn () => $fx->site($fx->folder() . '/s.json')->accept($fx->envelope($key, ['issued_at' => '2026-11-25T12:04:00+00:00']), SnapshotStore::REFRESH)) === null, 'a few minutes of clock difference is fine');
$back = $fx->site($store, now: '2026-11-20T12:00:00+00:00');
check($back->status()->state === LicensingState::Stale && $back->status()->reasonCode === 'clock_set_back' && $back->status()->clockSetBack, 'clock set back: stale, reported');
check($back->allows('telephony.ai_voice'), 'clock set back: installed code keeps working');
$seenStore = $fx->folder() . '/s.json';
$fx->site($seenStore)->accept($fx->envelope($key), SnapshotStore::REFRESH);
$fx->site($seenStore, now: '2026-12-30T00:00:00+00:00')->markSeen();
$rewound = $fx->site($seenStore, now: '2026-11-26T00:00:00+00:00');
check($rewound->status()->state === LicensingState::Stale && $rewound->status()->reasonCode === 'snapshot_not_refreshed', 'clock back after a later visit: still past 30 days');
check(refusal(static fn () => $fx->site($store)->accept($fx->envelope($key, ['issued_at' => '2026-11-24T10:00:00+00:00']), SnapshotStore::REFRESH)) === 'licensing_snapshot_older_than_kept', 'an older snapshot than the kept one is refused');

// Every test address gets every capability without a snapshot; a production address does not.
foreach (['localhost', '127.0.0.1', '::1', '[::1]', 'larena.localhost', 'larena.test', 'shop.local', 'dev.example.com', 'staging.example.com', 'demo.larena.dev', 'LARENA.TEST.'] as $host) {
    $dev = $fx->site($fx->folder() . '/s.json', host: $host);
    check($dev->status()->state === LicensingState::Development && $dev->allows('anything.paid'), 'test address allowed: ' . $host);
}
foreach (['example.com', 'devices.example.com', 'mytest.com', 'example.dev', 'larena.dev.example.com', 'staging-example.com', 'dev.', ''] as $host) {
    check(!TestAddresses::isTestAddress($host), 'not a test address: ' . $host);
}

// Load from a file: it must name this site; it may stay fresh up to a year, never longer.
$file = $fx->folder() . '/s.json';
check(refusal(static fn () => $fx->site($file)->accept($fx->envelope($key), SnapshotStore::FILE)) === 'licensing_snapshot_not_bound', 'a file without the site address is refused');
check(refusal(static fn () => $fx->site($file)->accept($fx->envelope($key, ['site_hosts' => ['other.example.com']]), SnapshotStore::FILE)) === 'licensing_snapshot_other_site', 'a file for another site is refused');
$offline = $fx->envelope($key, ['site_hosts' => ['cms.example.com'], 'fresh_until' => '2027-09-21T10:00:00+00:00']);
check($fx->site($file)->accept($offline, SnapshotStore::FILE)->licenceStatus === 'active', 'file for this site loaded');
$later = $fx->site($file, now: '2027-06-01T00:00:00+00:00');
check($later->status()->state === LicensingState::Active && $later->allows('telephony.ai_voice'), 'loaded file: active for months');
$capped = $fx->folder() . '/s.json';
$fx->site($capped)->accept($fx->envelope($key, ['site_hosts' => ['cms.example.com'], 'fresh_until' => '2028-06-01T00:00:00+00:00']), SnapshotStore::FILE);
check($fx->site($capped, now: '2026-12-01T00:00:00+00:00')->status()->state === LicensingState::Active, 'long file: active inside a year');
check($fx->site($capped, now: '2027-12-01T00:00:00+00:00')->status()->state === LicensingState::Stale, 'no snapshot stays fresh longer than a year');
check(refusal(static fn () => $fx->site($fx->folder() . '/s.json', host: 'other.example.com')->accept($fx->envelope($key, ['site_hosts' => ['cms.example.com']]), SnapshotStore::REFRESH)) === 'licensing_snapshot_other_site', 'a fetched snapshot naming another site is refused');

// The LicensingRuntime contract: the site decides for callers without a snapshot; an explicit snapshot keeps the old answer.
$runtime = new SiteLicensingRuntime($fx->site($store));
$paid = new StaticCapability('telephony.ai_voice', 'larena/telephony');
check($runtime->decide($paid)->status() === LicenseDecisionStatus::Allowed, 'runtime: listed capability allowed');
check($runtime->decide(new StaticCapability('backup.offsite', 'larena/backup'))->status() === LicenseDecisionStatus::Locked, 'runtime: not listed is locked');
check($runtime->decide(new StaticCapability('backup.offsite', 'larena/backup'), new StaticEntitlementSnapshot(EntitlementStatus::Active, 'k', 's', ['backup.offsite']))->isAllowed(), 'runtime: explicit snapshot keeps the old answer');
check(!(new SiteLicensingRuntime($fx->site($fx->folder() . '/s.json')))->decide($paid)->isAllowed(), 'runtime: no snapshot, locked');

// The admin signal: nothing when fine, info for a development copy, warnings otherwise; nothing for a site that never asked.
check(LicensingAdminSignal::for($fx->site($store)->status(), true) === null, 'active: no banner');
check(LicensingAdminSignal::for($fx->site($fx->folder() . '/s.json', host: 'larena.test')->status(), false)?->id === 'licensing:development', 'development: info');
check(LicensingAdminSignal::for($stale->status(), true)?->textKey === 'stale' && LicensingAdminSignal::for($stale->status(), true)->replace === ['date' => '25.11.2026'], 'stale: warning with the date');
check(LicensingAdminSignal::for($expired->status(), true)?->id === 'licensing:expired', 'expired: warning');
check(LicensingAdminSignal::for($afterRevoke->status(), true)?->replace === ['reason' => 'licensing_key_revoked'], 'invalid: warning with the reason');
check(LicensingAdminSignal::for($fx->site($fx->folder() . '/s.json')->status(), false) === null, 'no site key, no snapshot: no banner');
check(LicensingAdminSignal::for($fx->site($fx->folder() . '/s.json')->status(), true)?->id === 'licensing:missing', 'site key but no snapshot: warning');
foreach (['ru', 'en'] as $locale) {
    $words = require __DIR__ . '/../../resources/lang/' . $locale . '/licensing.php';
    foreach (['source', 'development', 'stale', 'clock_set_back', 'expired', 'revoked', 'missing', 'invalid'] as $word) {
        check(is_string($words['alerts'][$word] ?? null) && $words['alerts'][$word] !== '', "words {$locale}: {$word}");
    }
}

// The refresh source: the update client's pattern — HTTPS sources, not github.com, the site key as a bearer header.
$secretKey = 'lsk_' . str_repeat('A', 43);
$calls = [];
$source = new SnapshotSource(['https://github.com/simai/larena/releases', 'https://updates.example.com/larena/'], $secretKey,
    static function (string $url, array $headers, int $timeout, int $connect, int $limit) use (&$calls, $fx, $key): array {
        $calls[] = [$url, $headers];

        return [200, $fx->envelope($key, ['issued_at' => '2026-11-25T11:30:00+00:00'])];
    });
check($fx->site($store)->accept($source->fetch(), SnapshotStore::REFRESH)->licenceStatus === 'active', 'refresh through the source');
check(count($calls) === 1 && $calls[0][0] === 'https://updates.example.com/larena/entitlement' && $calls[0][1] === ['Authorization' => 'Bearer ' . $secretKey], 'github skipped, site key sent as bearer');
$refused = new SnapshotSource(['https://updates.example.com/larena'], $secretKey, static fn (): array => [401, '{"error":"site_key_required"}']);
try {
    $refused->fetch();
    check(false, 'refused refresh must fail');
} catch (RuntimeException $failure) {
    check($failure->getMessage() === 'licensing_refresh_refused' && !str_contains($failure->getMessage(), $secretKey), 'refused refresh: code only');
}
try {
    (new SnapshotSource(['https://updates.example.com/larena'], null, static fn (): array => [200, '']))->fetch();
    check(false, 'no site key must fail');
} catch (RuntimeException $failure) {
    check($failure->getMessage() === 'licensing_site_key_missing', 'no site key: nothing asked');
}
check(!str_contains(print_r($source, true), $secretKey), 'the source never prints the site key');

echo "SiteLicensingTest passed.\n";
