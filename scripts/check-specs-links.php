<?php

declare(strict_types=1);

/*
 * `composer run specs-links:check`: the package's pointers into Larena Specs (`docs/specs-links.json`, schema
 * larena-specs `schemas/specs-links.schema.json`, rule `docs/standards/package-repository-structure.md#specs-links`)
 * are alive and not stale. Shared knowledge lives only in Specs; the package's docs hold its own design and this one
 * pointer file (owner decision 2026-11-26, variant C).
 *
 * Specs is found at LARENA_SPECS_ROOT or next to the workspace, as for `delivery:check`. Without it (package CI) the
 * check says `skipped_specs_not_found` and passes; the local hook always has it. Refusals:
 * - `specs_links_invalid`: the file does not keep the schema (or repeats a path + anchor);
 * - `specs_link_broken`: the file or the section is missing in the current Specs;
 * - `specs_link_commit_unknown`: `specs_commit` is not in the Specs history;
 * - `specs_link_stale`: the section's text at `specs_commit` differs from the current Specs: read it again, then move
 *   `specs_commit` forward;
 * - `specs_links_missing`: this package has moved to pointers and the file is gone.
 *
 * Usage: php scripts/check-specs-links.php [package root] [--specs=<folder>]
 */

$root = realpath($argv[1] ?? '') ?: dirname(__DIR__);
$specs = null;
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--specs=')) {
        $specs = substr($argument, 8);
    } elseif (!str_starts_with($argument, '--')) {
        $root = realpath($argument) ?: $argument;
    }
}

$fail = static function (string $code, string $detail = ''): never {
    fwrite(STDERR, 'specs-links:check FAILED ' . $code . ($detail !== '' ? ': ' . $detail : '') . PHP_EOL);
    exit(1);
};

$file = $root . '/docs/specs-links.json';
if (!is_file($file)) {
    // licensing and update moved to pointers first (owner 2026-11-26): the file must stay.
    $fail('specs_links_missing', 'docs/specs-links.json');
}
try {
    $links = json_decode((string) file_get_contents($file), true, 16, JSON_THROW_ON_ERROR);
} catch (JsonException) {
    $fail('specs_links_invalid', 'not JSON');
}
$allowed = ['schema', 'schema_version', 'repository', 'specs_repo', 'specs_commit', 'links'];
if (!is_array($links) || array_diff(array_keys($links), $allowed) !== [] || array_diff($allowed, array_keys($links)) !== []
    || $links['schema'] !== 'larena.specs_links' || $links['schema_version'] !== 1 || $links['specs_repo'] !== 'simai/larena-specs'
    || !is_string($links['repository']) || preg_match('#\A[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+\z#', $links['repository']) !== 1
    || !is_string($links['specs_commit']) || preg_match('/\A[0-9a-f]{40}\z/', $links['specs_commit']) !== 1
    || !is_array($links['links']) || $links['links'] === [] || !array_is_list($links['links'])) {
    $fail('specs_links_invalid', 'the top-level fields');
}
$seen = [];
foreach ($links['links'] as $index => $link) {
    $keys = is_array($link) ? array_keys($link) : [];
    if (!is_array($link) || array_diff($keys, ['path', 'anchor', 'why']) !== [] || !is_string($link['path'] ?? null) || !is_string($link['why'] ?? null)
        || $link['path'] === '' || str_starts_with($link['path'], '/') || str_contains($link['path'], '\\') || preg_match('#(^|/)\.\.(/|$)#', $link['path']) === 1
        || trim($link['why']) === '' || mb_strlen($link['why']) > 300 || str_contains($link['why'], "\n")
        || (array_key_exists('anchor', $link) && (!is_string($link['anchor']) || preg_match('/\A[^\s#]+\z/', $link['anchor']) !== 1))) {
        $fail('specs_links_invalid', 'links[' . $index . ']');
    }
    $pair = $link['path'] . '#' . ($link['anchor'] ?? '');
    if (isset($seen[$pair])) {
        $fail('specs_links_invalid', 'repeated ' . $pair);
    }
    $seen[$pair] = true;
}

if ($specs === null) {
    foreach ([getenv('LARENA_SPECS_ROOT') ?: '', dirname($root, 3) . '/larena-specs', dirname($root, 2) . '/larena-specs'] as $candidate) {
        if ($candidate !== '' && is_dir($candidate . '/docs') && is_dir($candidate . '/schemas')) {
            $specs = $candidate;
            break;
        }
    }
}
if ($specs === null || !is_dir($specs)) {
    echo 'specs-links:check passed (schema only): skipped_specs_not_found' . PHP_EOL;
    exit(0);
}

$git = static function (array $arguments) use ($specs): ?string {
    // proc_open keeps the bytes as they are (exec() would trim each line).
    $process = proc_open(['git', '-C', $specs, ...$arguments], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        return null;
    }
    $output = (string) stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return proc_close($process) === 0 ? $output : null;
};
$commit = $links['specs_commit'];
if ($git(['cat-file', '-e', $commit . '^{commit}']) === null) {
    $fail('specs_link_commit_unknown', $commit);
}

/** The GitHub slug of a heading: lower case, only letters, digits, spaces, - and _; each space a dash. */
$slug = static fn (string $heading): string => str_replace(' ', '-', (string) preg_replace('/[^\p{L}\p{N} _-]/u', '', mb_strtolower(trim($heading))));

/** The section an anchor names (to the next heading of the same or a higher level), the whole text without one; null when absent. */
$section = static function (string $text, ?string $anchor) use ($slug): ?string {
    if ($anchor === null) {
        return $text;
    }
    $lines = preg_split('/\R/', $text) ?: [];
    $start = null;
    $level = null;
    foreach ($lines as $number => $line) {
        if ($start === null && str_contains($line, '<a id="' . $anchor . '"></a>')) {
            $start = $number;
            continue;
        }
        if (preg_match('/\A(#{1,6})\s+(.*)\z/', $line, $heading) === 1) {
            if ($start === null && $slug($heading[2]) === $anchor) {
                $start = $number;
                $level = strlen($heading[1]);
                continue;
            }
            if ($start !== null && $level === null) {
                $level = strlen($heading[1]);
                continue;
            }
            if ($start !== null && strlen($heading[1]) <= $level) {
                return implode("\n", array_slice($lines, $start, $number - $start));
            }
        }
    }

    return $start === null ? null : implode("\n", array_slice($lines, $start));
};

$checked = 0;
foreach ($links['links'] as $link) {
    $path = $specs . '/' . $link['path'];
    $anchor = $link['anchor'] ?? null;
    $label = $link['path'] . ($anchor !== null ? '#' . $anchor : '');
    if (!is_file($path)) {
        $fail('specs_link_broken', $label);
    }
    $now = $section((string) file_get_contents($path), $anchor);
    if ($now === null) {
        $fail('specs_link_broken', $label);
    }
    $then = $git(['show', $commit . ':' . $link['path']]);
    $then = $then === null ? null : $section($then, $anchor);
    if ($then === null || trim($then) !== trim($now)) {
        $fail('specs_link_stale', $label . ' changed after ' . substr($commit, 0, 12));
    }
    ++$checked;
}

echo 'specs-links:check passed: ' . $checked . ' links at ' . substr($commit, 0, 12) . PHP_EOL;
