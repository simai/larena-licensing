<?php

declare(strict_types=1);

use Larena\Licensing\Catalog\CapabilityCatalog;
use Larena\Licensing\Catalog\CapabilityCatalogBuilder;
use Larena\Licensing\Catalog\SiteCapabilities;
use Larena\Licensing\Site\SnapshotStore;

require_once __DIR__ . '/../support/site-licensing-fixtures.php';

// The capability catalog shipped with the package (owner decision 2026-11-25): free by default from the catalog, paid
// only with the snapshot, and a check that the shipped catalog keeps up with the Specs registry.
$root = dirname(__DIR__, 2);
$catalog = CapabilityCatalog::shipped();
$fx = new SiteLicensingFixture();
$fx->key('larena-entitlement-test');
$free = 'larena.capability.content.base';
$paid = 'larena.capability.chat.base';

// 1. The shipped catalog: 48 functions, the free ones free, the paid ones not, each with an area and its words.
check(count($catalog->all()) === 48, 'the catalog ships every registry capability');
check($catalog->get($free)?->isFree() === true && $catalog->get($paid)?->isFree() === false, 'tiers come from the registry');
check(in_array($free, $catalog->freeIds(), true) && !in_array($paid, $catalog->freeIds(), true), 'free ids');
check($catalog->get($paid)?->trialEligible === true && $catalog->get('larena.capability.telephony.enterprise_operations')?->tier === 'enterprise', 'trial and enterprise kept');
$languages = ['en' => require $root . '/resources/lang/en/capabilities.php', 'ru' => require $root . '/resources/lang/ru/capabilities.php'];
$shipped = json_decode((string) file_get_contents(CapabilityCatalog::SHIPPED), true);
check(is_array($shipped) && CapabilityCatalogBuilder::errors($shipped, $languages) === [], 'the shipped catalog is sound and has words in en and ru');

// 2. The bug of 2026-11-25: on a real site (not a test address) without a snapshot a free function was denied.
$store = $fx->folder() . '/snapshot.json';
$site = $fx->site($store, free: $catalog->freeIds());
check($site->allows($free) && $site->decide($free)->reasonCode === 'capability_free', 'a free capability is allowed without a snapshot on a real address');
check(!$site->allows($paid) && $site->decide($paid)->reasonCode === 'snapshot_missing', 'a paid capability is denied without a snapshot');
$site->accept($fx->envelope('larena-entitlement-test', ['capabilities' => [$paid]]), SnapshotStore::REFRESH);
check($fx->site($store, free: $catalog->freeIds())->allows($paid), 'a paid capability the snapshot lists is allowed');
check(!$fx->site($store, free: $catalog->freeIds())->allows('larena.capability.workflow.base'), 'a paid capability the snapshot does not list is denied');
// The provider gives the site the catalog's free functions together with the configured ones.
$provider = (string) file_get_contents($root . '/src/Laravel/LicensingServiceProvider.php');
check(str_contains($provider, '...$app->make(CapabilityCatalog::class)->freeIds(),'), 'the provider passes the catalog free list');

// 3. The read model: each function with its decision; a test address opens paid ones as development.
$entries = (new SiteCapabilities($catalog, $fx->site($fx->folder() . '/s.json', free: $catalog->freeIds())))->all();
$byId = array_column($entries, null, 'id');
check(count($entries) === 48 && $byId[$free]['decision'] === SiteCapabilities::ALLOWED && $byId[$free]['free'] === true, 'free: allowed');
check($byId[$paid]['decision'] === SiteCapabilities::NEEDS_LICENCE && $byId[$paid]['trial_eligible'] === true && $byId[$paid]['package'] === 'larena/chat', 'paid: needs a licence');
check(str_starts_with($byId[$paid]['title_key'], 'larena-licensing::capabilities.items.chat.base'), 'plain title key');
$dev = (new SiteCapabilities($catalog, $fx->site($fx->folder() . '/s.json', host: 'larena.test', free: $catalog->freeIds())))->get($paid);
check($dev !== null && $dev['decision'] === SiteCapabilities::DEVELOPMENT, 'paid on a test address: development');
$devFree = (new SiteCapabilities($catalog, $fx->site($fx->folder() . '/s.json', host: 'larena.test', free: $catalog->freeIds())))->get($free);
check($devFree !== null && $devFree['decision'] === SiteCapabilities::ALLOWED, 'free on a test address: allowed, not development');

// 4. Dependencies: needed ones first, the system itself left out; what switching off takes along.
check($catalog->requiredFor($free) === ['larena.capability.search.base', 'larena.capability.storage.base'], 'content needs search and storages');
check($catalog->requiredFor('larena.capability.docara.base') === ['larena.capability.search.base', 'larena.capability.storage.base',
    'larena.capability.content.base', 'larena.capability.layout.base'], 'needs of needs, in order');
check($catalog->requiredFor('larena.capability.telephony.advanced_operations') === ['larena.capability.telephony.base'], 'a feature needs its package baseline');
check($catalog->requiredFor('larena.capability.core.distributed_cluster') === [], 'the always-on core is not switched');
check($catalog->dependantsOf('larena.capability.search.base') === [$free, 'larena.capability.docara.base'], 'what needs search');

// 5. The check catches drift: a tier changed in the registry, a registry capability with no area, missing words;
//    a capability the registry marks planned stays out of the catalog.
$specs = null;
foreach ([getenv('LARENA_SPECS_ROOT') ?: '', dirname($root, 3) . '/larena-specs'] as $candidate) {
    if ($candidate !== '' && is_file($candidate . '/specs/capabilities/registry.json')) {
        $specs = $candidate;
    }
}
$registry = $specs !== null ? json_decode((string) file_get_contents($specs . '/specs/capabilities/registry.json'), true)
    : ['registry_id' => (string) $shipped['source']['registry_id'], 'schema' => (string) $shipped['source']['registry_schema'],
        'areas' => $shipped['areas'], 'packages' => [], 'capabilities' => array_map(static fn (array $entry): array => ['id' => $entry['id'],
            'package' => $entry['package'], 'kind' => $entry['kind'], 'default_tier' => $entry['tier'], 'status' => 'declared',
            'trial_eligible' => $entry['trial_eligible'], 'area' => $entry['area'], 'needs' => $entry['requires'],
            'always_on' => $entry['always_on']], $shipped['capabilities'])];
if ($specs !== null) {
    check(CapabilityCatalogBuilder::encode(CapabilityCatalogBuilder::build($registry)) === CapabilityCatalogBuilder::encode($shipped), 'shipped catalog matches the registry');
    check(CapabilityCatalogBuilder::registryErrors($registry) === [], 'the registry places every built capability');
}
$changed = $registry;
foreach ($changed['capabilities'] as $index => $entry) {
    if ($entry['id'] === $paid) {
        $changed['capabilities'][$index]['default_tier'] = 'free';
    }
}
check(CapabilityCatalogBuilder::encode(CapabilityCatalogBuilder::build($changed)) !== CapabilityCatalogBuilder::encode($shipped), 'a tier changed in the registry is drift');
$added = $registry;
$added['capabilities'][] = ['id' => 'larena.capability.chat.video', 'package' => 'larena/chat', 'kind' => 'extension_family', 'default_tier' => 'paid', 'status' => 'declared', 'trial_eligible' => false];
check(CapabilityCatalogBuilder::registryErrors($added) === ['registry_capability_unplaced:larena.capability.chat.video'], 'a registry capability without an area is caught');
$planned = $registry;
$planned['capabilities'][] = ['id' => 'larena.capability.shop.wishlist', 'package' => 'larena/shop', 'kind' => 'extension_family', 'default_tier' => 'free', 'status' => 'planned', 'trial_eligible' => false];
check(CapabilityCatalogBuilder::registryErrors($planned) === [], 'a planned capability needs no place yet');
check(CapabilityCatalogBuilder::encode(CapabilityCatalogBuilder::build($planned)) === CapabilityCatalogBuilder::encode(CapabilityCatalogBuilder::build($registry)), 'a planned capability stays out of the catalog');
$moved = $registry;
foreach ($moved['capabilities'] as $index => $entry) {
    if ($entry['id'] === 'larena.capability.link.base') {
        $moved['capabilities'][$index]['needs'] = [$free];
    }
}
check(CapabilityCatalogBuilder::encode(CapabilityCatalogBuilder::build($moved)) !== CapabilityCatalogBuilder::encode($shipped), 'needs changed in the registry are drift');
$noWords = $languages;
unset($noWords['ru']['items']['chat']);
check(in_array('capability_words_missing:ru:' . $paid . ':title_key', CapabilityCatalogBuilder::errors($shipped, $noWords), true), 'missing words are caught');
$cycle = $shipped;
foreach ($cycle['capabilities'] as $index => $entry) {
    if ($entry['id'] === 'larena.capability.search.base') {
        $cycle['capabilities'][$index]['requires'] = [$free];
    }
}
check(in_array('capability_requires_cycle:' . $free, CapabilityCatalogBuilder::errors($cycle, $languages), true), 'a cycle is caught');

// 6. The gate runs the check.
$composer = json_decode((string) file_get_contents($root . '/composer.json'), true);
check(in_array('@catalog:check', $composer['scripts']['quality:gate'], true), 'quality gate runs catalog:check');

echo "CapabilityCatalogTest passed.\n";
