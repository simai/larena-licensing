<?php

declare(strict_types=1);

use Larena\Licensing\Site\FileSnapshotStore;
use Larena\Licensing\Site\SiteLicensing;
use Larena\Licensing\Site\SnapshotVerifier;
use Larena\Licensing\Site\TestAddresses;
use Larena\Licensing\Site\TrustedKeys;

require_once __DIR__ . '/../../vendor/autoload.php';

/** Throwaway Ed25519 keys made for one test run; nothing real is ever used. */
final class SiteLicensingFixture
{
    /** @var array<string,string> key id => secret key */
    private array $secrets = [];

    /** @var array<string,string> key id => base64 public key */
    public array $public = [];

    /** @var list<string> */
    private array $folders = [];

    public function key(string $id): void
    {
        $pair = sodium_crypto_sign_keypair();
        $this->secrets[$id] = sodium_crypto_sign_secretkey($pair);
        $this->public[$id] = base64_encode(sodium_crypto_sign_publickey($pair));
    }

    /**
     * The registration server's envelope for these fields (larena.entitlement schema_version 1), signed by `$keyId`.
     *
     * @param array<string,mixed> $fields
     */
    public function envelope(string $keyId, array $fields = []): string
    {
        $bytes = json_encode(array_merge([
            'schema' => 'larena.entitlement',
            'schema_version' => 1,
            'snapshot_key' => 'entitlement.e' . bin2hex(random_bytes(8)),
            'site' => 'site_00000000000000aa',
            'product' => 'larena',
            'status' => 'active',
            'capabilities' => ['update.install', 'telephony.ai_voice'],
            'issued_at' => '2026-11-25T10:00:00+00:00',
            'valid_until' => '2026-11-26T10:00:00+00:00',
        ], $fields), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $signature = json_encode(['key_id' => $keyId, 'signature' => base64_encode(sodium_crypto_sign_detached($bytes, $this->secrets[$keyId]))], JSON_THROW_ON_ERROR);

        return json_encode(['entitlement' => $bytes, 'signature' => $signature], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    public function folder(): string
    {
        $folder = sys_get_temp_dir() . '/larena-licensing-test-' . bin2hex(random_bytes(6));
        mkdir($folder, 0700);
        $this->folders[] = $folder;

        return $folder;
    }

    /**
     * @param list<string> $revoked
     * @param list<string> $free
     */
    public function site(
        string $storePath,
        string $host = 'cms.example.com',
        string $now = '2026-11-25T12:00:00+00:00',
        array $revoked = [],
        array $free = [],
    ): SiteLicensing {
        return new SiteLicensing(
            new SnapshotVerifier(TrustedKeys::from([$this->public], [$revoked]), 'larena'),
            new FileSnapshotStore($storePath),
            new TestAddresses(),
            $host,
            $free,
            static fn (): DateTimeImmutable => new DateTimeImmutable($now),
        );
    }

    public function __destruct()
    {
        foreach ($this->folders as $folder) {
            foreach (glob($folder . '/{,.}*', GLOB_BRACE) ?: [] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            @rmdir($folder);
        }
    }
}

function check(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, 'FAILED: ' . $message . PHP_EOL);
        exit(1);
    }
}

/** The refusal code of a call that must be refused. */
function refusal(callable $call): ?string
{
    try {
        $call();
    } catch (\Larena\Licensing\Site\SnapshotRefused $refused) {
        return $refused->reason();
    }

    return null;
}
