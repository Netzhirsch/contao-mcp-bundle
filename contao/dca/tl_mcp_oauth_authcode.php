<?php

declare(strict_types=1);

/*
 * Short-lived authorization codes (RFC 6749 §1.3.1). Issued by /oauth/authorize
 * after user consent, exchanged for an access token at /oauth/token, then
 * marked as revoked / deleted. Lifetime: 10 minutes max.
 */
$GLOBALS['TL_DCA']['tl_mcp_oauth_authcode'] = [
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
        'redirect_uri' => ['sql' => ['type' => 'text', 'notnull' => false]],
        'scopes' => ['sql' => ['type' => 'text', 'notnull' => false]],
        'expires_at' => ['sql' => ['type' => 'integer', 'default' => 0, 'unsigned' => true]],
        'is_revoked' => ['sql' => ['type' => 'boolean', 'default' => false]],
        'code_challenge' => ['sql' => ['type' => 'string', 'length' => 128, 'default' => '']],
        'code_challenge_method' => ['sql' => ['type' => 'string', 'length' => 10, 'default' => '']],
    ],
];
