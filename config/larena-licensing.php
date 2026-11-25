<?php

declare(strict_types=1);

// Larena Licensing on the site (owner decisions 2026-11-25): one signed entitlement snapshot is the site's only
// source of rights. Code asks for a right to a function; what is paid or free is catalog data.
return [
    // The product the snapshot must name (the update client's LARENA_UPDATE_PRODUCT).
    'product' => env('LARENA_UPDATE_PRODUCT', 'larena'),

    /*
     * Public Ed25519 keys that may sign an entitlement snapshot: key id => base64 of the 32-byte public key. The
     * distribution ships them in the site's `config/larena-entitlement-keys.php` (same format) and they are added
     * to these. A site or a test stand adds its own in `.env`: LARENA_LICENSING_TRUSTED_KEYS=id=base64,id2=base64.
     * A list of its own, not the release keys: the snapshot key is online on the registration server, the release
     * key is offline, and an online key must never be able to sign code.
     */
    'trusted_keys' => \Larena\Licensing\Site\TrustedKeys::parse(env('LARENA_LICENSING_TRUSTED_KEYS')),

    // Key ids no longer trusted even when listed (the update client's revoked ids count too).
    'revoked_key_ids' => array_values(array_filter(array_map('trim', explode(',', (string) env('LARENA_LICENSING_REVOKED_KEY_IDS', ''))))),

    // Catalog data: capabilities free for every site, without a snapshot. Making a function free is moving its
    // right here (or into the free part of the catalog), not changing code.
    'free_capabilities' => [],

    // The kept snapshot; empty: storage/app/larena/licensing/snapshot.json (outside the web root, shared by releases).
    'snapshot_path' => env('LARENA_LICENSING_SNAPSHOT_PATH'),

    // The site's own address the test-address rule and the snapshot's `site_hosts` read; empty: app.url (APP_URL).
    'site_url' => env('LARENA_LICENSING_SITE_URL'),

    // The daily refresh (`larena:licensing:refresh` through the site's scheduler), when the site has a site key.
    // Sources, the site key and timeouts are the update client's (`update-client.sources`, `.site_key`, `.download`).
    'refresh' => [
        'daily_at' => env('LARENA_LICENSING_REFRESH_AT', '04:17'),
    ],
];
