<?php

declare(strict_types=1);

use Larena\Licensing\Catalog\CapabilityCatalog;
use Larena\Licensing\Site\SiteAddons;
use Larena\Licensing\Site\SiteCoupon;
use Larena\Licensing\Site\SiteKeyStore;
use Larena\Licensing\Site\SiteLicence;
use Larena\Licensing\Site\SnapshotSource;

require_once __DIR__ . '/../support/site-licensing-fixtures.php';

// «Лицензия и обновления» in the admin, the licensing side: the site key kept and never shown, «Обновить сейчас», a
// snapshot file, a coupon (Larena Server's shape of 2026-11-26) and the addon list, all against a fake update server
// with throwaway keys. Nothing real is called.
$root = dirname(__DIR__, 2);
$catalog = CapabilityCatalog::shipped();
$fx = new SiteLicensingFixture();
$fx->key('larena-entitlement-test');
$forger = new SiteLicensingFixture();
$forger->key('larena-entitlement-test');
$key = 'larena-entitlement-test';
$chat = 'larena.capability.chat.base';
$docara = 'larena.capability.docara.base';
$siteKey = 'site-key-' . bin2hex(random_bytes(12));

/** A fake update server: records each request (url, headers, body) and answers by path. */
final class FakeLicenceServer
{
    /** @var list<array{url:string, headers:array<string,string>, body:?string}> */
    public array $requests = [];

    /** @var array<string, array{0:int,1:string}> path suffix => answer */
    public array $answers = [];

    public bool $down = false;

    /** @return list<array{url:string, headers:array<string,string>, body:?string}> what was asked since the last reset */
    public function log(): array
    {
        return $this->requests;
    }

    public function reset(): void
    {
        $this->requests = [];
    }

    /** @return array{0:int,1:string} */
    public function take(string $url, array $headers, ?string $body): array
    {
        $this->requests[] = ['url' => $url, 'headers' => $headers, 'body' => $body];
        if ($this->down) {
            throw new RuntimeException('connection refused');
        }
        foreach ($this->answers as $suffix => $answer) {
            if (str_ends_with($url, $suffix)) {
                return $answer;
            }
        }

        return [404, '{"error":"not_found"}'];
    }
}

$server = new FakeLicenceServer();
$transport = static fn (string $url, array $headers, int $timeout, int $connect, int $limit, ?string $body = null): array => $server->take($url, $headers, $body);
$source = static fn (?string $siteKeyGiven, array $sources = ['https://github.com/simai/larena', 'https://updates.example.com/r/larena/stable'])
    => new SnapshotSource($sources, $siteKeyGiven, $transport);

// --- The site key store: 0600, never printed, a link refused, a malformed key refused.
$folder = $fx->folder();
$keys = new SiteKeyStore($folder . '/site-key');
check(!$keys->has() && $keys->read() === null, 'no key at first');
$keys->save('  ' . $siteKey . "\n");
check($keys->read() === $siteKey, 'the key is kept trimmed');
check((fileperms($folder . '/site-key') & 0777) === 0600, 'the key file is 0600');
check(!str_contains(print_r($keys, true), $siteKey) && !str_contains(var_export((array) $keys->__debugInfo(), true), $siteKey), 'the store never prints the key');
foreach (['short', 'has space inside', str_repeat('x', 4097)] as $bad) {
    $code = null;
    try {
        $keys->save($bad);
    } catch (RuntimeException $failure) {
        $code = $failure->getMessage();
    }
    check($code === 'licensing_site_key_invalid', 'a malformed key is refused: ' . substr($bad, 0, 10));
}
check($keys->read() === $siteKey, 'a refused key leaves the kept one');
$linked = $fx->folder();
symlink($folder . '/site-key', $linked . '/site-key');
check((new SiteKeyStore($linked . '/site-key'))->read() === null, 'a link in place of the key file is not read');
$code = null;
try {
    (new SiteKeyStore($linked . '/site-key'))->save($siteKey);
} catch (RuntimeException $failure) {
    $code = $failure->getMessage();
}
check($code === 'licensing_site_key_unwritable', 'a link in place of the key file is not written through');
unlink($linked . '/site-key');

// --- The licence: refresh, key entry, file load.
$siteFolder = $fx->folder();
$licensing = $fx->site($siteFolder . '/snapshot.json');
$siteKeys = new SiteKeyStore($siteFolder . '/site-key');
$licence = new SiteLicence($licensing, $siteKeys, static fn (?string $given) => $source($given ?? $siteKeys->read()), null);
$view = $licence->overview();
check($view['state'] === 'missing' && $view['key_set'] === false && $view['key_source'] === null, 'no snapshot and no key at first');
$result = $licence->refresh();
check(!$result->done && $result->reasonCode === 'licensing_site_key_missing' && $server->log() === [], 'refresh without a key asks nothing');

$good = $fx->envelope($key, ['capabilities' => [$chat], 'issued_at' => '2026-11-25T11:00:00+00:00', 'valid_until' => '2026-11-26T11:00:00+00:00',
    'licence_until' => '2027-11-25T00:00:00+00:00', 'updates_until' => ['*' => '2027-11-25T00:00:00+00:00', 'larena/chat' => '2027-06-01T00:00:00+00:00']]);
$server->answers = ['/entitlement' => [200, $good]];
$result = $licence->enterKey($siteKey);
check($result->done && $result->reasonCode === 'site_key_saved', 'a key entered is kept and the licence fetched with it');
check(count($server->log()) === 1 && $server->log()[0]['url'] === 'https://updates.example.com/r/larena/stable/entitlement', 'only the update server is asked, not github');
check($server->log()[0]['headers']['Authorization'] === 'Bearer ' . $siteKey, 'the new key goes as Bearer');
$view = $licence->overview();
check($view['state'] === 'active' && $view['key_set'] && $view['key_source'] === SiteLicence::KEY_STORED, 'active with a stored key');
check($view['licence_until'] === '2027-11-25T00:00:00+00:00' && $view['updates_until']['larena/chat'] === '2027-06-01T00:00:00+00:00', 'licence and update dates');
check(!str_contains(json_encode($view, JSON_THROW_ON_ERROR), $siteKey) && !str_contains(json_encode($result->toArray(), JSON_THROW_ON_ERROR), $siteKey), 'the key is never shown');

$server->reset();
$server->answers = ['/entitlement' => [401, '{"error":"site_key_unknown"}']];
$result = $licence->refresh();
check(!$result->done && $result->reasonCode === 'licensing_refresh_refused' && $licence->overview()['state'] === 'active', 'a refused refresh keeps the last good snapshot');
$server->down = true;
check($licence->refresh()->reasonCode === 'licensing_refresh_failed', 'a server down: refresh failed');
$server->down = false;
$server->answers = ['/entitlement' => [200, $forger->envelope($key, ['issued_at' => '2026-11-25T11:30:00+00:00'])]];
$result = $licence->refresh();
check(!$result->done && $result->reasonCode === 'licensing_snapshot_refused' && $result->detail === 'licensing_signature_invalid', 'a forged answer is refused with the verifier code');

// A key in the configuration wins: the admin cannot replace it.
$configured = new SiteLicence($licensing, $siteKeys, static fn (?string $given) => $source($given), 'env-key-' . bin2hex(random_bytes(8)));
check($configured->overview()['key_source'] === SiteLicence::KEY_FROM_CONFIG, 'the configuration key is named as such');
check($configured->enterKey('another-key-123')->reasonCode === 'licensing_site_key_from_config' && $siteKeys->read() === $siteKey, 'a configured key is not replaced');
// A malformed key is not kept; a key with no update server is kept but not checked.
check($licence->enterKey('bad key')->reasonCode === 'licensing_site_key_invalid' && $siteKeys->read() === $siteKey, 'a malformed key is not kept');
$offline = new SiteLicence($licensing, $siteKeys, static fn (?string $given) => $source($given, ['https://github.com/simai/larena']), null);
$second = 'site-key-' . bin2hex(random_bytes(12));
check($offline->enterKey($second)->reasonCode === 'site_key_saved_not_checked' && $siteKeys->read() === $second, 'a key without an update server is kept, not checked');
$siteKeys->save($siteKey);

// A file for a closed network: must name this site, verifies, is newer than the kept one.
$fileSite = $fx->site($fx->folder() . '/snapshot.json');
$fileLicence = new SiteLicence($fileSite, new SiteKeyStore($fx->folder() . '/site-key'), static fn (?string $given) => $source($given), null);
check($fileLicence->load('')->reasonCode === 'licensing_file_unreadable', 'an empty file is refused');
$unbound = $fx->envelope($key, ['issued_at' => '2026-11-25T11:00:00+00:00']);
$result = $fileLicence->load($unbound);
check(!$result->done && $result->detail === 'licensing_snapshot_not_bound', 'a file without the site address is refused');
$bound = $fx->envelope($key, ['issued_at' => '2026-11-25T11:00:00+00:00', 'site_hosts' => ['cms.example.com'], 'fresh_until' => '2027-05-25T11:00:00+00:00']);
check($fileLicence->load($bound)->reasonCode === 'licence_loaded' && $fileLicence->overview()['state'] === 'active', 'a bound file is loaded');

// --- A coupon: the shape of Larena Server, every refusal, the answer verified.
$couponSite = $fx->site($fx->folder() . '/snapshot.json');
$coupon = new SiteCoupon($couponSite, static fn () => $source($siteKey));
$server->reset();
check($coupon->redeem('x')->reasonCode === 'coupon_malformed' && $server->log() === [], 'a malformed coupon asks nothing');
check((new SiteCoupon($couponSite, static fn () => $source(null)))->redeem('LARENA-2026-ABCD')->reasonCode === 'site_key_required', 'no site key: nothing asked');
foreach (SiteCoupon::SERVER_REFUSALS as $refusal) {
    $server->reset();
    $server->answers = ['/coupon' => [409, json_encode(['error' => $refusal], JSON_THROW_ON_ERROR)]];
    $result = $coupon->redeem('larena 2026 abcd');
    check(!$result->done && $result->reasonCode === $refusal && $result->requested, 'the server refusal comes back: ' . $refusal);
    check(count($server->log()) === 1, 'a named refusal asks no other source: ' . $refusal);
}
$server->reset();
$granted = json_decode($fx->envelope($key, ['capabilities' => [$docara], 'issued_at' => '2026-11-25T11:00:00+00:00',
    'updates_until' => ['larena/docara' => '2027-11-26T00:00:00+00:00']]), true, 8, JSON_THROW_ON_ERROR);
$granted['schema'] = 'larena.entitlement-envelope';
$granted['schema_version'] = 1;
$granted['coupon'] = ['mode' => 'granted', 'offer' => 'docara-addon', 'ends_at' => '2027-11-26T00:00:00+00:00'];
$server->answers = ['/coupon' => [200, json_encode($granted, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)]];
$result = $coupon->redeem('  larena 2026 abcd ');
check($result->done && $result->reasonCode === 'coupon_granted' && $result->offer === 'docara-addon' && $result->endsAt?->format('Y-m-d') === '2027-11-26', 'a coupon is redeemed');
$sent = json_decode((string) $server->log()[0]['body'], true, 4, JSON_THROW_ON_ERROR);
check($server->log()[0]['url'] === 'https://updates.example.com/r/larena/stable/coupon' && $sent === ['code' => 'larena 2026 abcd'], 'POST <source>/coupon {"code"}');
check($server->log()[0]['headers']['Authorization'] === 'Bearer ' . $siteKey, 'the coupon goes with the site key');
check($couponSite->allows($docara) && $couponSite->updatesUntil('larena/docara')?->format('Y-m-d') === '2027-11-26', 'the addon is in the kept snapshot');
check(!str_contains(json_encode($result->toArray(), JSON_THROW_ON_ERROR), 'abcd'), 'the coupon is not in the result');
$granted['coupon']['mode'] = 'renewed';
$renewed = json_decode($fx->envelope($key, ['capabilities' => [$docara], 'issued_at' => '2026-11-25T11:10:00+00:00']), true, 8, JSON_THROW_ON_ERROR);
$renewed['coupon'] = ['mode' => 'renewed', 'offer' => 'docara-addon', 'ends_at' => '2028-11-26T00:00:00+00:00'];
$server->answers = ['/coupon' => [200, json_encode($renewed, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)]];
check($coupon->redeem('LARENA-2026-EFGH')->reasonCode === 'coupon_renewed', 'a renewal says so');
$server->answers = ['/coupon' => [200, $forger->envelope($key, ['issued_at' => '2026-11-25T11:20:00+00:00'])]];
$result = $coupon->redeem('LARENA-2026-IJKL');
check(!$result->done && $result->reasonCode === 'coupon_answer_refused' && $result->detail === 'licensing_signature_invalid', 'a forged coupon answer is refused');
$server->answers = ['/coupon' => [500, 'oops']];
check($coupon->redeem('LARENA-2026-MNOP')->reasonCode === 'coupon_request_failed', 'an unnamed answer is a failed request');

// --- The addon list: from the catalog without a kept list, from the server once fetched; states decided by the snapshot.
$addonsSite = $fx->site($fx->folder() . '/snapshot.json');
$addonsPath = $fx->folder() . '/addons.json';
$installed = ['larena/chat' => true];
$addons = new SiteAddons($addonsSite, $catalog, static fn () => $source($siteKey), $addonsPath,
    static fn (string $package): bool => $installed[$package] ?? false, 'https://shop.example.com/larena');
$list = $addons->list();
check($list['source'] === 'catalog' && $list['addons'] !== [], 'without a kept list the catalog stands in');
$chatAddon = $addons->get('larena-chat');
check($chatAddon !== null && $chatAddon['state'] === SiteAddons::AVAILABLE && $chatAddon['installed'] && $chatAddon['trial_capability'] === $chat
    && $chatAddon['trial_days'] === 14 && $chatAddon['shop_url'] === 'https://shop.example.com/larena', 'an installed addon not held offers 14 days and the shop');
$server->answers = ['/addons' => [200, json_encode(['schema' => 'larena.addon-list', 'schema_version' => 1, 'product' => 'larena', 'addons' => [
    ['offer' => 'docara-addon', 'title' => 'Docara', 'capabilities' => [$docara], 'packages' => ['larena/docara'], 'state' => 'held', 'until' => null,
        'shop_url' => 'https://shop.example.com/docara'],
    ['offer' => 'chat-addon', 'title' => 'Чат', 'capabilities' => [$chat], 'packages' => ['larena/chat'], 'state' => 'available', 'until' => null, 'shop_url' => null],
    ['offer' => 'Bad Offer', 'capabilities' => [], 'packages' => []],
    ['offer' => 'evil', 'title' => 'x', 'capabilities' => [], 'packages' => [], 'shop_url' => 'javascript:alert(1)'],
]], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)]];
$server->reset();
check($addons->refresh()->reasonCode === 'addons_refreshed', 'the list is fetched');
check($server->log()[0]['url'] === 'https://updates.example.com/r/larena/stable/addons' && $server->log()[0]['body'] === null, 'GET <source>/addons');
$list = $addons->list();
check($list['source'] === 'server' && count($list['addons']) === 3, 'the server list is kept; a bad offer is dropped');
$docaraAddon = $addons->get('docara-addon');
check($docaraAddon['state'] === SiteAddons::AVAILABLE && !$docaraAddon['installed'], 'the server says held, the snapshot does not: available (the snapshot decides)');
check($addons->get('evil')['shop_url'] === 'https://shop.example.com/larena', 'a non-https shop address is dropped for the site one');
// After a coupon the snapshot holds Docara: held, with its «updates until».
$heldEnvelope = $fx->envelope($key, ['capabilities' => [$docara], 'issued_at' => '2026-11-25T11:00:00+00:00', 'updates_until' => ['larena/docara' => '2027-11-26T00:00:00+00:00']]);
$addonsSite->accept($heldEnvelope, 'refresh');
$docaraAddon = $addons->get('docara-addon');
check($docaraAddon['state'] === SiteAddons::HELD && $docaraAddon['until'] === '2027-11-26T00:00:00+00:00' && $docaraAddon['trial_capability'] === null, 'held with its updates date');
// A running trial: the trial end.
$trialEnvelope = $fx->envelope($key, ['status' => 'active', 'capabilities' => [$docara, $chat], 'issued_at' => '2026-11-25T11:30:00+00:00',
    'trials' => [$chat => '2026-12-09T11:30:00+00:00']]);
$addonsSite->accept($trialEnvelope, 'refresh');
check($addons->get('chat-addon')['state'] === SiteAddons::TRIAL && $addons->get('chat-addon')['until'] === '2026-12-09T11:30:00+00:00', 'a trial shows its end');
$server->answers = ['/addons' => [200, '{"schema":"other"}']];
check($addons->refresh()->reasonCode === 'addons_list_invalid' && $addons->list()['source'] === 'server', 'an unreadable list keeps the previous one');
$server->down = true;
check($addons->refresh()->reasonCode === 'addons_request_failed', 'a server down keeps the previous list');
$server->down = false;
// A test address: held, never a trial offer.
$devAddons = new SiteAddons($fx->site($fx->folder() . '/snapshot.json', 'larena.test'), $catalog, static fn () => $source($siteKey), $fx->folder() . '/addons.json',
    static fn (string $package): bool => false);
check($devAddons->get('larena-chat')['state'] === SiteAddons::HELD && $devAddons->get('larena-chat')['trial_capability'] === null, 'a test address holds every addon');

// --- Words for every reason, in both languages; the source never prints the key.
foreach (['ru', 'en'] as $locale) {
    $words = require $root . '/resources/lang/' . $locale . '/licensing.php';
    foreach ([...SiteLicence::REASONS, ...SiteCoupon::REASONS, 'addons_refreshed', 'addons_request_failed', 'addons_list_invalid'] as $reason) {
        check(is_string($words['licence']['reasons'][$reason] ?? null) && $words['licence']['reasons'][$reason] !== '', $locale . ' words for ' . $reason);
    }
    foreach (['active', 'stale', 'expired', 'missing', 'invalid', 'development'] as $state) {
        check(is_string($words['licence']['states'][$state] ?? null), $locale . ' words for state ' . $state);
    }
}
check(!str_contains(print_r($source($siteKey), true), $siteKey), 'the source never prints the key');

echo "SiteLicenceSectionTest passed\n";
