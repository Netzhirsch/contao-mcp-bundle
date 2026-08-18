# MCP-Verbindung lokal einrichten (Claude Desktop / Cowork)

Wie man eine **lokale** Contao-Instanz (Laragon, z. B. `http://cwa.test`) als MCP-Server
in der Claude-Desktop-/Cowork-App einbindet — inklusive der Stolperfallen, die in der
Praxis Zeit kosten.

> Für **Claude Code (CLI)** statt Desktop siehe ganz unten („Alternative: Claude Code CLI").
> Für lokales **HTTPS** statt `http://…test` siehe [lokales-https.md](lokales-https.md).

## Überblick: Warum `mcp-remote`?

Die Claude-Desktop-/Cowork-App spricht MCP über **stdio** (lokale Prozesse), der
Contao-MCP-Server ist aber ein **HTTP-Endpoint** (`/mcp`, Controller-Mode). Die Brücke
ist das npx-Paket **`mcp-remote`**: es startet lokal, verbindet sich per HTTP/SSE mit
dem Server und übersetzt stdio ↔ HTTP transparent (inkl. OAuth-Handshake).

```
Claude-App  ──stdio──►  npx mcp-remote  ──HTTP+OAuth──►  http://cwa.test/mcp
```

## Voraussetzungen

- **Node.js + npx** installiert (`node -v`, `npx -v`) — `mcp-remote` wird per `npx -y` geladen.
- Die Contao-Instanz läuft lokal und der MCP-Endpoint ist erreichbar:
  `http://<host>.test/mcp` (Bundle installiert, `contao:migrate` gelaufen).
- Ein Backend-User mit **MCP-Zugriff**: Admin, oder das Häkchen
  **„MCP-Server-Zugriff erlauben"** (`netzhirschMcpAccess`) am Account bzw. einer Gruppe.

## Schritt 1 — Endpoint-Daten aus der Instanz holen

Die maßgeblichen Werte stehen in `var/mcp/config.json` der Instanz:

```jsonc
{
  "path": "mcp",                    // → Endpoint = backend_url + "/" + path
  "backend_url": "http://cwa.test", // Basis-URL
  "auth_mode": "oauth",             // "oauth" (Login nötig) oder "none" (offen)
  "lazy_mode": false                // false = Direkt-Tools sichtbar (s. u.)
}
```

→ Der MCP-Endpoint ist hier `http://cwa.test/mcp`.

## Schritt 2 — Connector in `claude_desktop_config.json` eintragen

Datei (Windows): `C:\Users\<user>\AppData\Roaming\Claude\claude_desktop_config.json`

> ⚠️ **Die App MUSS dabei vollständig geschlossen sein** — siehe „Goldene Regeln".

`mcpServers`-Block ergänzen (Beispiel mit drei lokalen/remote Instanzen):

```json
{
  "mcpServers": {
    "cwa": {
      "command": "npx",
      "args": ["-y", "mcp-remote", "http://cwa.test/mcp", "--allow-http"]
    },
    "contao-jobs": {
      "command": "npx",
      "args": ["-y", "mcp-remote", "http://jobs.test/mcp", "--allow-http"]
    },
    "contao-marli": {
      "command": "npx",
      "args": ["-y", "mcp-remote", "https://marli.netzhirsch.de/mcp"]
    }
  }
}
```

- `--allow-http` ist **nur bei `http://`** nötig (lokale `.test`-Domains). Bei `https://` weglassen.
- Der Schlüssel (`"cwa"`) ist der Anzeigename des Connectors und das Präfix der Tools
  (`mcp__cwa__news_list` usw.).

**Alternative ohne Datei-Editieren:** In den App-Einstellungen unter **Connectors/MCP**
einen Server anlegen — Command `npx`, Args `-y mcp-remote http://cwa.test/mcp --allow-http`.
Das schreibt die App selbst und ist die robusteste Variante.

## Schritt 3 — App starten & einloggen

1. Claude-App **starten** (jetzt liest sie die Config beim Boot ein).
2. Bei `auth_mode: oauth` kommt beim ersten Connect der **OAuth-Login**
   (Dynamic Client Registration — kein Client-Secret nötig). Als Backend-User
   mit MCP-Zugriff anmelden und freigeben.
   **Bei Registrierungsmodus „Eingeschränkt":** vorher im Backend unter
   *MCP-Server → Status* den Button
   **„Registrierung für 15 Minuten öffnen"** klicken und die App innerhalb des
   Fensters starten. Das Fenster schließt sich nach der ersten erfolgreichen
   Registrierung von selbst — kein manuelles Umstellen auf „Offen" und
   Zurückstellen mehr nötig.
3. Danach erscheinen die `mcp__<name>__*`-Tools.

## Goldene Regeln (sonst „verschwindet" alles wieder)

1. **Config nur bei vollständig geschlossener App editieren.** Die laufende App ist
   Eigentümer von `claude_desktop_config.json` und **überschreibt** Datei-Änderungen aus
   ihrem internen Stand. Vor dem Editieren also wirklich beenden (Tray/Task-Manager
   prüfen: kein `Claude`- und kein `node … mcp-remote`-Prozess mehr).
2. **Kein `claude mcp add/remove` für diese Connectoren.** Das ist der **Claude-Code-CLI-Store**
   — eine *andere* Ebene als `claude_desktop_config.json`. Mischen führt zu Doppel-/
   Geister-Einträgen und gegenseitigem Überschreiben.
3. **MCP-Server werden nur beim App-Start geladen.** Jede Config-Änderung greift erst
   nach **Neustart** der App.

## Schema-Cache & Reconnect (wichtig nach Bundle-Updates)

Der Client cached die Tool-Schemas (`tools/list`) **beim Verbindungsaufbau**. Wird das
Bundle aktualisiert und ändert sich ein Schema, läuft der Client sonst weiter gegen den
**alten** Cache.

- Symptom: Objekt-Parameter scheitern mit
  `Property '/fields': Invalid type. Expected null|object, but received unknown` —
  obwohl `contao_describe_tool` das Schema **korrekt** zeigt.
- Grund: `describe` ist ein **Live-Call** (immer aktuell), `tools/list` ist
  **clientseitig gecacht**. Der Cache ist veraltet.
- **Fix: App neu starten** → `mcp-remote` reconnectet → frisches `tools/list`. (Reconnect,
  kein Code-Change.)

## `lazy_mode` — Direkt-Tools vs. Discovery

- `lazy_mode: false` → alle ~156 Tools direkt sichtbar (`news_list`, `image_size_create`…).
  Nötig für Automatisierungen/Builds (z. B. Skill-2).
- `lazy_mode: true` → `tools/list` zeigt nur 3 Discovery-Tools (`contao_search_tools`,
  `contao_describe_tool`, `contao_call`); alles andere läuft über `contao_call`.
  Spart Token-Overhead, aber Tools sind nicht direkt adressierbar.

Umschalten in `var/mcp/config.json` (oder Backend-Modul) → danach App neu starten.

## Verifikation

Nach erfolgreichem Connect:

```jsonc
// Server lebt + Versionen:
contao_version()
// Objekt-Parameter-Pfad (read-only):
contao_call(name="pages_list", args={"limit": 1})
```

Kommen Daten zurück (statt `received unknown`), ist die Verbindung inkl. Schema sauber.

## Fehlerbehebung

| Symptom | Ursache | Lösung |
|---|---|---|
| Connector taucht nach Edit nicht auf / verschwindet wieder | Datei bei **laufender** App editiert → überschrieben | App schließen, editieren, dann starten — oder Connectors-UI nutzen |
| `received unknown` bei Objekt-Param, `describe` aber korrekt | veralteter `tools/list`-Cache | App neu starten (Reconnect) |
| OAuth-Login schlägt fehl / hängt | abgelaufene/kaputte mcp-remote-Auth | Ordner `C:\Users\<user>\.mcp-auth` löschen, App neu starten (frischer Handshake) |
| `403 mcp_access_denied` nach Login | User ohne MCP-Recht | `netzhirschMcpAccess` am Account/Gruppe setzen, oder Admin nutzen |
| `--allow-http`-Fehler / Verbindung verweigert | `http://` ohne `--allow-http` | `--allow-http` in die Args aufnehmen (nur bei http) |
| zwei gleiche Connectoren / Konflikt | `claude mcp add` **und** Desktop-Config | CLI-Eintrag entfernen, nur Desktop-Config behalten |

## Alternative: Claude Code CLI (statt Desktop-App)

Im reinen CLI nutzt man den nativen HTTP-Transport (ohne `mcp-remote`):

```bash
claude mcp add --transport http cwa http://cwa.test/mcp
# projektübergreifend:  -s user
```

Das schreibt in den CLI-Store (`~/.claude.json`), **nicht** in `claude_desktop_config.json`.
Die beiden Welten nicht vermischen — pro Client eine Methode.
