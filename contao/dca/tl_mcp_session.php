<?php

declare(strict_types=1);

/*
 * MCP sessions of the handshake era (protocol revisions up to 2025-11-25).
 *
 * A client that opened with `initialize` sends the `Mcp-Session-Id` it was
 * given on every later request, and that request can land on any PHP worker —
 * or, behind a load balancer, on another machine. The session therefore lives
 * in the one place every node shares: the Contao database.
 *
 * `session_id`: the RFC 4122 form of the UUID the server issued. `data`: the
 * SDK's encoded session (negotiated revision, client info and capabilities).
 * `expires_at`: UNIX timestamp; every request that writes the session moves it
 * forward, so only a session nobody has used for the whole lifetime expires.
 * Revision 2026-07-28 has no sessions and never touches this table.
 */
$GLOBALS['TL_DCA']['tl_mcp_session'] = [
    'config' => [
        'sql' => [
            'keys' => [
                'id' => 'primary',
                'session_id' => 'unique',
                'expires_at' => 'index',
            ],
        ],
    ],
    'fields' => [
        'id' => ['sql' => ['type' => 'integer', 'unsigned' => true, 'autoincrement' => true]],
        'tstamp' => ['sql' => ['type' => 'integer', 'default' => 0, 'unsigned' => true]],
        'session_id' => ['sql' => ['type' => 'string', 'length' => 36, 'default' => '']],
        'data' => ['sql' => ['type' => 'text', 'length' => 16777215, 'notnull' => false]],
        'expires_at' => ['sql' => ['type' => 'integer', 'default' => 0, 'unsigned' => true]],
    ],
];
