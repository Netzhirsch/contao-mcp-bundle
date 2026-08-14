<?php

declare(strict_types=1);

use Netzhirsch\ContaoMcpBundle\Backend\Module\ModuleMcpActivity;
use Netzhirsch\ContaoMcpBundle\Backend\Module\ModuleMcpConfig;
use Netzhirsch\ContaoMcpBundle\Backend\Module\ModuleMcpStatus;
use Netzhirsch\ContaoMcpBundle\Backend\Module\ModuleMcpTools;

/*
 * Backend-Menügruppe "MCP-Server" — ein Menüpunkt je Anliegen. Doku +
 * Installationsanleitung liegen als Markdown im Repo (docs/installation.md,
 * docs/dokumentation.md), nicht mehr im Backend:
 *
 *   - Status        — Endpoint, Auth-Zustand + komplette OAuth-Verwaltung
 *                     (Pairing-Fenster, IATs, registrierte Clients)
 *   - Konfiguration — config.json-Formular
 *   - Aktivität     — letzte 100 MCP-tl_log-Einträge
 *   - Tools         — Tools einzeln aktivieren/deaktivieren
 *
 * Gruppen- und Modul-Keys sind vendor-genamespaced (netzhirsch_…) — Pflicht-
 * Konvention, schützt vor Key-/Sprachschlüssel-Kollisionen mit Core/Bundles.
 * Jeder Punkt ist einzeln über die normalen tl_user/tl_user_group-Modul-
 * rechte vergebbar. Gruppen-Label: TL_LANG.MOD.netzhirsch_mcp (String),
 * Modul-Labels: TL_LANG.MOD.<key> (Arrays) — siehe contao/languages.
 *
 * Das Gruppen-Icon hängt am CSS-Hook (BackendCssListener →
 * public/backend.css, Klasse .group-netzhirsch_mcp). Level-2-Einträge
 * bekommen im Core kein Icon — daher hier keine icon-Keys.
 *
 * Der MCP-Endpoint selbst läuft als Symfony-Route (McpController) —
 * Config-Änderungen greifen beim nächsten /mcp-Request ohne Zutun.
 */
$GLOBALS['BE_MOD']['netzhirsch_mcp'] = [
    'netzhirsch_mcp_status' => [
        'callback' => ModuleMcpStatus::class,
    ],
    'netzhirsch_mcp_config' => [
        'callback' => ModuleMcpConfig::class,
    ],
    'netzhirsch_mcp_activity' => [
        'callback' => ModuleMcpActivity::class,
    ],
    'netzhirsch_mcp_tools' => [
        'callback' => ModuleMcpTools::class,
    ],
];
