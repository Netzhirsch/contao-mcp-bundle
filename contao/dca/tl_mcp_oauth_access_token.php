<?php

declare(strict_types=1);

/*
 * Access token records — used for revocation. The token itself is a JWT
 * (stateless, signed) and not stored here; what's stored is the JTI (token
 * identifier) so we can blacklist it.
 */
$GLOBALS['TL_DCA']['tl_mcp_oauth_access_token'] = [
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
        'client_id' => ['sql' => ['type' => 'string', 'length' => 64, 'default' => '']],
        'user_id' => ['sql' => ['type' => 'integer', 'default' => 0, 'unsigned' => true]],
        'scopes' => ['sql' => ['type' => 'text', 'notnull' => false]],
        'expires_at' => ['sql' => ['type' => 'integer', 'default' => 0, 'unsigned' => true]],
        'is_revoked' => ['sql' => ['type' => 'boolean', 'default' => false]],
    ],
];
