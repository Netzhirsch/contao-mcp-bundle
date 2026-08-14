<?php

declare(strict_types=1);

/*
 * Refresh tokens — used to mint fresh access tokens after the (short)
 * access-token TTL expires. Long-lived (e.g. 30 days). Revoked when a
 * user revokes the OAuth grant or rotates the token.
 */
$GLOBALS['TL_DCA']['tl_mcp_oauth_refresh_token'] = [
    'config' => [
        'sql' => [
            'keys' => [
                'id' => 'primary',
                'identifier' => 'unique',
            ],
        ],
    ],
    'fields' => [
        'id' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'autoincrement' => true]],
        'tstamp' => ['sql' => ['type' => 'integer', 'default' => 0, 'unsigned' => true]],
        'identifier' => ['sql' => ['type' => 'string', 'length' => 100, 'default' => '']],
        'access_token_identifier' => ['sql' => ['type' => 'string', 'length' => 100, 'default' => '']],
        'expires_at' => ['sql' => ['type' => 'integer', 'default' => 0, 'unsigned' => true]],
        'is_revoked' => ['sql' => ['type' => 'boolean', 'default' => false]],
    ],
];
