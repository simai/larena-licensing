<?php

declare(strict_types=1);

/*
 * The capability catalog the package ships (`resources/catalog/capabilities.json`), built from the Specs capability
 * registry: tiers, trials, areas and needs all come from there (owner decision 2026-11-25: paid or free is catalog data).
 *
 *   php scripts/refresh-capability-catalog.php           write the catalog from the registry (composer run catalog:refresh)
 *   php scripts/refresh-capability-catalog.php --check   the package gate: the shipped catalog is sound, has words in every
 *                                                        language, and matches a fresh build when Specs is found
 *
 * Specs is found next to the workspace or at LARENA_SPECS_ROOT, as scripts/check-delivery-manifest.php finds it.
 * Without it (package CI) the comparison with the registry is reported as skipped; the local hook always has it.
 */

use Larena\Licensing\Catalog\CapabilityCatalogBuilder;

require_once dirname(__DIR__) . '/vendor/autoload.php';

$root = dirname(__DIR__);
$check = in_array('--check', array_slice($argv, 1), true);
$catalogPath = $root . '/resources/catalog/capabilities.json';
$languages = [];
foreach (['en', 'ru'] as $locale) {
    $path = $root . '/resources/lang/' . $locale . '/capabilities.php';
    $lines = is_file($path) ? require $path : [];
    $languages[$locale] = is_array($lines) ? $lines : [];
}
$specs = null;
foreach ([getenv('LARENA_SPECS_ROOT') ?: '', dirname($root, 3) . '/larena-specs', dirname($root, 2) . '/larena-specs'] as $candidate) {
    if ($candidate !== '' && is_file($candidate . '/specs/capabilities/registry.json')) {
        $specs = $candidate;
        break;
    }
}
$registry = $specs === null ? null : readJson($specs . '/specs/capabilities/registry.json');
$errors = [];

if (!$check) {
    if ($registry === null) {
        fwrite(STDERR, "The Specs capability registry is needed to refresh the catalog (set LARENA_SPECS_ROOT).\n");
        exit(1);
    }
    $missing = CapabilityCatalogBuilder::registryErrors($registry);
    if ($missing !== []) {
        fwrite(STDERR, "The Specs registry has no areas or needs for these (specs/capabilities/placement.json):\n- " . implode("\n- ", $missing) . "\n");
        exit(1);
    }
    $catalog = CapabilityCatalogBuilder::build($registry);
    file_put_contents($catalogPath, CapabilityCatalogBuilder::encode($catalog));
    echo 'Capability catalog written: ' . count($catalog['capabilities']) . " capabilities.\n";
    exit(0);
}

$shipped = readJson($catalogPath);
if ($shipped === null) {
    $errors[] = 'catalog_missing_or_unreadable';
    $shipped = [];
}
$errors = [...$errors, ...CapabilityCatalogBuilder::errors($shipped, $languages)];
$registryCheck = 'skipped_specs_not_found';
if ($registry !== null) {
    $registryCheck = 'passed';
    $errors = [...$errors, ...CapabilityCatalogBuilder::registryErrors($registry)];
    if (CapabilityCatalogBuilder::encode(CapabilityCatalogBuilder::build($registry)) !== CapabilityCatalogBuilder::encode($shipped)) {
        $errors[] = 'catalog_differs_from_registry:run composer run catalog:refresh';
        $registryCheck = 'failed';
    }
}

echo json_encode([
    'schema' => 'larena.capability_catalog_check',
    'package' => 'larena/licensing',
    'status' => $errors === [] ? 'passed' : 'failed',
    'registry_check' => $registryCheck,
    'capabilities' => is_array($shipped['capabilities'] ?? null) ? count($shipped['capabilities']) : 0,
    'errors' => $errors,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($errors === [] ? 0 : 1);

/** @return array<string, mixed>|null */
function readJson(string $path): ?array
{
    $bytes = is_file($path) ? file_get_contents($path) : false;
    $decoded = is_string($bytes) ? json_decode($bytes, true) : null;

    return is_array($decoded) ? $decoded : null;
}
