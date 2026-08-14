# Lokales HTTPS für den Contao-MCP-Server (Laragon / Windows)

Wie man `https://contao-mcp.test` lokal zum Laufen bringt — und warum man es
für MCP-Tests oft gar nicht braucht.

> _Beispiele gehen vom Netzhirsch-Dev-Setup aus: Projektordner
> `C:\laragon\www\contao-mcp` → Laragon-Host `contao-mcp.test`. Pfade und
> Hostnamen entsprechend deinem Projekt anpassen._

> **Grundprinzip:** Das Bundle terminiert **kein** TLS. HTTPS macht lokal
> **Laragon** (Apache/nginx + eigenes Zertifikat). Das Bundle nutzt das nur
> und baut seine extern beworbenen OAuth-URLs aus dem konfigurierten
> `backend_url` — nicht aus dem Request-Schema. Lokales HTTPS ist deshalb
> reine Umgebungs-Konfiguration.

---

## TL;DR

- **Für die meisten lokalen Tests reicht `http://localhost` / `http://127.0.0.1`** —
  die OAuth-Redirect-Whitelist und die HTTPS-Warnung erlauben Loopback ohne
  TLS. Kein Zertifikat nötig.
- **Echtes lokales HTTPS** willst du nur, wenn du den Produktiv-Aufbau
  (`https://…`) realitätsnah nachstellen musst. Dann: Laragon-SSL aktivieren
  + Laragon-CA als vertrauenswürdig hinterlegen (siehe unten).
- **Die Stolperfalle:** Ein MCP-Client wie Claude Desktop läuft über Node.
  Node vertraut der Laragon-CA standardmäßig **nicht** → TLS-Fehler. Lösung:
  `NODE_EXTRA_CA_CERTS` setzen oder `mkcert` nutzen.

---

## Variante A — Der einfache Weg: HTTP über Loopback (empfohlen für Dev)

Das Bundle ist absichtlich loopback-freundlich:

- `RegisterController` erlaubt `http://localhost`, `http://127.0.0.1`, `http://[::1]`
  als Redirect-URI (RFC 8252 — Native Apps).
- `ModuleMcpServer::isHttpInsecure()` warnt **nur** bei `http://` auf einem
  Nicht-Loopback-Host. localhost bleibt warnungsfrei.

**Setup:**

1. In `var/mcp/config.json`:
   ```jsonc
   {
     "backend_url": "http://localhost",
     "auth_mode": "oauth"
   }
   ```
   (oder `auth_mode: "none"` für reine Funktionstests ganz ohne OAuth)
2. Contao-Console-Aufrufe + Browser über `http://localhost/contao` bzw.
   den Laragon-`.test`-Host per HTTP.

Damit läuft die komplette OAuth-Kette lokal ohne ein einziges Zertifikat.

---

## Variante B — Echtes HTTPS via Laragon (`https://contao-mcp.test`)

### 1. Auto-Host prüfen

Laragon legt für jeden Ordner unter `C:\laragon\www\` automatisch einen
Host `<ordner>.test` an. Für dieses Projekt also `contao-mcp.test`.
Auto-Host-Format kontrollieren: **Laragon → Menü → Preferences → "Auto
create virtual hosts"** aktiv.

### 2. SSL aktivieren

In aktuellen Laragon-Versionen wird SSL beim Anlegen des Hosts mit erzeugt.
Falls `https://` noch nicht greift:

- **Laragon → Menü → Apache → Reload** (bzw. nginx, je nach Stack), oder
- **Laragon → Menü → Apache → SSL → Regenerate certificate** (Menüpfad
  variiert je nach Version).

Laragon legt die Zertifikate unter `C:\laragon\etc\ssl\` ab
(`laragon.crt` = Root-CA, plus per-Host-Zertifikate).

### 3. Laragon-Root-CA vertrauenswürdig machen (einmalig)

Damit Browser & Tools dem lokalen Zertifikat trauen, muss die Laragon-CA in
den Windows-Zertifikatsspeicher:

```powershell
# als Admin
certutil -addstore -f "ROOT" C:\laragon\etc\ssl\laragon.crt
```

Alternativ per GUI: `laragon.crt` doppelklicken → „Zertifikat installieren"
→ **Lokaler Computer** → „Vertrauenswürdige Stammzertifizierungsstellen".

Danach Browser komplett neu starten.

### 4. backend_url umstellen

`var/mcp/config.json`:
```jsonc
{
  "backend_url": "https://contao-mcp.test",
  "auth_mode": "oauth"
}
```

> Wichtig: Das Bundle baut `authorization_endpoint`, `token_endpoint`,
> `registration_endpoint` und die `.well-known`-Metadaten aus genau diesem
> `backend_url`. Steht hier `https://`, wird die ganze OAuth-Discovery als
> https ausgegeben — unabhängig davon, wie Apache das Schema durchreicht.

### 5. Verifizieren

```powershell
# Frontend / Backend
start https://contao-mcp.test/contao

# Healthz (sollte 200 + JSON liefern)
curl.exe -i https://contao-mcp.test/mcp/healthz

# OAuth-Metadaten (alle Endpunkte müssen https sein)
curl.exe -s https://contao-mcp.test/.well-known/oauth-authorization-server
```

Wenn `curl` über `-k` (Zertifikat ignorieren) klappt, aber ohne `-k` meckert,
ist die CA-Trust-Schritt (3) noch nicht durch.

---

## Die MCP-Client-Stolperfalle (Node vertraut der Laragon-CA nicht)

Ein MCP-Client wie **Claude Desktop** verbindet sich über Node (z. B. die
`mcp-remote`-Bridge). Node nutzt **sein eigenes CA-Bundle** und ignoriert den
Windows-Zertifikatsspeicher → bei `https://contao-mcp.test` kommt ein
`SELF_SIGNED_CERT_IN_CHAIN` / `UNABLE_TO_VERIFY_LEAF_SIGNATURE`.

**Lösung 1 — Laragon-CA an Node geben:**
```powershell
# dauerhaft für den User setzen
setx NODE_EXTRA_CA_CERTS "C:\laragon\etc\ssl\laragon.crt"
```
Danach den MCP-Client (und ggf. das Terminal) neu starten.

**Lösung 2 — `mkcert` (robuster, weil Node-/Browser-tauglich):**
```powershell
choco install mkcert        # oder scoop install mkcert
mkcert -install             # installiert eine lokale CA, die auch Node akzeptiert
mkcert contao-mcp.test
```
Das erzeugte Cert/Key in den Laragon-vhost eintragen
(`C:\laragon\etc\ssl\` bzw. die Apache-SSL-vhost-Konfig unter
`C:\laragon\etc\apache2\sites-enabled\`).

**Lösung 3 — einfach Loopback nutzen:** Für lokale MCP-Tests `backend_url` auf
`http://localhost` (Variante A). Spart das ganze CA-Theater.

---

## Troubleshooting

| Symptom | Ursache | Fix |
|---|---|---|
| Browser: „nicht sicher" / Zertifikatswarnung | Laragon-CA nicht vertraut | Schritt 3 (certutil / GUI), Browser neu starten |
| `curl` ohne `-k` schlägt fehl | dito | Schritt 3 |
| MCP-Client: `SELF_SIGNED_CERT_IN_CHAIN` | Node ignoriert Windows-Store | `NODE_EXTRA_CA_CERTS` oder `mkcert` |
| OAuth-Metadaten zeigen `http://` statt `https://` | `backend_url` falsch | `var/mcp/config.json` → `backend_url` auf `https://contao-mcp.test` |
| Rote HTTPS-Warnung im Backend trotz HTTPS | `backend_url` ist `http://` non-loopback | `backend_url` auf `https://…` setzen |
| `https://contao-mcp.test` lädt gar nicht | SSL-vhost fehlt | Laragon → Apache → Reload / Regenerate certificate |
| Generierte Contao-Links sind `http://` hinter Proxy | (nur relevant produktiv) Request-Schema nicht erkannt | `framework.trusted_proxies` + `trusted_headers` — lokal i. d. R. kein Thema |

---

## Merksatz

> **Lokal entwickeln:** `http://localhost` reicht.
> **Produktiv nachstellen:** Laragon-SSL + CA trusten + `backend_url` auf https.
> Das Bundle selbst macht in beiden Fällen nichts mit TLS — es liest nur
> `backend_url`.
