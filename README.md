# Contao MCP Bundle

[![CI](https://github.com/Netzhirsch/contao-mcp-bundle/actions/workflows/ci.yml/badge.svg)](https://github.com/Netzhirsch/contao-mcp-bundle/actions/workflows/ci.yml)

**Status:** Stable — `v1.0.4`
**Lizenz:** proprietär, kommerziell lizenziert — 30 Tage kostenlos testen,
danach 49 €/Monat je Contao-Instanz (siehe [Lizenz & Testphase](#lizenz--testphase)
und [LICENSE](LICENSE))

Ein [Model Context Protocol](https://modelcontextprotocol.io/)-Server als
Contao-5-Bundle. Verbindet Claude Desktop, Claude in der API, Claude Code,
MCP Inspector oder jede andere MCP-fähige KI direkt mit dem Contao-Backend —
ohne eigene REST-Endpunkte, ohne Middleware, ohne Port.

Statt jeder KI-Aufgabe einen eigenen API-Endpunkt nachzuziehen, bekommt die
KI-Session strukturierten Zugriff auf den gesamten DCA-Stack: Redakteure können
per natürlichsprachlichem Auftrag Inhalte anlegen, Pipelines können Seiten
vollautomatisch aus Drittsystemen befüllen, Entwickler können Strukturmigrationen
skripten — alles über dieselben ~170 Tools, abgesichert mit denselben
Backend-Benutzerrechten wie beim manuellen Bearbeiten.

**Unterstützte Entitäten:** News, Seiten, Artikel, Kalender, FAQ, Mitglieder,
Formulare, Newsletter, Kommentare, Themes, Layouts, Module, Bildgrößen,
Templates, Dateien, URL-Rewrites, Formular-Leads, Wartung +
System-Einstellungen.

## Was drin ist

- **~170 Tools** über Contao-Kernentitäten + populäre Extensions.
- **Lazy-Mode-Discovery**: drei Meta-Tools (`contao_search_tools`,
  `contao_describe_tool`, `contao_call`) verstecken die übrigen vor
  `tools/list` — spart bei Claude Desktop ~12 KB System-Prompt-Overhead pro
  Turn.
- **OAuth 2.1** mit PKCE + Dynamic Client Registration (RFC 7591) inkl.
  Initial-Access-Token-Gate für `restricted` mode.
- **Filesystem-Suche**: `files_search` (rekursive Glob-Suche im Upload-Tree,
  POSIX-Syntax + `**`-Erweiterung, basename-Match bei Patterns ohne Slash)
- **Site-Building-Helfer**: `entity_move`, `page_cache_invalidate`,
  `system_settings_update`, `insert_tags_list`, `page_preview`,
  `maintenance_run`, `dbafs_sync` (Reconcile `tl_files` ↔ Disk).
- **Optionale Extension-Tools**: erscheinen automatisch, sobald das jeweilige
  Bundle installiert ist (sonst sauberer `extension_not_available`-Fehler):
  Newsletter, Kommentare, `url_rewrite_*` (terminal42) und **lesend**
  `leads_list` + `lead_get` für Formular-Einsendungen (`terminal42/contao-leads`).
- **Author-Pass-Through**: Writes laufen unter dem echten OAuth-User in
  `tl_log` + `tl_version`.
- **Backend-Modul** „MCP-Server" mit vier Bereichen: Status (Lizenz + Testphase/
  Abo starten, OAuth-Clients, IATs), Konfiguration, Aktivitätslog, Tool-Panel
  (jedes Tool einzeln abschaltbar) — **nur für Contao-Administratoren**.
- **Linux + Windows getestet** (Laragon dev, Debian production).

## Installation

### 1. composer.json des Contao-Projekts

Das Bundle liegt auf [Packagist](https://packagist.org/packages/netzhirsch/contao-mcp-bundle)
— kein `repositories`-Eintrag nötig. Erforderlich ist nur der Patch-Block, weil
zwei Patches gegen `php-mcp/server` angewendet werden müssen:

```json
{
    "require": {
        "netzhirsch/contao-mcp-bundle": "^1.0",
        "cweagans/composer-patches": "^1.7"
    },
    "config": {
        "allow-plugins": {
            "cweagans/composer-patches": true
        }
    },
    "extra": {
        "patches": {
            "php-mcp/server": {
                "Pluggable Bearer-auth + OAuth Authorization Server Metadata hooks": "vendor/netzhirsch/contao-mcp-bundle/patches/transport-auth-and-oauth-metadata.patch",
                "Optional tool-filter for tools/list (Lazy-Mode)": "vendor/netzhirsch/contao-mcp-bundle/patches/dispatcher-tool-filter.patch"
            }
        }
    }
}
```

> Die `extra.patches`-Block muss in der **root** `composer.json` stehen —
> Composer ignoriert Patch-Declarations aus Dependencies (by design).

### 2. Composer

```bash
composer require netzhirsch/contao-mcp-bundle
```

Alternativ im **Contao Manager** nach „Contao MCP Bundle" suchen und
installieren — kein Token, kein Repository-Eintrag nötig.

### 3. Bundle registrieren

Auto-Discovery über das Contao Manager Plugin — kein manuelles Eintragen in
`config/bundles.php` nötig.

### 4. Schema-Migrationen + erste Konfig

```bash
vendor/bin/contao-console contao:migrate --env=prod
```

Legt die OAuth-Tabellen an (`tl_mcp_oauth_*`) und ergänzt die
External-ID-Spalten auf 24 Entity-Tabellen. Standardkonfig läuft
unauthentifiziert — für Production unbedingt `auth_mode=oauth` einschalten
(siehe Backend-Modul oder `var/mcp/config.json`).

Der MCP-Endpoint ist nach der Migration sofort live unter
`https://<backend_url>/mcp` — Apache/PHP-FPM serviert ihn wie jede andere
Symfony-Route. Kein Daemon, kein Port, kein Reverse-Proxy nötig.

### 5. Lizenz aktivieren (30 Tage kostenlos)

Ohne aktive Lizenz antworten alle Tools mit `license_inactive` — Contao selbst
läuft normal weiter. Im Backend unter **MCP-Server → Status** oben auf
**„Testphase starten"** klicken: 30 Tage, ohne Zahlungsdaten. Details siehe
[Lizenz & Testphase](#lizenz--testphase).

### 6. In Claude Desktop / Cowork einbinden

**Anleitungen im Repo:** [docs/installation.md](docs/installation.md)
(Client anbinden, online + lokal) und [docs/dokumentation.md](docs/dokumentation.md)
(vollständige Funktionsreferenz). Im Backend selbst gibt es keinen Doku-Tab mehr.

> **Stolperfalle bei `oauth_registration_mode: restricted` (Default):** Claude,
> `mcp-remote` & Co. können bei der Registrierung **keinen** Initial Access Token
> mitschicken — der IAT-Button hilft dort also nicht. Stattdessen im Backend
> **MCP-Server → Status → „Registrierung für 10 Minuten öffnen"** klicken und den
> Client **sofort** verbinden. Das Fenster schließt sich nach der **ersten**
> erfolgreichen Registrierung; für jeden weiteren Versuch neu öffnen.

Schritt-für-Schritt-Anleitung für die lokale Connector-Einrichtung
(`mcp-remote`-Bridge, `claude_desktop_config.json`, OAuth, Schema-Cache +
Stolperfallen): **[docs/mcp-client-lokal-einrichten.md](docs/mcp-client-lokal-einrichten.md)**.
Kurzfassung der genauen Config-Werte auch im Backend-Doku-Tab des MCP-Server-Moduls.

## Lizenz & Testphase

Das Bundle ist kommerziell lizenziert. Der Tool-Layer ist lizenzgeschützt:
ohne gültige Lizenz liefert jeder `tools/call` einen `license_inactive`-Fehler
(Ausnahme: `ping`). **Contao selbst ist nie betroffen** — Frontend, Backend und
alle anderen Erweiterungen laufen unverändert weiter.

| | |
|---|---|
| **Testphase** | 30 Tage, **ohne Zahlungsdaten**, eine je Domain/Konto |
| **Preis** | **49 €/Monat** oder **539 €/Jahr** (12 für 11), netto zzgl. MwSt. |
| **Einheit** | pro **Contao-Instanz** — unabhängig davon, wie viele Front-End-Domains sie bedient |
| **Zahlung** | Karte oder SEPA-Lastschrift, ausschließlich auf **Stripe-gehosteten** Seiten |
| **Staging/Dev** | kostenlos (lokale Hosts sowie Subdomains einer bezahlten Domain) |

**Bestellen im Backend** — alles unter **MCP-Server → Status**, Buttonleiste oben:

1. **„Testphase starten"** → schaltet die Tools für 30 Tage frei.
2. **„Abonnieren"** → öffnet die Stripe-Bezahlseite. Karten-/SEPA-Daten werden
   **nur bei Stripe** eingegeben, nie in Contao gespeichert.
3. **„Abo verwalten"** → Stripe-Kundenportal (Zahlungsmittel, Rechnungen, Kündigung).

**Alternativ per CLI:**

```bash
vendor/bin/contao-console contao:mcp:license status          # aktueller Zustand
vendor/bin/contao-console contao:mcp:license trial <email>   # Testphase starten
vendor/bin/contao-console contao:mcp:license activate <token> # Token einspielen
```

**Verlängerung läuft automatisch.** Der Cron `LicenseRenewalCron` (stündlich,
gedrosselt) erneuert das Token; die Prüfung selbst ist **offline** (Ed25519).
Ein Ausfall des Lizenzservers sperrt daher niemanden aus — zusätzlich gelten
3 Tage Kulanz nach Ablauf. Voraussetzung ist ein laufender Contao-Cron.

> Verbindung zum Lizenzserver: `https://license.netzhirsch.de`, fest im Bundle
> hinterlegt — **nichts zu konfigurieren**. Übertragen werden nur Domain,
> Produkt und die E-Mail des bestellenden Backend-Users.

## Anforderungen

- **PHP** `^8.1` mit Extensions: `openssl`, **`sodium`**, `pdo_mysql`, `mbstring`,
  `intl` (`sodium` ist für die Lizenzprüfung zwingend — fehlt es, bleiben alle
  Tools gesperrt; der Code ist 8.1-sauber, die 8.1-Untergrenze deckt
  Contao-5.3-Installationen ab)
- **Contao** ≥ 5.3 (Smoke-Test läuft gegen 5.3 **und** 5.7)
- **Symfony** ≥ 6.4 oder 7.x
- **MySQL** ≥ 8.0 oder MariaDB ≥ 10.6 (strict mode unterstützt)

## Smoke-Test

```bash
vendor/bin/contao-console contao:mcp:smoke-test --env=dev
```

Geht ~200 Asserts gegen den Tool-Layer durch (CRUD auf Member/Group/Form/
Newsletter/Comments/Theme/Layout/Templates/Maintenance + External-ID +
Audit-Regressions + Key-Rotation + Rate-Limit + MCP-Activity-Log),
erstellt eigene Testdaten, räumt am Ende wieder auf. Soll grün
durchlaufen.

Zusätzlich gibt es eine isolierte PHPUnit-Suite (`vendor/bin/phpunit`)
für OAuth-Crypto-Edge-Cases (dual-key Rotation, IAT single-use,
HMAC-Pepper) die der Smoke-Test als End-to-End nicht erreicht.

## Lokale Entwicklung & HTTPS

Das Bundle terminiert **kein** TLS — HTTPS liefert der Webserver davor
(lokal Laragon, produktiv z.B. Plesk/Let's-Encrypt). Die extern beworbenen
OAuth-Endpunkte baut das Bundle aus dem konfigurierten `backend_url`, nicht
aus dem Request-Schema — dadurch ist es reverse-proxy-robust.

Für lokale MCP-Tests reicht meist `backend_url: "http://localhost"`
(Loopback ist von der Redirect-URI-Whitelist und der HTTPS-Warnung
ausgenommen) — kein Zertifikat nötig. Echtes lokales HTTPS
(`https://<host>.test`) inkl. der Node-/CA-Stolperfalle bei MCP-Clients:
siehe **[docs/lokales-https.md](docs/lokales-https.md)**.

## Health-Check vor Production-Deploy

```jsonc
// MCP-Call
{"tool": "system_health_check"}
```

Returnt eine strukturierte Liste über PHP-Setup, `var/mcp/`-Permissions,
OAuth-Konfig + `warnings: [...]` mit konkreten Fix-Befehlen. Vor jedem
Site-Move oder Server-Wechsel laufen lassen.

## Konfiguration

Datei: `var/mcp/config.json` (wird beim ersten Backend-Aufruf des Moduls
angelegt).

> Die vier **MCP-Server-Backendmodule sind Administratoren vorbehalten** — sie
> schalten `auth_mode` (und damit die komplette Rechteprüfung), vergeben
> OAuth-Registrierungen, widerrufen Clients und schließen kostenpflichtige Abos
> ab. Ein Nicht-Admin bekommt auch mit gesetztem Modulrecht „Zugriff verweigert".

Felder:

| Key | Default | Bedeutung |
|---|---|---|
| `path` | `mcp` | URL-Pfad (ohne führenden Slash) |
| `pagination_limit` | `500` | Max Tools pro `tools/list` (irrelevant in Lazy-Mode) |
| `auth_mode` | `none` | `none` oder `oauth` |
| `backend_url` | `""` | Public Base-URL des Contao-Backends (Pflicht bei OAuth) |
| `oauth_registration_mode` | `restricted` | `restricted` (IAT-Pflicht) oder `open` |
| `lazy_mode` | `false` | Wenn `true`: nur 6 Discovery-Tools in `tools/list` |

Bundle-eigene Konfig in `config/packages/netzhirsch_contao_mcp.yaml`:

```yaml
netzhirsch_contao_mcp:
    write:
        default_author_id: 1   # Fallback wenn auth_mode=none
```

## Bekannte Einschränkungen

Stand `v1.0.4`:

- **Vendor-Patches** an `php-mcp/server` (zwei Patches via
  `cweagans/composer-patches`) sind notwendig, bis upstream PR #59
  (PSR-7-Middleware-Support) released ist. Der zweite Patch
  (`dispatcher-tool-filter`) bleibt voraussichtlich permanent als
  eigene Erweiterung — kein Upstream-Pendant geplant.
- **PHPUnit-Coverage** fokussiert OAuth-Crypto (KeyManager, IAT,
  Rotation). Tool-Layer wird ausschließlich vom Smoke-Test exerziert.
- **Encryption-Key-Rotation** ist NICHT implementiert. Der
  `var/mcp/oauth/encryption.key` schützt Refresh-Token-Payloads at
  rest — Rotation würde alle Refresh-Tokens invalidieren. (Die
  RSA-**Signing**-Keys lassen sich dagegen rotieren, siehe
  `contao:mcp:oauth:rotate-keys` unter „Wartung".)
- **Lizenz-Domainbindung** wertet die konfigurierte `backend_url` aus.
  Das ist eine kaufmännische, keine kryptografische Grenze — sie hält
  ehrliche Installationen sauber getrennt, ist aber vom Betreiber der
  Instanz beeinflussbar.

Voller Audit-Stand: [CHANGELOG.md](CHANGELOG.md).

## Backup-Strategie

Das Bundle persistiert vier separate Daten-Surfaces. Ein vollständiger
Restore braucht alle vier — sonst bleiben entweder OAuth-Tokens
ungültig (Keys weg) oder Tool-Calls können keine externen Referenzen
zuordnen (External-IDs weg).

| Surface | Pfad | Restore-Verhalten |
|---|---|---|
| OAuth-RSA-Keys + Encryption-Key | `var/mcp/oauth/*.pem`, `var/mcp/oauth/encryption.key` | Pflicht. Fehlt → alle Refresh-Tokens ungültig, alle Access-Tokens müssen neu ausgestellt werden. Mode 0600 zwingend. |
| Bundle-Config | `var/mcp/config.json` | Optional. Fehlt → Defaults greifen, Operator muss `auth_mode=oauth` manuell aktivieren. |
| OAuth-Tabellen | `tl_mcp_oauth_client`, `tl_mcp_oauth_access_token`, `tl_mcp_oauth_refresh_token`, `tl_mcp_oauth_auth_code`, `tl_mcp_oauth_iat` | Pflicht für nahtlose Migration. Fehlt → Clients müssen sich neu registrieren (DCR). |
| External-ID-Spalten | `external_id_namespace` + `external_id_key` auf 24 Entity-Tabellen | Pflicht für Skill-2-Integrationen. Fehlt → Updates müssen via Contao-PK statt externer Referenz erfolgen, schmerzhafte Doppel-Pflege. |

Empfehlung: `tar` über `var/mcp/` + mysqldump auf die fünf
`tl_mcp_oauth_*`-Tabellen + ein DB-Dump des kompletten Contao-Schemas
(External-ID-Spalten leben auf Entity-Tabellen, kein eigener Backup-
Container möglich).

## Bug-Reports

Issues / Findings bitte ins Repo, plus Anhang:

- Output von `system_health_check`
- Backend-User-Rolle + Contao-Version
- Relevante Einträge aus `var/log/prod.log` (Symfony-Standard-Log)

## Wartung

Composer-Updates des Bundles:

```bash
composer update netzhirsch/contao-mcp-bundle
```

Vendor-Patches (php-mcp/server) werden automatisch beim
`composer install`/`update` reapplied. Bei einem `php-mcp/server`-Major-Bump
ggf. Patches anpassen — siehe `patches/README.md`.

### Console-Kommandos

| Kommando | Zweck | Empfohlener Rhythmus |
|---|---|---|
| `contao:mcp:license status\|trial\|activate\|renew` | Lizenz/Testphase verwalten | bei Bedarf (Verlängerung läuft per Cron automatisch) |
| `contao:mcp:oauth:cleanup` | abgelaufene Auth-Codes, Tokens, IATs purgen | täglich als Cron |
| `contao:mcp:oauth:rotate-keys` | OAuth-RSA-Signing-Keys rotieren (Dual-Key, ohne Ausloggen) | monatlich |
| `contao:mcp:permission-debug` | nachvollziehen, warum ein Backend-User ein Tool (nicht) darf | zur Fehlersuche |
| `contao:mcp:smoke-test` | End-to-End-Selbsttest des Tool-Layers | nach Updates/Serverumzug |

Der Contao-Cron muss laufen (`contao:cron` bzw. der Web-Cron) — daran hängt
auch die automatische Lizenzverlängerung.

---
*Maintainer: Jan-Philipp Kalus &lt;kalus@netzhirsch.de&gt; — Netzhirsch*
