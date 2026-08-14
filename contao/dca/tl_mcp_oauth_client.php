<?php

declare(strict_types=1);

/*
 * Dynamic-registered OAuth 2.1 clients (RFC 7591). Inspector / Claude
 * register themselves automatically on first contact; subsequent connects
 * reuse the same client_id.
 *
 * No backend management UI — entries are created via the /oauth/register
 * endpoint and listed (read-only) in the MCP-Server backend module.
 */
$GLOBALS['TL_DCA']['tl_mcp_oauth_client'] = [
    'config' => [
        'sql' => [
            'keys' => [
                'id' => 'primary',
                'client_id' => 'unique',
            ],
        ],
    ],
    'fields' => [
        'id' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'autoincrement' => true]],
        'tstamp' => ['sql' => ['type' => 'integer', 'default' => 0, 'unsigned' => true]],
        'client_id' => ['sql' => ['type' => 'string', 'length' => 64, 'default' => '']],
        'client_secret_hash' => ['sql' => ['type' => 'string', 'length' => 255, 'default' => '']],
        'name' => ['sql' => ['type' => 'string', 'length' => 255, 'default' => '']],
        'redirect_uris' => ['sql' => ['type' => 'text', 'notnull' => false]],
        'is_confidential' => ['sql' => ['type' => 'boolean', 'default' => false]],
        'created_at' => ['sql' => ['type' => 'integer', 'default' => 0, 'unsigned' => true]],
        // Who granted the (latest) consent at /authorize. Denormalised
        // username so the admin table stays readable after a tl_user delete.
        // 0/'' until the first consent — the admin list then falls back to
        // the newest access token's user for legacy rows.
        'authorized_user_id' => ['sql' => ['type' => 'integer', 'default' => 0, 'unsigned' => true]],
        'authorized_username' => ['sql' => ['type' => 'string', 'length' => 255, 'default' => '']],
        'authorized_at' => ['sql' => ['type' => 'integer', 'default' => 0, 'unsigned' => true]],
    ],
];
