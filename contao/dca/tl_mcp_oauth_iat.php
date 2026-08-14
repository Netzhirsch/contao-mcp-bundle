<?php

declare(strict_types=1);

/*
 * Initial Access Tokens (RFC 7591 §3) — single-use credentials the Backend
 * admin generates and hands to a client OPERATOR (e.g. "the developer
 * installing the Inspector"). Without an IAT, /_mcp_oauth/register
 * refuses to register new clients when `oauth_registration_mode = restricted`.
 *
 * `token_hash`: SHA-256 of the plain token; the plain value is only shown
 * once at generation time. `expires_at`: UNIX timestamp (default: now + 1h).
 * `used_at`: timestamp of the registration that redeemed the IAT, 0 if unused.
 */
$GLOBALS['TL_DCA']['tl_mcp_oauth_iat'] = [
    'config' => [
        'sql' => [
            'keys' => [
                'id' => 'primary',
                'token_hash' => 'index',
            ],
        ],
    ],
    'fields' => [
        'id' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'autoincrement' => true]],
        'tstamp' => ['sql' => ['type' => 'integer', 'default' => 0, 'unsigned' => true]],
        'token_hash' => ['sql' => ['type' => 'string', 'length' => 64, 'default' => '']],
        'created_by_user_id' => ['sql' => ['type' => 'integer', 'default' => 0, 'unsigned' => true]],
        'created_at' => ['sql' => ['type' => 'integer', 'default' => 0, 'unsigned' => true]],
        'expires_at' => ['sql' => ['type' => 'integer', 'default' => 0, 'unsigned' => true]],
        'used_at' => ['sql' => ['type' => 'integer', 'default' => 0, 'unsigned' => true]],
        'redeemed_by_client_id' => ['sql' => ['type' => 'string', 'length' => 64, 'default' => '']],
    ],
];
