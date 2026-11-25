<?php

declare(strict_types=1);

namespace Larena\Licensing\Site;

/**
 * The public Ed25519 keys that may sign an entitlement snapshot, and the key ids that may not.
 *
 * Same format as the release keys of larena/update (`key id => base64 of 32 bytes`, `id=base64,id2=base64` in
 * `.env`), but a list of its own: the snapshot key lives on the online registration server, the release key stays
 * offline. Were the snapshot key in the release list, a stolen registration server could sign code. The distribution
 * ships the list in `config/larena-entitlement-keys.php`; a site adds keys with `LARENA_LICENSING_TRUSTED_KEYS`.
 *
 * Read strictly: a malformed id or key, or one id given two different keys, is not trusted at all. Revoked ids win
 * over any list, and the release client's revoked ids count here too.
 */
final readonly class TrustedKeys
{
    public const string KEY_ID = '/\A[a-z0-9][a-z0-9._-]{0,63}\z/D';

    /**
     * @param array<string,string> $keys    key id => raw 32-byte public key
     * @param list<string>         $revoked
     */
    private function __construct(private array $keys, private array $revoked)
    {
    }

    /**
     * @param list<mixed> $lists   each `key id => base64` (config arrays)
     * @param list<mixed> $revoked each a list of key ids, or a comma-separated string
     */
    public static function from(array $lists, array $revoked = []): self
    {
        $keys = [];
        $conflicts = [];
        foreach ($lists as $list) {
            foreach (is_array($list) ? $list : [] as $id => $encoded) {
                if (!is_string($id) || !is_string($encoded) || preg_match(self::KEY_ID, $id) !== 1) {
                    continue;
                }
                $raw = self::decode(trim($encoded));
                if ($raw === null) {
                    continue;
                }
                if (isset($keys[$id]) && !hash_equals($keys[$id], $raw)) {
                    $conflicts[$id] = true;
                }
                $keys[$id] = $raw;
            }
        }
        foreach (array_keys($conflicts) as $id) {
            unset($keys[$id]);
        }
        $ids = [];
        foreach ($revoked as $list) {
            $items = is_string($list) ? explode(',', $list) : (is_array($list) ? $list : []);
            foreach ($items as $id) {
                if (is_string($id) && trim($id) !== '') {
                    $ids[trim($id)] = true;
                }
            }
        }

        return new self($keys, array_keys($ids));
    }

    /** `id=base64,id2=base64` from `.env`; anything malformed is left out. @return array<string,string> */
    public static function parse(mixed $value): array
    {
        if (!is_string($value) || $value === '' || strlen($value) > 65536) {
            return [];
        }
        $keys = [];
        $seen = [];
        foreach (explode(',', $value) as $pair) {
            $parts = explode('=', trim($pair), 2);
            if (count($parts) !== 2) {
                continue;
            }
            [$id, $encoded] = [trim($parts[0]), trim($parts[1])];
            if (isset($seen[$id])) {
                unset($keys[$id]);
                continue;
            }
            $seen[$id] = true;
            if (preg_match(self::KEY_ID, $id) === 1 && self::decode($encoded) !== null) {
                $keys[$id] = $encoded;
            }
        }

        return $keys;
    }

    public function isRevoked(string $keyId): bool
    {
        return in_array($keyId, $this->revoked, true);
    }

    /** The raw public key, or null for an unknown or revoked id. */
    public function key(string $keyId): ?string
    {
        return $this->isRevoked($keyId) ? null : ($this->keys[$keyId] ?? null);
    }

    public function isEmpty(): bool
    {
        return $this->keys === [];
    }

    private static function decode(string $encoded): ?string
    {
        if (preg_match('/\A[A-Za-z0-9+\/]+={0,2}\z/D', $encoded) !== 1) {
            return null;
        }
        $raw = base64_decode($encoded, true);

        return is_string($raw) && strlen($raw) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES ? $raw : null;
    }

    /** @return array<string, mixed> */
    public function __debugInfo(): array
    {
        return ['key_ids' => array_keys($this->keys), 'revoked' => $this->revoked];
    }
}
