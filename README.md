# Contao MCP Bundle

[![CI](https://github.com/Netzhirsch/contao-mcp-bundle/actions/workflows/ci.yml/badge.svg)](https://github.com/Netzhirsch/contao-mcp-bundle/actions/workflows/ci.yml)

*🇬🇧 [English version](README.en.md) — diese deutsche Fassung ist die Referenz.*

**Status:** Stable — `v1.8.2`
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
skripten — alles über dieselben 186 Tools, abgesichert mit denselben
Backend-Benutzerrechten wie beim manuellen Bearbeiten.

**Unterstützte Entitäten:** News, Seiten, Artikel, Kalender, FAQ, Mitglieder,
Formulare, Newsletter, Kommentare, Themes, Layouts, Module, Bildgrößen,
Templates, Dateien, URL-Rewrites, Formular-Leads, Wartung +
System-Einstellungen.

## Was drin ist

- **186 Tools** über Contao-Kernentitäten + populäre Extensions.
- **Lazy-Mode-Discovery**: drei Meta-Tools (`contao_search_tools`,
  `contao_describe_tool`, `contao_call`) verstecken die übrigen vor
  `tools/list` — spart bei Claude Desktop ~12 KB System-Prompt-Overhead pro
  Turn.
- **OAuth 2.1** mit PKCE, Client-ID-Metadatendokumenten (CIMD),
  Dynamic Client Registration (RFC 7591) und Protected-Resource-Metadata
  (RFC 9728). Mit CIMD verbindet sich Claude **ohne jede Vorbereitung im
  Backend** — kein Pairing-Fenster, keine geöffnete Registrierung. Wer
  registrieren will, kann es weiterhin: im Default-Modus `restricted`
  ausschließlich im 15-Minuten-Pairing-Fenster.
- **Volltextsuche über die Website**: `search_query` durchsucht Contaos
  Suchindex (`tl_search`) — findet also auch Text, der aus Modulen, Includes
  oder Erweiterungen stammt und über die CRUD-Tools nicht auffindbar wäre.
  Geschützte Seiten bleiben außen vor; `search_index_status` zeigt, ob der
  Index überhaupt befüllt ist.
- **Filesystem-Suche**: `files_search` (rekursive Glob-Suche im Upload-Tree,
  POSIX-Syntax + `**`-Erweiterung, basename-Match bei Patterns ohne Slash)
- **Site-Building-Helfer**: `entity_move`, `page_cache_invalidate`,
  `system_settings_update`, `insert_tags_list`, `page_preview`,
  `maintenance_run`, `dbafs_sync` (Reconcile `tl_files` ↔ Disk).
- **Bauen in einem Aufruf statt in einer Schrittliste**: `pages_create_tree` und
  `pages_delete_tree` für den Seitenbaum, `content_create_tree` für eine ganze
  Inhaltsstrecke inklusive verschachtelter Container. Alles Prüfbare wird vor
  dem ersten Schreibvorgang geprüft; `dry_run` zeigt den Plan.
- **`entity_field_patch`**: eine Passage in einer Textspalte ersetzen, statt das
  ganze Feld neu zu schreiben. `old` muss genau so oft vorkommen wie erwartet,
  sonst bricht der Aufruf ab, ohne den Datensatz anzufassen — und der
  Schreibvorgang läuft trotzdem über das `*_update`-Tool der Tabelle, mit
  Versions-Snapshot.
- **`html_filter_info` + `html_filter_preview`**: was der Ausgabefilter von
  eigenem Markup übrig lässt — **bevor** es geschrieben wird. Gespeichert ist
  nicht gerendert: Der Read-back liefert das Markup unverändert zurück, während
  `<input type>` und `<label for>` im Frontend längst entfernt sind.
- **Optionale Extension-Tools**: erscheinen automatisch, sobald das jeweilige
  Bundle installiert ist (sonst sauberer `extension_not_available`-Fehler):
  Newsletter, Kommentare, `url_rewrite_*` (terminal42), **lesend**
  `leads_list` + `lead_get` für Formular-Einsendungen (`terminal42/contao-leads`)
  und **Übersetzen mit DeepL** (`numero2/contao-deepl`, siehe unten).
- **Author-Pass-Through**: Writes laufen unter dem echten OAuth-User in
  `tl_log` + `tl_version`.
- **Löschungen sind rückholbar**: Was die KI löscht, landet inklusive
  Kind-Datensätzen in `tl_undo` — wiederherstellbar über **Contaos normales
  „Rückgängig"** im Backend. Wiederherstellen bleibt bewusst Handarbeit: Die KI
  kann löschen, aber nichts stillschweigend zurückholen.
- **Löschungen, die etwas kaputt machen würden, werden blockiert**:
  `usage_find` beantwortet „wo wird das benutzt?" für Seiten, Dateien, Bilder,
  Artikel, Module, Formulare, Templates, Bildgrößen und alles Weitere — und
  **derselbe Check läuft automatisch vor jedem `*_delete`**. Gefunden wird an
  vier Stellen: DB-Felder (aus der DCA abgeleitet, also inkl. Extension-Feldern),
  **Insert-Tags in beliebigen Textspalten** (`{{link::42}}`, auch per Alias,
  `{{file::…}}`, `{{insert_module::…}}`), **in Dateien selbst** —
  `@import`/`url()` in SCSS/CSS, hartcodierte Pfade in Templates — und bei
  **Templates** jede `customTpl`/`…Tpl`-Spalte, die darauf zeigt, plus
  `{% extends %}` / `$this->extend()` aus anderen Templates. Damit fallen auch
  die Fälle auf, die keine Datenbankabfrage sieht: `_colors.scss` wird als
  `@import 'colors'` eingebunden, und ein gelöschtes `ce_text_custom` ändert
  stillschweigend, wie ein Content-Element rendert. Blockiert wird nur, was
  **beweisbar und schädlich** ist; Backend-Rechte-Mounts und bloße
  Namensnennungen werden berichtet, halten aber nichts auf. Überschreiben mit
  `ignore_references=true` (landet in `tl_log`).
- **Umbenennen und Verschieben werden mitgeprüft — aber nur, wo es wirklich
  bricht**: `file_rename`, `file_move` und `template_rename` laufen durch
  denselben Check. Contao behält beim Umbenennen Zeile, ID und UUID und
  schreibt nur `tl_files.path` neu — also überleben `singleSRC = <uuid>` und
  `{{file::<uuid>}}` das problemlos, während `{{file::files/x.svg}}`, ein
  SCSS-`@import` und ein hartcodierter Template-Pfad brechen. Blockiert wird
  deshalb **nur, was an diesem Pfad bzw. Namen hängt**; alles UUID-/ID-basierte
  wird gezeigt, hält aber nichts auf. Ein `.html5`-Template in einen anderen
  Ordner zu verschieben ist folgerichtig gar nicht blockiert: Contao findet es
  über den Basisnamen, der sich dabei nicht ändert.
- **Backend-Modul** „MCP-Server" mit vier Bereichen: Status (Lizenz + Testphase/
  Abo starten, OAuth-Clients, IATs), Konfiguration, Aktivitätslog, Tool-Panel
  (jedes Tool einzeln abschaltbar) — **nur für Contao-Administratoren**.
- **Linux + Windows getestet** (Laragon dev, Debian production).

## Installation

### 1. Composer

```bash
composer require netzhirsch/contao-mcp-bundle
```

Mehr ist nicht nötig — kein `repositories`-Eintrag, kein Patch-Block, kein
`allow-plugins`. Das Bundle liegt auf
[Packagist](https://packagist.org/packages/netzhirsch/contao-mcp-bundle).

Alternativ im **Contao Manager** nach „Contao MCP Bundle" suchen und
installieren.

### 2. Bundle registrieren

Auto-Discovery über das Contao Manager Plugin — kein manuelles Eintragen in
`config/bundles.php` nötig.

### 3. Schema-Migrationen + erste Konfig

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

### 4. Lizenz aktivieren (30 Tage kostenlos)

Ohne aktive Lizenz antworten alle Tools mit `license_inactive` — Contao selbst
läuft normal weiter. Im Backend unter **MCP-Server → Status** oben auf
**„Testphase starten"** klicken: 30 Tage, ohne Zahlungsdaten. Details siehe
[Lizenz & Testphase](#lizenz--testphase).

### 5. In Claude Desktop / Cowork einbinden

**Anleitungen im Repo:** [docs/installation.md](docs/installation.md)
(Client anbinden, online + lokal) und [docs/dokumentation.md](docs/dokumentation.md)
(vollständige Funktionsreferenz). Im Backend selbst gibt es keinen Doku-Tab mehr.

> **Client verbinden bei `oauth_registration_mode: restricted` (Default):** Der
> Weg ist **MCP-Server → Status → „Registrierung für 15 Minuten öffnen"**. Das
> Fenster bleibt die vollen 15 Minuten offen, egal wie viele Versuche das kostet
> (bis 1.4.0 schloss es nach der ersten erfolgreichen Registrierung — daher
> scheiterten Retrys und ein zweiter Client). Abgewiesene Versuche stehen mit
> Grund und IP unter **MCP-Server → Aktivität**.

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
- **Contao** 5.3 bis 6.0 (die CI fährt den Smoke-Test gegen 5.3, 5.7 **und** 6.0)
- **Symfony** ≥ 6.4, 7.x oder 8.x

### Contao 6

Läuft ohne Anpassung. Zwei Dinge sind beim Installieren zu beachten:

**Contao 6 verlangt PHP ≥ 8.4.** Die PHP-Untergrenze des Bundles bleibt bei 8.1,
damit Contao-5.3-Instanzen weiterlaufen — auf PHP 8.1 ist schlicht nur die
5er-Linie installierbar.

**Der Installationsbefehl braucht `-W`:**

```bash
composer require netzhirsch/contao-mcp-bundle -W
```

Grund ist nicht das Bundle, sondern `php-mcp/server`: es pinnt
`phpdocumentor/reflection-docblock` auf `^5.6` und `symfony/finder` auf `^7.2`,
während eine Contao-6-Installation beide höher auflöst. `-W` erlaubt Composer,
sie zurückzustufen. Beide Pakete vertragen das; ohne `-W` bricht die Auflösung
ab. Auf Contao 5 ist das Flag überflüssig.
- **MySQL** ≥ 8.0 oder MariaDB ≥ 10.6 (strict mode unterstützt)
- **Speicher für `var/mcp/`**: schreibbar, mehr nicht. Seit 1.9.1 schreibt das
  Bundle seine Zustandsdateien atomar per `rename()` und braucht **kein
  funktionierendes Datei-Locking** — NFS-Mounts ohne `lockd`/`statd` sind damit
  unproblematisch. Auf ≤ 1.9.0 konnte `flock()` dort unbegrenzt hängen und einen
  Gateway Timeout auslösen (siehe CHANGELOG 1.9.1).

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

## Verbinden ohne Pairing: CIMD

Seit 1.11.0 kann ein Client sich mit einer HTTPS-URL ausweisen, statt sich zu
registrieren — der Server liest die Client-Daten von dieser URL
([Client ID Metadata Document](https://datatracker.ietf.org/doc/html/draft-ietf-oauth-client-id-metadata-document-00)).
Für den Kunden heißt das: **kein Pairing-Fenster öffnen, nichts vorbereiten.**
Claude wählt diesen Weg von selbst, wenn die Instanz ihn ankündigt.

Umschaltbar im Backend unter **MCP-Server → Konfiguration**:

| Modus | Bedeutung |
|---|---|
| `trusted` *(Standard)* | nur `claude.ai`, `claude.com` und deren Subdomains |
| `open` | jede HTTPS-`client_id`, die offene Haltung der Spezifikation |
| `off` | nicht angekündigt, Clients registrieren sich wie bisher (DCR) |

Der Standard ist `trusted`, weil „jede HTTPS-URL akzeptieren" gleichbedeutend
ist mit „jede HTTPS-URL abrufen, die ein Aufrufer nennt". Auf dem
Produktivsystem eines Kunden ist das ein größeres Versprechen, als der Nutzen
hergibt — die Clients, mit denen Contao hier spricht, sind bekannt.

**Was beim Abruf passiert.** Das Dokument wird geholt, bevor irgendjemand
angemeldet ist, von einer URL, die der Aufrufer bestimmt. Entsprechend eng ist
der Rahmen:

- nur `https`, mit Pfad, ohne Fragment, ohne Zugangsdaten, ohne `.`/`..`, keine
  IP-Literale
- der Host wird aufgelöst, **jede** Antwort muss öffentlich routbar sein, und
  die Verbindung wird auf die geprüfte Adresse gepinnt (gegen DNS-Rebinding)
- geblockt sind neben RFC 1918 und Loopback auch CGNAT, `169.254.169.254`,
  NAT64 und IPv4-in-IPv6
- keine Weiterleitungen, 5 Sekunden Zeitlimit, 5 KB Größengrenze beim Streamen,
  `Content-Type` muss JSON sein
- Rate-Limit pro `client_id`-Host, nur bei Cache-Miss
- das `client_id`-Feld im Dokument muss exakt der abgerufenen URL entsprechen
- `logo_uri` wird ignoriert

**Redirect-URIs** werden exakt geprüft. Die einzige Ausnahme ist RFC 8252 §7.3:
Bei Loopback-Adressen wird der Port ignoriert, weil ein nativer Client seinen
Port nicht vorher kennt. Alles andere — Schema, Host, Pfad, Query — muss
stimmen, und `http://localhost.attacker.example/callback` fällt durch.

Sind alle Redirect-URIs eines Clients Loopback-Adressen, warnt die
Zustimmungsseite zusätzlich: Ein Metadatendokument kann nicht verhindern, dass
ein anderes Programm auf demselben Rechner einen Port belegt und den Namen des
echten Clients für sich beansprucht.

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
| `oauth_registration_mode` | `restricted` | `restricted` (Registrierung nur im Pairing-Fenster) oder `open` |
| `lazy_mode` | `false` | Wenn `true`: nur 6 Discovery-Tools in `tools/list` |

Bundle-eigene Konfig in `config/packages/netzhirsch_contao_mcp.yaml`:

```yaml
netzhirsch_contao_mcp:
    write:
        default_author_id: 1   # Fallback wenn auth_mode=none
    preview:
        # Nur nötig, wenn die Instanz hinter HTTP-Basic-Auth liegt.
        # Default ist die Env-Variable; ohne sie bleibt alles wie bisher.
        basic_auth: '%env(default::MCP_PREVIEW_BASIC_AUTH)%'
```

`page_preview` holt die Seite über ihre **öffentliche** URL — steht davor ein
Basic-Auth-Schutz (typisch auf Staging), antwortet der Webserver mit 401, bevor
Contao überhaupt läuft. Dann in der `.env.local` der Instanz:

```dotenv
MCP_PREVIEW_BASIC_AUTH="user:pass"
```

Das Tool weist bei 401/403 selbst darauf hin. Die Zugangsdaten stehen nur in der
`.env.local`, nie in der Antwort oder im Log.

## Übersetzen mit DeepL

Braucht [`numero2/contao-deepl`](https://github.com/numero2/contao-deepl) und
einen DeepL-API-Schlüssel. Beides konfiguriert man **einmal**, und zwar dort, wo
es das Bundle ohnehin erwartet:

```bash
composer require numero2/contao-deepl
```

```dotenv
DEEPL_API_KEY="…"
```

> Der Schlüssel ist Pflicht, sobald das Bundle installiert ist: `numero2` setzt
> `%env(DEEPL_API_KEY)%` ohne Fallback, ein fehlender Wert lässt schon
> `cache:clear` mit *„Environment variable not found"* abbrechen.

Danach erscheinen vier Tools. Fehlt eines von beidem, antworten sie mit
`extension_not_available` bzw. `deepl_not_configured` und sagen, was fehlt —
`deepl_status` beantwortet das direkt, inklusive der Liste der Zielsprachen.

| Tool | Wofür |
|---|---|
| `deepl_status` | Verfügbarkeit, Zielsprachen, optional der Kontostand |
| `deepl_translate` | Freitext rein, Übersetzung raus — rührt keinen Datensatz an |
| `deepl_translate_records` | Ein oder mehrere Datensätze **einer** Tabelle |
| `deepl_translate_page_tree` | Seite + Meta + Artikel + Inhalte + alle Unterseiten |

**Übersetzbar** sind `tl_page`, `tl_article`, `tl_content`, `tl_news`,
`tl_news_archive`, `tl_calendar_events`, `tl_calendar`, `tl_faq`,
`tl_faq_category`, `tl_form`, `tl_form_field` und `tl_module` — jeweils nur die
Spalten, die wirklich Fließtext enthalten. Contaos Strukturwerte bleiben intakt:
Eine Überschrift behält ihr `h2`, ein Listen-Element seine Reihenfolge, ein
Tabellen-Element seinen Zeilenschnitt, und Rich-Text geht mit DeepLs
`tag_handling=html` raus, damit Markup und Attribute überleben.

### Drei Modi, zwei Schalter

Weil „übersetzen", „Geld ausgeben" und „Inhalt überschreiben" drei verschiedene
Entscheidungen sind:

- **`dry_run: true`** — nur planen. Kein API-Aufruf, kein Schreibzugriff, keine
  Kosten. Antwortet mit den betroffenen Datensätzen, den Feldern und der
  Zeichenzahl, die der echte Lauf einreichen würde.
- **beides `false`** (Default) — übersetzen und **zurückgeben**. Nichts wird
  geschrieben. Auf 50 Datensätze begrenzt, weil hier jede Quelle und jede
  Übersetzung mitkommt.
- **`save: true`** — übersetzen und über das `*_update`-Tool der Tabelle
  schreiben: Versions-Snapshot, `tl_log`-Eintrag, `changed_fields` und die
  Rechteprüfung pro Datensatz, genau wie bei einem direkten Update.

Zusätzlich bremst `max_characters` (Default 100 000) **vor** dem ersten
API-Aufruf, wenn der Plan teurer wäre als erlaubt.

### Was ein Aufruf kostet

Jede Antwort führt mit, was sie verbraucht hat:

```json
"usage": { "characters_submitted": 482, "characters_reused": 16, "api_requests": 2 }
```

`characters_submitted` ist die Zahl, auf die DeepL abrechnet — tatsächlich
gesendete Quellzeichen. Übersetzungen werden 30 Tage zwischengespeichert
(eigener Cache, nach Zielsprache, Quellsprache **und** Tag-Handling
geschlüsselt), deshalb kostet die empfohlene Reihenfolge *planen → ansehen →
speichern* nur einmal. Der Kontozähler aus `deepl_status` ist eine
Abrechnungsperioden-Summe und läuft der Realität hinterher — er ist **nicht** der
Preis des letzten Aufrufs.

### Der übliche Weg zu einem zweiten Sprachbaum

Übersetzt wird **an Ort und Stelle**: Der Datensatz, den man nennt, ist der
Datensatz, der sich ändert. Für eine zweite Sprache also erst kopieren, dann die
Kopie übersetzen:

1. `entity_duplicate(table: "tl_page", id: 42, into_pid: <Ziel-Root>, with_children: true)`
2. `deepl_translate_page_tree(id: <die Kopie>, target_lang: "EN-GB", dry_run: true)` — was kostet das?
3. dasselbe mit `save: true`
4. `entity_language_link(...)` für die Verknüpfung mit changelanguage

**Aliase werden bewusst nicht übersetzt.** DeepL liefert Fließtext, kein Slug;
„Unsere Leistungen" gehört nicht in eine URL. Für übersetzte URLs erst den Titel
übersetzen und danach einen **leeren** Alias an `page_update` schicken — Contao
erzeugt ihn dann über den Slug-Service aus dem neuen Titel neu.

## Wie Tools Fehler melden

Ein Tool, das nicht tun kann, was es soll, gibt ein strukturiertes Ergebnis
zurück statt einer Ausnahme — mit `error`, einer `message` im Klartext und,
wo es hilft, der Liste des Erlaubten. Zwei Fälle, die man kennen sollte:

**Ein Feld, das der Datensatztyp nicht hat**, wird abgelehnt und nennt den Typ:

```
Field "gibtsNicht" is not valid for content type "text".
Use content_palette_get("text") to see allowed fields. Currently allowed: pid, ptable, …
```

**Ein Parameter, den das Tool nicht hat**, ebenso — mit Vorschlag bei einem
Tippfehler (seit 1.10.0; davor wurde er stillschweigend verworfen und der
Aufruf meldete Erfolg, ohne etwas zu ändern):

```
Tool "page_update" has no parameter "pageTitel" (did you mean "pageTitle"?).
Nothing was changed. Allowed parameters: id, pid, title, type, sorting, …
```

Beides gilt für direkte `tools/call` **und** für den `contao_call`-Proxy im
Lazy-Mode.

## Bekannte Einschränkungen

Stand `v1.8.2`:

- **PHPUnit-Coverage** deckt OAuth-Crypto, die Permission-Map und den
  Usage-Scanner ab. Der Tool-Layer wird stattdessen end-to-end vom Smoke-Test
  exerziert.
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

## Entwicklung: Prüfkette vor einem Release

```bash
composer verify
```

Bündelt PHPStan + PHPUnit — genau das, was die CI fährt. Einmal pro Klon
`composer setup-hooks` ausführen: Danach lehnt ein `pre-push`-Hook einen Push ab,
der die CI rot machen würde (`git push --no-verify` umgeht ihn im Notfall).

Der **Smoke-Test** braucht ein laufendes Contao samt Datenbank und ist deshalb
nicht Teil davon — er gehört vor jeden Release-Tag:

```bash
vendor/bin/contao-console contao:mcp:smoke-test --env=dev
```

Reihenfolge für einen Release: `composer verify` → Smoke-Test → committen →
pushen → **CI grün abwarten** → erst dann taggen.

## Sicherheitslücken melden

Bitte **nicht** über ein öffentliches Issue, sondern über die
[Security-Policy](SECURITY.md) (GitHub Security Advisory oder
<kalus@netzhirsch.de>).

## Bug-Reports

Issues / Findings bitte ins Repo, plus Anhang:

- Output von `system_health_check`
- Backend-User-Rolle + Contao-Version
- Relevante Einträge aus `var/log/prod.log` (Symfony-Standard-Log)

## Update von einer Version ≤ 1.4.0

**Nichts zu tun** — `composer update netzhirsch/contao-mcp-bundle` läuft durch,
auch wenn in der Root-`composer.json` noch der frühere Patch-Block steht. Die
`patches/`-Dateien liegen dafür bis 2.0.0 weiter im Paket; angewendet werden sie
von nichts mehr.

Wer aufräumen will (empfohlen, aber nicht dringend): `extra.patches`,
`cweagans/composer-patches` aus `require` und den `allow-plugins`-Eintrag aus der
Root-`composer.json` löschen, dann `composer update`. Der Vendor bleibt danach
gepatcht — das Plugin installiert `php-mcp/server` bei geschrumpfter Patch-Liste
nicht von sich aus neu. Folgenlos, weil `ContaoDispatcher` die betroffenen
Methoden überschreibt; wer es sauber will, hängt ein
`composer reinstall php-mcp/server` an. Details:
[`patches/README.md`](patches/README.md).

## Wartung

Composer-Updates des Bundles:

```bash
composer update netzhirsch/contao-mcp-bundle
```

Es werden **keine Vendor-Patches** mehr angewendet: was das Bundle am
Dispatcher braucht (Lazy-Mode-Filter, Post-Call-Cleanup), liegt in
`Server\ContaoDispatcher` als Subklasse. Bei einem `php-mcp/server`-Major-Bump
dort prüfen, ob `handleToolList()`/`handleToolCall()` noch passen.

### Wenn ein Update mit „no merge base" abbricht

Betrifft jede Instanz, auf der das Bundle **als Git-Checkout** im Vendor liegt
(„Source-Install"). Zwei Wege führen dorthin, und **beide, nicht nur der erste**:

1. Die Versionsangabe ist ein Branch (`dev-master`) — für Branches installiert
   Composer standardmäßig aus der Quelle.
2. Die Root-`composer.json` enthält noch einen `repositories`-Eintrag vom Typ
   `vcs` auf das GitHub-Repository. Ohne GitHub-Token liefert der **kein**
   Dist-Archiv, also installiert Composer auch einen **Tag** aus der Quelle.

Ob es einen trifft, steht im Log: Bei einem Archiv steht dort `Downloading
netzhirsch/contao-mcp-bundle`, bei einem Source-Install `Syncing
netzhirsch/contao-mcp-bundle … into cache`.

Vor jedem Update prüft Composer den Checkout mit
`git diff --name-status origin/master...master` auf lokale Änderungen. Diese Drei-Punkt-Form braucht einen gemeinsamen Vorfahren — und wenn
der Composer-Cache zwischendurch neu aufgebaut wurde, hat der Vendor-Klon
gegenüber seinem `origin` keinen mehr:

```
In GitDownloader.php line 236:
  Failed to execute git diff --name-status origin/master...master --
  fatal: origin/master...master: no merge base
```

Composer schreibt diesen Fehler unter das Paket, das gerade an der Reihe war —
im beobachteten Fall `php-mcp/server`. Der Branch im Kommando verrät den
Verursacher: `php-mcp/server` liegt auf `main`, `master` ist **dieses** Bundle.

**Reparatur mit Shell** — den kaputten Checkout wegwerfen, Composer holt ihn neu:

```bash
rm -rf vendor/netzhirsch/contao-mcp-bundle
composer install --no-dev --optimize-autoloader
```

**Über den Contao Manager allein geht es nicht.** Weder Aktualisieren noch
Entfernen des Pakets hilft, weil Composer den Checkout prüft, *bevor* es
irgendetwas mit ihm tut — in `VcsDownloader::prepare()`, und zwar für **beide**
Fälle:

```php
if ($type === 'update')         { $this->cleanChanges($prevPackage, $path, true); }
elseif ($type === 'uninstall')  { $this->cleanChanges($package, $path, false); }
```

`prepare()` läuft vor jeder Paket-Ausgabe, deshalb bricht auch ein
`composer remove` ab, ohne eine einzige `- Removing …`-Zeile zu drucken. Es
braucht also **Datei-Zugriff**: FTP/SFTP, den Dateimanager des Hosters oder SSH.

**Kleinster Eingriff** (FTP/SFTP, Hoster-Dateimanager): nur den Ordner
`vendor/netzhirsch/contao-mcp-bundle/.git` löschen — versteckte Dateien im
Client einblenden. Composer erkennt einen Git-Checkout ausschließlich an
`is_dir($path.'/.git')`; ohne dieses Verzeichnis steigt die Prüfung sofort aus,
und der nächste Vorgang im Manager läuft durch. Der Code bleibt liegen, die
Instanz läuft in der Zwischenzeit normal weiter.

Alternativ das ganze Verzeichnis `vendor/netzhirsch/contao-mcp-bundle` löschen
und im Manager das Paket neu hinzufügen. Datenbank (`tl_mcp_oauth_*`), Lizenz
und `var/mcp/` bleiben dabei unangetastet, der Konnektor verbindet sich danach
unverändert.

**Den Composer-Cache zu leeren reicht nicht.** „no merge base" heißt, dass beide
Refs aufgelöst werden konnten und keine gemeinsame Historie haben — das Problem
sitzt im Vendor-Klon, den ein Cache-Leeren gar nicht anfasst.

Damit es gar nicht erst auftritt: das Bundle als Archiv statt als Git-Checkout
installieren. Dann ist der `GitDownloader` nicht beteiligt.

```bash
composer config preferred-install.netzhirsch/contao-mcp-bundle dist
composer update netzhirsch/contao-mcp-bundle
```

**Die eigentliche Ursache beseitigen:** Das Bundle liegt auf
[Packagist](https://packagist.org/packages/netzhirsch/contao-mcp-bundle), und
Packagist liefert zu jedem Tag ein Zip. Ein `repositories`-Eintrag vom Typ `vcs`
auf GitHub ist damit überflüssig — und solange er dort steht, gewinnt er gegen
Packagist und erzwingt den Git-Checkout. Also aus der Root-`composer.json`
entfernen:

```jsonc
"repositories": [
    { "type": "vcs", "url": "git@github.com:Netzhirsch/contao-mcp-bundle.git" }  // ← weg
]
```

Danach `composer update netzhirsch/contao-mcp-bundle`. Ein Tag kommt dann als
Archiv, und der `GitDownloader` ist gar nicht mehr beteiligt.

### Wenn Composer über `psr/http-message` stolpert

Die Meldung sieht so aus:

```
- php-mcp/server 3.3.0 requires react/http ^1.11 -> satisfiable by react/http[v1.11.0].
- react/http v1.11.0 requires psr/http-message ^1.0 -> found psr/http-message[1.0, 1.0.1, 1.1]
  but these were not loaded, likely because it conflicts with another require.
```

**Ab Version 1.9.0 tritt das nicht mehr auf**: das Bundle installiert
`react/http` nicht mehr mit (siehe CHANGELOG). Auf einer Installation ≤ 1.8.x
ist ein Update auf `^1.9` die Lösung.

Falls die Meldung trotzdem auftaucht, ist der Hintergrund immer derselbe:
irgendein Paket im Projekt verlangt `psr/http-message ^2.0`, ein anderes
besteht auf `^1.0`. Wer das ist, zeigt die `composer.lock`:

```bash
php -r '$l=json_decode(file_get_contents("composer.lock"),true); foreach($l["packages"] as $p){$c=$p["require"]["psr/http-message"]??null; if($c)printf("%-42s %s\n",$p["name"],$c);}'
```

Gesucht ist die Zeile, in der **kein** `^1.` vorkommt — das ist der Blockierer.

Eine Falle beim Beheben: `composer update <paket> --with-all-dependencies`
hilft hier **nicht**. Ein Teil-Update darf nur Abhängigkeiten der genannten
Pakete bewegen — der Blockierer ist aber meist ein Geschwister, kein Kind.
Er muss mit auf die Kommandozeile, sonst bleibt `-W` wirkungslos.

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
