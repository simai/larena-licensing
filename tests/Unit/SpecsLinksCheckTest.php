<?php

declare(strict_types=1);

// `composer run specs-links:check` (docs/specs-links.json, owner decision 2026-11-26): against a throwaway Specs git
// repository it passes live pointers and catches a dead file, a dead section, an unknown commit, a stale section, a bad
// file and a missing one; without Specs it checks the shape only and says so.
$script = dirname(__DIR__, 2) . '/scripts/check-specs-links.php';
$base = sys_get_temp_dir() . '/larena-specs-links-' . bin2hex(random_bytes(6));
$specs = $base . '/specs';
$package = $base . '/package';
mkdir($specs . '/docs/architecture', 0700, true);
mkdir($specs . '/schemas', 0700, true);
mkdir($package . '/docs', 0700, true);

$assertThat = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException('SpecsLinksCheckTest FAILED: ' . $message);
    }
};

$run = static function (array $command, ?string $cwd = null): array {
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $cwd, ['GIT_CONFIG_GLOBAL' => '/dev/null', 'GIT_CONFIG_NOSYSTEM' => '1',
        'HOME' => sys_get_temp_dir(), 'PATH' => (string) getenv('PATH'), 'LARENA_SPECS_ROOT' => '']);
    $out = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($process), $out];
};

$doc = "# Документ\n\n<a id=\"rights\"></a>\n\n## 1. Права\n\nСайт решает по снимку.  \n\n### 1.1 Подробно\n\nТекст.\n\n## 2. Другое\n\nДругое.\n";
file_put_contents($specs . '/docs/architecture/doc.md', $doc);
$git = static fn (string ...$arguments): array => $run(['git', '-C', $specs, '-c', 'user.name=Test', '-c', 'user.email=test@example.test', ...$arguments]);
$git('init', '-q');
$git('add', '-A');
$git('commit', '-q', '-m', 'one');
$commit = trim($git('rev-parse', 'HEAD')[1]);
$assertThat(preg_match('/\A[0-9a-f]{40}\z/', $commit) === 1, 'the throwaway Specs has a commit');

$write = static function (array $links, ?string $at = null) use ($package, $commit): void {
    file_put_contents($package . '/docs/specs-links.json', json_encode(['schema' => 'larena.specs_links', 'schema_version' => 1,
        'repository' => 'simai/larena-test', 'specs_repo' => 'simai/larena-specs', 'specs_commit' => $at ?? $commit, 'links' => $links],
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
};
$check = static fn (): array => $run([PHP_BINARY, $script, $package, '--specs=' . $specs]);
$expect = static function (string $code, string $what) use ($check, $assertThat): void {
    [$status, $out] = $check();
    $assertThat($status === 1 && str_contains($out, $code), $what . ' is ' . $code . ' (got: ' . trim($out) . ')');
};

$write([['path' => 'docs/architecture/doc.md', 'anchor' => 'rights', 'why' => 'rights'],
    ['path' => 'docs/architecture/doc.md', 'anchor' => '2-другое', 'why' => 'a heading slug'],
    ['path' => 'docs/architecture/doc.md', 'why' => 'the whole file']]);
[$status, $out] = $check();
$assertThat($status === 0 && str_contains($out, 'passed: 3 links'), 'live pointers pass: ' . $out);

$write([['path' => 'docs/architecture/gone.md', 'why' => 'x']]);
$expect('specs_link_broken', 'a missing file');
$write([['path' => 'docs/architecture/doc.md', 'anchor' => 'no-such-section', 'why' => 'x']]);
$expect('specs_link_broken', 'a dead section');
$write([['path' => 'docs/architecture/doc.md', 'why' => 'x']], str_repeat('ab', 20));
$expect('specs_link_commit_unknown', 'an unknown commit');
$write([['path' => '../outside.md', 'why' => 'x']]);
$expect('specs_links_invalid', 'a path out of Specs');
$write([['path' => 'docs/architecture/doc.md', 'why' => 'x'], ['path' => 'docs/architecture/doc.md', 'why' => 'again']]);
$expect('specs_links_invalid', 'a repeated pointer');
file_put_contents($package . '/docs/specs-links.json', '{"schema":"larena.specs_links"}');
$expect('specs_links_invalid', 'a file without its fields');

// A change inside the section after specs_commit makes the pointer stale; a change in another section does not.
$write([['path' => 'docs/architecture/doc.md', 'anchor' => 'rights', 'why' => 'rights']]);
file_put_contents($specs . '/docs/architecture/doc.md', str_replace('Другое.', 'Другое, изменено.', $doc));
[$status] = $check();
$assertThat($status === 0, 'a change in another section keeps the pointer fresh');
file_put_contents($specs . '/docs/architecture/doc.md', str_replace('Текст.', 'Текст изменён.', $doc));
$expect('specs_link_stale', 'a changed section');
// Trailing spaces in the section are kept as they are: no false stale.
file_put_contents($specs . '/docs/architecture/doc.md', $doc);
[$status] = $check();
$assertThat($status === 0, 'the committed text with its trailing spaces matches');

unlink($package . '/docs/specs-links.json');
$expect('specs_links_missing', 'a package without its pointer file');

// Without Specs the shape is still checked, and the run says it skipped the rest.
$write([['path' => 'docs/architecture/doc.md', 'why' => 'x']]);
[$status, $out] = $run([PHP_BINARY, $script, $package, '--specs=' . $base . '/absent']);
$assertThat($status === 0 && str_contains($out, 'skipped_specs_not_found'), 'without Specs: skipped (' . trim($out) . ')');

$run(['rm', '-rf', $base]);
echo "SpecsLinksCheckTest passed\n";
