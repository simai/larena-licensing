<?php

declare(strict_types=1);

use Larena\Licensing\Catalog\CapabilityCatalog;
use Larena\Licensing\Catalog\SiteCapabilities;
use Larena\Licensing\Site\SiteTrial;
use Larena\Licensing\Site\SnapshotSource;
use Larena\Licensing\Site\TrialResult;

require_once __DIR__ . '/../support/site-licensing-fixtures.php';

// «Попробовать бесплатно» from the site (Larena Server: POST <update server>/r/<product>[/<channel>]/trial): the same
// sources and site key as the refresh, the answer verified like a refresh and kept only when it verifies, every refusal
// said in words, nothing asked where nothing needs asking. The server here is a fake one that records what it is sent.
$root = dirname(__DIR__, 2);
$catalog = CapabilityCatalog::shipped();
$fx = new SiteLicensingFixture();
$fx->key('larena-entitlement-test');
// The forger signs with a key of its own that the site does not trust.
$forger = new SiteLicensingFixture();
$forger->key('larena-entitlement-forger');
$key = 'larena-entitlement-test';
$chat = 'larena.capability.chat.base';
$siteKey = 'site-key-' . bin2hex(random_bytes(12));

/** A fake update server: records each request and answers what the test set. */
final class FakeTrialServer
{
    /** @var list<array{url:string, headers:array<string,string>, body:?string}> */
    private array $requests = [];

    /** @var array{0:int,1:string} */
    public array $answer = [500, ''];

    public bool $down = false;

    /**
     * What it was sent so far.
     *
     * @return list<array{url:string, headers:array<string,string>, body:?string}>
     */
    public function requests(): array
    {
        return $this->requests;
    }

    public function reset(): void
    {
        $this->requests = [];
    }

    /** @return Closure(string, array<string,string>, int, int, int, ?string=): array{0:int,1:string} */
    public function transport(): Closure
    {
        return function (string $url, array $headers, int $timeout, int $connect, int $limit, ?string $body = null): array {
            $this->requests[] = ['url' => $url, 'headers' => $headers, 'body' => $body];
            if ($this->down) {
                throw new RuntimeException('connection refused');
            }

            return $this->answer;
        };
    }
}

$server = new FakeTrialServer();
$sources = ['https://github.com/simai/larena/releases', 'https://updates.example.com/r/larena/stable/'];
$trialFor = static function (string $store, string $host = 'cms.example.com', ?string $withKey = 'given') use ($fx, $catalog, $server, $sources, $siteKey): array {
    $site = $fx->site($store, $host, free: $catalog->freeIds());
    $source = new SnapshotSource($sources, $withKey === 'given' ? $siteKey : $withKey, $server->transport());

    return [new SiteTrial($site, $catalog, static fn (): SnapshotSource => $source), $site];
};
$trialEnvelope = static fn (array $fields = []): string => $fx->envelope($key, array_merge([
    'status' => 'trial',
    'capabilities' => ['larena.capability.chat.base'],
    'issued_at' => '2026-11-25T11:30:00+00:00',
    'fresh_until' => '2026-12-09T11:30:00+00:00',
], $fields));

// 1. Success: one POST to <source>/trial with the site key as for /entitlement and {"capability": id}; the signed
//    snapshot verified and kept; the function allowed, the trial end read from the snapshot.
$store = $fx->folder() . '/snapshot.json';
[$trial, $site] = $trialFor($store);
check(!$site->allows($chat), 'before the trial a paid function is denied');
$server->answer = [200, json_encode(['schema' => 'larena.entitlement-envelope', 'schema_version' => 1] + json_decode($trialEnvelope(), true), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)];
$result = $trial->request($chat);
check($result->started() && $result->requested && $result->reasonCode === 'trial_started', 'the trial started: ' . json_encode($result->toArray()));
check($result->endsAt?->format(DATE_ATOM) === '2026-12-09T11:30:00+00:00', 'the trial end is the snapshot fresh_until');
check(count($server->requests()) === 1, 'one request, github skipped');
$sent = $server->requests()[0];
check($sent['url'] === 'https://updates.example.com/r/larena/stable/trial', 'POST <source>/trial: ' . $sent['url']);
check($sent['headers'] === ['Authorization' => 'Bearer ' . $siteKey, 'Content-Type' => 'application/json'], 'the site key as for /entitlement');
check($sent['body'] === '{"capability":"larena.capability.chat.base"}', 'the JSON body names the capability');
check(is_file($store), 'the snapshot is kept');
[, $fresh] = $trialFor($store);
check($fresh->allows($chat) && $fresh->decide($chat)->reasonCode === 'licence_trial', 'the kept trial snapshot allows the function');
check($fresh->trialUntil()?->format(DATE_ATOM) === '2026-12-09T11:30:00+00:00', 'the site knows when the trial ends');
$entry = (new SiteCapabilities($catalog, $fresh))->get($chat);
check($entry !== null && $entry['decision'] === SiteCapabilities::ALLOWED && $entry['trial_until'] === '2026-12-09T11:30:00+00:00', 'the screen read model shows the trial end');
check((new SiteCapabilities($catalog, $fresh))->get('larena.capability.content.base')['trial_until'] === null, 'a free function has no trial end');
// A second trial of a function the licence now allows asks nothing.
$server->reset();
[$again] = $trialFor($store);
check($again->request($chat)->reasonCode === 'capability_licensed' && $server->requests() === [], 'already licensed: no request');
check(!str_contains(print_r($result, true) . json_encode($result->toArray()), $siteKey), 'the result never carries the site key');

// 2. Every refusal the server names gives its reason, keeps nothing, and asks no other source.
// The HTTP status the server gives each; every server refusal must be here.
$statuses = [
    'site_key_required' => 401, 'site_key_unknown' => 401, 'capability_unknown' => 404, 'trial_not_eligible' => 422,
    'trial_used' => 409, 'capability_licensed' => 409, 'registration_unavailable' => 503, 'license_signature_invalid' => 503,
];
foreach (SiteTrial::SERVER_REFUSALS as $code) {
    $status = $statuses[$code];
    $store = $fx->folder() . '/snapshot.json';
    $server->reset();
    $server->answer = [$status, '{"error":"' . $code . '"}'];
    $twoServers = new SnapshotSource(['https://updates.example.com/r/larena', 'https://mirror.example.com/r/larena'], $siteKey, $server->transport());
    $site = $fx->site($store, free: $catalog->freeIds());
    $result = (new SiteTrial($site, $catalog, static fn (): SnapshotSource => $twoServers))->request($chat);
    check($result->status === TrialResult::REFUSED && $result->reasonCode === $code && $result->requested, $code . ' is the reason');
    check(count($server->requests()) === 1, $code . ': the answer of the first server stands');
    check(!is_file($store) && !$site->allows($chat), $code . ': nothing kept, still denied');
}
// An answer the server does not name, or no answer at all: the next source is tried, then a plain failure.
$store = $fx->folder() . '/snapshot.json';
$server->reset();
$server->answer = [502, '<html>Bad gateway</html>'];
$twoServers = new SnapshotSource(['https://updates.example.com/r/larena', 'https://mirror.example.com/r/larena'], $siteKey, $server->transport());
$result = (new SiteTrial($fx->site($store, free: $catalog->freeIds()), $catalog, static fn (): SnapshotSource => $twoServers))->request($chat);
check($result->reasonCode === 'trial_request_failed' && count($server->requests()) === 2 && !is_file($store), 'an unnamed answer: both sources tried, nothing kept');
$server->reset();
$server->down = true;
[$trial] = $trialFor($store);
check($trial->request($chat)->reasonCode === 'trial_request_failed' && !is_file($store), 'no connection: a plain failure');
$server->down = false;

// 3. A forged or broken answer is refused and kept nowhere; the kept snapshot stays.
$store = $fx->folder() . '/snapshot.json';
[$trial, $site] = $trialFor($store);
$server->answer = [200, $forger->envelope('larena-entitlement-forger', ['status' => 'trial', 'capabilities' => [$chat], 'issued_at' => '2026-11-25T11:30:00+00:00'])];
$result = $trial->request($chat);
check($result->reasonCode === 'trial_answer_refused' && $result->detail === 'licensing_key_unknown' && !is_file($store), 'an unknown signing key is refused');
$genuine = json_decode($trialEnvelope(), true);
$tampered = json_decode($genuine['entitlement'], true);
$tampered['capabilities'][] = 'larena.capability.workflow.base';
$server->answer = [200, json_encode(['entitlement' => json_encode($tampered, JSON_UNESCAPED_SLASHES), 'signature' => $genuine['signature']], JSON_UNESCAPED_SLASHES)];
$result = $trial->request($chat);
check($result->reasonCode === 'trial_answer_refused' && $result->detail === 'licensing_signature_invalid' && !is_file($store), 'a tampered snapshot is refused');
$server->answer = [200, 'not json'];
check($trial->request($chat)->detail === 'licensing_snapshot_malformed' && !is_file($store), 'a broken answer is refused');
check(!$site->allows($chat), 'still denied after forged answers');
// A genuine answer without the function: kept (it is the server's truth), but the trial did not start.
$server->answer = [200, $trialEnvelope(['capabilities' => ['larena.capability.workflow.base']])];
check($trial->request($chat)->reasonCode === 'trial_not_granted', 'a snapshot without the function is no trial');

// 4. Asked nothing where nothing needs asking.
$server->reset();
$store = $fx->folder() . '/snapshot.json';
[$trial] = $trialFor($store);
check($trial->request('larena.capability.content.base')->reasonCode === 'capability_free', 'a free function needs no trial');
check($trial->request('larena.capability.telephony.enterprise_operations')->reasonCode === 'trial_not_eligible', 'a function without a trial is refused here');
check($trial->request('larena.capability.update_registration.base')->reasonCode === 'trial_not_eligible', 'an enterprise function too');
check($trial->request('larena.capability.no_such.base')->reasonCode === 'capability_unknown', 'an unknown function is refused here');
[$development] = $trialFor($store, 'larena.test');
$answer = $development->request($chat);
check($answer->status === TrialResult::NOT_NEEDED && $answer->reasonCode === 'development_address' && !$answer->requested, 'a test address needs no trial');
[$keyless] = $trialFor($store, 'cms.example.com', null);
check($keyless->request($chat)->reasonCode === 'site_key_required', 'no site key: nothing asked');
[$noServer] = [new SiteTrial($fx->site($store, free: $catalog->freeIds()), $catalog,
    static fn (): SnapshotSource => new SnapshotSource(['https://github.com/simai/larena/releases'], $siteKey, $server->transport()))];
check($noServer->request($chat)->reasonCode === 'licensing_source_missing', 'only github: nowhere to ask');
check($server->requests() === [] && !is_file($store), 'none of these asked the server or kept anything');

// 4b. The exact trial end (Larena Server, larena.entitlement v1 `trials`): `trials[capability]` when the snapshot
//     names it, else the `fresh_until` fallback. It is inside the signed bytes, so it is verified like the rest.
$exactEnd = '2026-12-09T23:59:59+00:00';
$store = $fx->folder() . '/snapshot.json';
[$trial] = $trialFor($store);
$server->reset();
$server->answer = [200, $trialEnvelope(['status' => 'active', 'fresh_until' => '2026-11-27T11:30:00+00:00', 'trials' => [$chat => $exactEnd]])];
$result = $trial->request($chat);
check($result->started() && $result->endsAt?->format(DATE_ATOM) === $exactEnd, 'trials{} present: the trial ends at trials[capability]: ' . json_encode($result->toArray()));
[, $kept] = $trialFor($store);
check($kept->trialUntil($chat)?->format(DATE_ATOM) === $exactEnd, 'trials{} present: the site reads the exact end');
check($kept->trialUntil() === null, 'an active licence with no capability named has no trial end');
$entry = (new SiteCapabilities($catalog, $kept))->get($chat);
check($entry !== null && $entry['decision'] === SiteCapabilities::ALLOWED && $entry['trial_until'] === $exactEnd, 'trials{} present: the screen shows the exact date, not fresh_until');
check($entry['trial_days'] === 14, 'the read model carries the catalog trial length');
// Without trials{} the fallback stays: a trial licence's fresh_until.
$store = $fx->folder() . '/snapshot.json';
[$trial] = $trialFor($store);
$server->answer = [200, $trialEnvelope()];
check($trial->request($chat)->endsAt?->format(DATE_ATOM) === '2026-12-09T11:30:00+00:00', 'trials{} absent: the fresh_until fallback');
[, $kept] = $trialFor($store);
check((new SiteCapabilities($catalog, $kept))->get($chat)['trial_until'] === '2026-12-09T11:30:00+00:00', 'trials{} absent: the screen shows fresh_until');
// A trials{} changed after signing, or a malformed one, is refused and nothing is kept.
$genuine = json_decode($trialEnvelope(['trials' => [$chat => $exactEnd]]), true);
$tampered = json_decode($genuine['entitlement'], true);
$tampered['trials'][$chat] = '2027-12-09T23:59:59+00:00';
foreach ([
    'a trials{} changed after signing' => [json_encode(['entitlement' => json_encode($tampered, JSON_UNESCAPED_SLASHES), 'signature' => $genuine['signature']], JSON_UNESCAPED_SLASHES), 'licensing_signature_invalid'],
    'a trials{} for a capability not granted' => [$trialEnvelope(['trials' => ['larena.capability.workflow.base' => $exactEnd]]), 'licensing_snapshot_malformed'],
    'a trials{} that is a list' => [$trialEnvelope(['trials' => [$exactEnd]]), 'licensing_snapshot_malformed'],
    'a trials{} with a date that is not ATOM' => [$trialEnvelope(['trials' => [$chat => '09.12.2026']]), 'licensing_snapshot_malformed'],
    'a trials{} ending before the issue date' => [$trialEnvelope(['trials' => [$chat => '2026-11-01T00:00:00+00:00']]), 'licensing_snapshot_malformed'],
] as $case => [$answer, $code]) {
    $store = $fx->folder() . '/snapshot.json';
    [$trial] = $trialFor($store);
    $server->answer = [200, $answer];
    $result = $trial->request($chat);
    check($result->reasonCode === 'trial_answer_refused' && $result->detail === $code && !is_file($store), $case . ' is refused: ' . json_encode($result->toArray()));
}

// 5. Words for every reason in ru and en; the command and the provider.
foreach (['en', 'ru'] as $locale) {
    $lines = require $root . '/resources/lang/' . $locale . '/licensing.php';
    check(is_string($lines['trial']['started'] ?? null) && str_contains($lines['trial']['started'], ':date'), $locale . ' trial started words');
    foreach (SiteTrial::REASONS as $reason) {
        check(is_string($lines['trial']['reasons'][$reason] ?? null) && $lines['trial']['reasons'][$reason] !== '', $locale . ' words for ' . $reason);
    }
}
$command = (string) file_get_contents($root . '/src/Laravel/Console/LicensingTrialCommand.php');
check(str_contains($command, "protected \$signature = 'larena:licensing:trial {capability") && !str_contains($command, 'site_key'), 'the command, without the key');
$provider = (string) file_get_contents($root . '/src/Laravel/LicensingServiceProvider.php');
check(str_contains($provider, 'LicensingTrialCommand::class]') && str_contains($provider, '$app->make(SnapshotSource::class)'), 'the provider binds the trial on the refresh source');

echo "SiteTrialTest passed.\n";
