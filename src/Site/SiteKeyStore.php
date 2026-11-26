<?php

declare(strict_types=1);

namespace Larena\Licensing\Site;

use RuntimeException;

/**
 * The site key entered in the admin («Лицензия и обновления» → «Лицензия»), kept as one file outside the web root
 * (`storage/app/larena/licensing/site-key` by default, mode 0600), written whole to a temporary file and renamed; a link
 * in its place is refused. A key set in the site's configuration (`LARENA_UPDATE_SITE_KEY`) stands over the kept one.
 *
 * The key is never read back to a screen, a log or a reason: {@see has()} says only whether one is kept. Failures are a
 * RuntimeException with a stable code: `licensing_site_key_invalid`, `licensing_site_key_unwritable`.
 */
final readonly class SiteKeyStore
{
    /** What a site key may hold: printable ASCII without spaces, as the update client sends it in a header. */
    public const string FORMAT = '/\A[\x21-\x7e]{8,4096}\z/D';

    public function __construct(private string $path)
    {
    }

    public function has(): bool
    {
        return $this->read() !== null;
    }

    /** The kept key, for the sources only (never shown). */
    public function read(): ?string
    {
        if (is_link($this->path) || !is_file($this->path)) {
            return null;
        }
        $bytes = @file_get_contents($this->path, false, null, 0, 4097);
        $key = is_string($bytes) ? trim($bytes) : '';

        return preg_match(self::FORMAT, $key) === 1 ? $key : null;
    }

    public function save(#[\SensitiveParameter] string $key): void
    {
        $key = trim($key);
        if (preg_match(self::FORMAT, $key) !== 1) {
            throw new RuntimeException('licensing_site_key_invalid');
        }
        $directory = dirname($this->path);
        if (!is_dir($directory) && !@mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new RuntimeException('licensing_site_key_unwritable');
        }
        if (is_link($this->path)) {
            throw new RuntimeException('licensing_site_key_unwritable');
        }
        $temporary = $directory . '/.site-key-' . bin2hex(random_bytes(6)) . '.tmp';
        $old = umask(0077);
        try {
            $written = @file_put_contents($temporary, $key, LOCK_EX);
        } finally {
            umask($old);
        }
        if ($written !== strlen($key)) {
            @unlink($temporary);
            throw new RuntimeException('licensing_site_key_unwritable');
        }
        @chmod($temporary, 0600);
        if (!@rename($temporary, $this->path)) {
            @unlink($temporary);
            throw new RuntimeException('licensing_site_key_unwritable');
        }
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['path' => $this->path, 'key' => $this->has() ? '[set]' : null];
    }
}
