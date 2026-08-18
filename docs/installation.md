# Installation: MCP-Client anbinden

Diese Anleitung gehört zur Referenz des Bundles `netzhirsch/contao-mcp-bundle`; die vollständige Funktionsreferenz steht in der [Dokumentation](./dokumentation.md). Der konkrete MCP-Endpoint deiner Instanz wird im Contao-Backend unter **MCP-Server → Status** angezeigt.

Der MCP-Endpoint der Instanz ist `https://<backend-url>/mcp`. Diese Anleitung beschreibt Schritt für Schritt, wie ein MCP-Client (Claude Web, Claude Desktop, MCP Inspector, …) angebunden wird — getrennt nach **Online-Instanz** (HTTPS, Produktiv-/Staging-Server) und **lokaler Instanz** (Laragon, `http://*.test`), weil sich die Wege unterscheiden.

## 1. Voraussetzungen (beide Varianten)

- **Backend-User mit MCP-Zugriff:** Admin, oder das Häkchen _„MCP-Server-Zugriff erlauben"_ am Benutzer bzw. einer seiner Gruppen. Tool-Schreibzugriffe werden auf diesen User attribuiert (`tl_log`, `tl_version`), und seine Backend-Rechte gelten 1:1 auch über MCP.
- **Browser auf dem Client-Rechner** für den einmaligen OAuth-Login (Backend-Login + Consent).
- **Node.js** nur für die lokale Variante (`mcp-remote`-Bridge) und den MCP Inspector.

## 2. Variante A — Online-Instanz (HTTPS)

Claude Web und aktuelle Claude-Desktop-Versionen sprechen den Server direkt an — ohne Node.js, ohne Config-Datei.

1. **Server prüfen:** Menüpunkt **MCP-Server → Konfiguration** — `auth_mode` = _OAuth 2.1_ und `backend_url` = die öffentliche HTTPS-Adresse. Der Menüpunkt **MCP-Server → Status** muss den Endpoint grün zeigen.
2. **Pairing-Fenster öffnen** _(nur im Modus „Eingeschränkt")_: Menüpunkt **MCP-Server → Status** → Kopfleisten-Button _„Registrierung für 15 Minuten öffnen"_. Das Fenster gilt 15 Minuten und verriegelt sich danach selbst — beliebig viele Versuche in dieser Zeit.
3. **Connector anlegen:**
   - _Claude Web_ (claude.ai): Settings → Connectors → _„Add custom connector"_ → URL `https://<backend-url>/mcp` → Connect.
   - _Claude Desktop_ (aktuell): Settings → Connectors → _„Add custom connector"_ → gleiche URL.
4. **OAuth-Login:** Der Browser öffnet `…/_mcp_oauth/authorize` — als Backend-User mit MCP-Zugriff anmelden und dem Consent zustimmen.
5. **Prüfen:** Menüpunkt **MCP-Server → Status** → Tabelle „Registrierte Clients" zeigt den neuen Client mit deinem Benutzernamen unter „Autorisiert von". Im Client erscheinen die Contao-Tools.

## 3. Variante B — Lokale Instanz (Laragon, http://*.test)

Lokale `.test`-Adressen sind aus dem Internet nicht erreichbar und laufen ohne HTTPS — hier braucht es die `mcp-remote`-Bridge (Node.js) zwischen Claude Desktop und dem Server.

1. **Claude Desktop komplett beenden** (Tray-Icon → Quit; im Task-Manager darf kein `Claude`- und kein `node … mcp-remote`-Prozess mehr laufen). Die laufende App überschreibt sonst jede Config-Änderung.
2. **Config-Datei editieren** — Windows: `%APPDATA%\Claude\claude_desktop_config.json`, macOS: `~/Library/Application Support/Claude/claude_desktop_config.json`:

   ```json
   {
     "mcpServers": {
       "contao-lokal": {
         "command": "npx",
         "args": ["-y", "mcp-remote", "http://<host>.test/mcp", "--allow-http"]
       }
     }
   }
   ```

   `--allow-http` ist nur bei `http://` nötig; der Schlüssel (`contao-lokal`) wird Anzeigename und Tool-Präfix. Mehrere Instanzen = mehrere Einträge nebeneinander.
3. **Pairing-Fenster öffnen** _(nur im Modus „Eingeschränkt")_: Menüpunkt **MCP-Server → Status** → _„Registrierung für 15 Minuten öffnen"_.
4. **App starten:** Claude Desktop liest die Config beim Boot. Beim ersten Connect öffnet sich der Browser für Backend-Login + Consent.
5. **Prüfen:** Die `mcp__contao-lokal__*`-Tools erscheinen; der Menüpunkt **MCP-Server → Status** zeigt den Client mit „Autorisiert von".

Stolperfallen (Schema-Cache nach Bundle-Updates, CLI-Store vs. Desktop-Config, kaputte mcp-remote-Auth) und echtes lokales HTTPS: siehe [mcp-client-lokal-einrichten.md](./mcp-client-lokal-einrichten.md) und [lokales-https.md](./lokales-https.md) im Bundle.

## 4. Erster Connect: der OAuth-Flow im Detail

Beide Varianten durchlaufen automatisch dieselbe Kette:

1. Client POSTet `/mcp` → `401` mit `WWW-Authenticate: Bearer`.
2. Client liest `/.well-known/oauth-authorization-server` und findet die OAuth-Endpunkte.
3. Falls noch nicht registriert: `POST /_mcp_oauth/register` (Dynamic Client Registration, RFC 7591) — im „Eingeschränkt"-Modus nur mit aktivem Pairing-Fenster oder IAT, siehe Abschnitt 5.
4. Browser öffnet `/_mcp_oauth/authorize`: Backend-Login, Consent, Redirect.
5. Auth-Code wird gegen Access-Token (1 h) + Refresh-Token (30 Tage, Rotation) getauscht; der Client speichert beides und refresht im Hintergrund. Die Registrierung passiert genau einmal pro Client-Installation.

## 5. Registrierung im „Eingeschränkt"-Modus: Pairing-Fenster & IAT

Standardmäßig verlangt die Client-Registrierung eine Freigabe. Zwei Wege:

- **Pairing-Fenster (der Normalweg für Menschen):** Menüpunkt **MCP-Server → Status** → _„Registrierung für 15 Minuten öffnen"_, Client innerhalb des Fensters verbinden. Schließt nach 15 Minuten automatisch — kein Umstellen, kein Zurückstellen-Vergessen. Abgewiesene Versuche stehen mit Grund unter **MCP-Server → Aktivität**. Auch ein im Fenster registrierter Client erhält erst nach Backend-Login + Consent ein Token.
- **Initial Access Token:** wird nicht mehr ausgegeben. Ein IAT konnte ohnehin nur die Registrierung automatisieren, nie die Autorisierung — auch danach musste ein Mensch sich im Backend anmelden und zustimmen. Das Pairing-Fenster ist der einzige Weg.

Registrierte Clients bleiben dauerhaft verbunden (Refresh-Token), bis sie im Menüpunkt **MCP-Server → Status** widerrufen werden.

## 6. MCP Inspector (Debugging)

```bash
npx --yes @modelcontextprotocol/inspector
```

Transport _„Streamable HTTP"_, URL `https://<backend-url>/mcp` — OAuth-Roundtrip wie oben (bei „Eingeschränkt" vorher Pairing-Fenster öffnen).

## 7. Andere MCP-Clients

ChatGPT-Connector, Cursor und eigene Integrationen tragen denselben Endpoint ein und folgen ihrem eigenen OAuth-Flow. Solange der Client OAuth 2.1 mit PKCE spricht (RFC 7591 + RFC 8414), funktioniert es ohne Bundle-Änderung.
