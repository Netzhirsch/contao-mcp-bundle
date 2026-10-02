# Contao MCP Bundle

[![CI](https://github.com/Netzhirsch/contao-mcp-bundle/actions/workflows/ci.yml/badge.svg)](https://github.com/Netzhirsch/contao-mcp-bundle/actions/workflows/ci.yml)

*🇬🇧 [English version](README.en.md) — diese deutsche Fassung ist die Referenz.*

**Status:** Stable — aktuelle Version und alle Änderungen im [CHANGELOG](CHANGELOG.md)
**Lizenz:** proprietär, kommerziell lizenziert — 30 Tage kostenlos testen,
danach 49 €/Monat je Contao-Instanz (siehe [Lizenz & Testphase](#lizenz--testphase)
und [LICENSE](LICENSE))

Ein [Model Context Protocol](https://modelcontextprotocol.io/)-Server als
Bundle für Contao 5.3 LTS, 5.7 LTS und 6.0. Verbindet Claude Desktop, Claude in der API, Claude Code,
MCP Inspector oder jede andere MCP-fähige KI direkt mit dem Contao-Backend —
ohne eigene REST-Endpunkte, ohne Middleware, ohne Port.

Statt jeder KI-Aufgabe einen eigenen API-Endpunkt nachzuziehen, bekommt die
KI-Session strukturierten Zugriff auf den gesamten DCA-Stack: Redakteure können
per natürlichsprachlichem Auftrag Inhalte anlegen, Pipelines können Seiten
vollautomatisch aus Drittsystemen befüllen, Entwickler können Strukturmigrationen
skripten — alles über dieselben 196 Tools, abgesichert mit denselben
Backend-Benutzerrechten wie beim manuellen Bearbeiten.

**Unterstützte Entitäten:** Seiten, Artikel, Inhaltselemente, News, Kalender,
FAQ, Mitglieder und Mitgliedergruppen, Backend-Benutzer und -Gruppen (lesend),
Formulare und Formularfelder, Newsletter, Kommentare, Themes, Layouts, Module,
Bildgrößen, Templates, Dateien, URL-Rewrites, Formular-Leads (lesend),
OpenGraph-/X-Card-Daten, Suchindex, Wartung + System-Einstellungen.

## Was drin ist

- **196 Tools** über Contao-Kernentitäten + populäre Extensions.
- **Lazy-Mode-Discovery** (zuschaltbar über `lazy_mode`, Standard: aus):
  `tools/list` zeigt nur die drei Meta-Tools `contao_search_tools`,
  `contao_describe_tool` und `contao_call` sowie `ping`, `contao_version` und
  `installed_bundles`; alle übrigen bleiben über `contao_call` erreichbar. Statt
  rund 180 KB Tool-Schemas gehen so pro Turn rund 3 KB an den Client.
- **OAuth 2.1** mit PKCE, Client-ID-Metadatendokumenten (CIMD),
  Dynamic Client Registration (RFC 7591) und Protected-Resource-Metadata
  (RFC 9728). Mit CIMD verbindet sich Claude **ohne Registrierungsschritt** —
  kein Pairing-Fenster, keine geöffnete Registrierung. Wer
  registrieren will, kann es weiterhin: im Default-Modus `restricted`
  ausschließlich im 15-Minuten-Pairing-Fenster.
- **Rechte-Parität**: Die Rechte des Backend-Benutzers gelten für die KI 1:1,
  durchgesetzt über Contaos eigene Voter statt nachgebaut. Ein Feld zu
  schreiben braucht zusätzlich sein Feldrecht („Erlaubte Felder" in der
  Benutzergruppe), wo Contao es verlangt. Seit Contao 5 ist das jedes Feld mit
  Eingabe, sofern das DCA es nicht mit `exclude => false` freigibt. Ein Wert, der
  nichts ändert (beim Anlegen der Default, beim Ändern der gespeicherte Wert),
  braucht kein Recht, wie im Backend. Ebenso `rsce_data`: RSCE schreibt die
  Spalte über virtuelle Felder, die nie ein Feldrecht verlangen.
  Nicht-Administratoren brauchen außerdem das Häkchen **„MCP-Server-Zugriff
  erlauben"** am Benutzer oder an einer seiner Gruppen (Standard: aus); ohne
  es wird jeder Aufruf mit `mcp_access_denied` abgewiesen.
- **Volltextsuche über die Website**: `search_query` durchsucht Contaos
  Suchindex (`tl_search`) — findet also auch Text, der aus Modulen, Includes
  oder Erweiterungen stammt und über die CRUD-Tools nicht auffindbar wäre.
  Geschützte Seiten bleiben außen vor, und ein eingeschränkter Benutzer sieht
  nur Treffer aus seinen eigenen Seiten-Mounts (`out_of_scope_skipped` zählt
  den Rest); `search_index_status` zeigt, ob der Index überhaupt befüllt ist.
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
- **External IDs** machen wiederholte Importe idempotent — dieselbe Quellzeile
  aktualisiert denselben Datensatz, statt Dubletten anzulegen.
- **`html_filter_info` + `html_filter_preview`**: was der Ausgabefilter von
  eigenem Markup übrig lässt — **bevor** es geschrieben wird. Gespeichert ist
  nicht gerendert: Der Read-back liefert das Markup unverändert zurück, während
  `<input type>` und `<label for>` im Frontend längst entfernt sind.
- **Optionale Bundles**: Die Tools sind immer registriert und arbeiten,
  sobald das jeweilige Paket installiert ist — News, Kalender, FAQ, Newsletter
  und Kommentare (Contao-Bundles, in der Managed Edition enthalten),
  `url_rewrite_*` (terminal42), **lesend** `leads_list` + `lead_get` für
  Formular-Einsendungen (`terminal42/contao-leads`), **OpenGraph & X-Cards**
  (`numero2/contao-opengraph3`, siehe unten), **Übersetzen mit DeepL**
  (`numero2/contao-deepl`, siehe unten) und die Sprachverknüpfung
  `entity_language_link` (`terminal42/contao-changelanguage`). Ohne ihr Paket
  antworten Newsletter-, Kommentar- und Erweiterungs-Tools mit
  `extension_not_available`; die News-, Kalender- und FAQ-Tools setzen ihr
  Bundle voraus.
- **Dateien hochladen**, auch große: `file_upload_begin`/`_chunk`/`_finish`
  übertragen eine Datei in Stücken, ohne dass sie vorher irgendwo öffentlich
  liegen muss; Größe, `sha256` und Magic Bytes werden geprüft, bevor etwas
  geschrieben wird (siehe unten).
- **Fremdtext ist markiert**: Lese-Antworten nennen unter `_untrusted_fields`
  die Felder, in denen Besucher- oder Redaktionstext steht — damit ein Agent
  Anweisungen darin nicht für Anweisungen hält.
- **Leitfaden als MCP-Prompt** `contao_guide`, erzeugt aus dem Zustand dieser
  Installation: Versionen, Zahl der Tools, Lazy-Mode, installierte und
  fehlende Erweiterungen.
- **Erweiterbar**: Andere Bundles können eigene Tools (im Tool-Panel einzeln
  freizuschalten) und Felder beisteuern — siehe [EXTENDING.md](EXTENDING.md).
- **RockSolid Custom Elements**: RSCE-Elemente (`rsce_*`) lassen sich als
  Inhaltselement, Frontend-Modul und Formularfeld anlegen **und** konfigurieren.
  `rsce_data` wird gegen die
  `rsce_*_config.php` geprüft, in das Gespeicherte gemergt und so abgelegt, wie
  es das Backend ablegt (siehe unten).
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
- **Backend-Modul** „MCP-Server" mit vier Bereichen: Status (Lizenz +
  Testphase/Abo, Update-Hinweis, Pairing-Fenster, OAuth-Clients),
  Konfiguration, Aktivitätslog, Tool-Panel (jedes Tool einzeln abschaltbar —
  bis auf `contao_search_tools`, `contao_describe_tool`, `contao_call` und
  `ping`) — **nur für Contao-Administratoren**.
- **Linux + Windows getestet** (Laragon dev, Debian production).

## Installation

### 1. Composer

```bash
composer require netzhirsch/contao-mcp-bundle
```

Mehr ist nicht nötig (unter Contao 6 mit `-W`, siehe [Contao 6](#contao-6)) —
kein `repositories`-Eintrag, kein Patch-Block, kein `allow-plugins`. Das Bundle liegt auf
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
External-ID-Spalten auf 24 Entity-Tabellen. **Der Endpunkt ist ab Werk geschlossen.** Seit 1.22.0 ist `auth_mode`
standardmäßig `oauth`; eine Instanz, deren Konfiguration noch nie gespeichert
wurde, antwortet auf `/mcp` mit **503** und nennt das Backend-Modul. Erst dort
wählt man den Modus — `none` bleibt möglich, aber nur als ausdrückliche
Entscheidung für einen privaten oder Loopback-Host.

Die Route `/mcp` ist nach der Migration sofort registriert
(`<backend_url>/mcp`) — Apache/PHP-FPM serviert sie wie jede andere
Symfony-Route. Kein Daemon, kein Port, kein Reverse-Proxy nötig. Antworten gibt
sie, sobald die Konfiguration einmal gespeichert und eine Lizenz oder Testphase
aktiv ist.

### 4. Lizenz aktivieren (30 Tage kostenlos)

Ohne aktive Lizenz antworten alle Tools mit `license_inactive` — Contao selbst
läuft normal weiter. Im Backend unter **MCP-Server → Status** oben auf
**„Testphase starten"** klicken: 30 Tage, ohne Zahlungsdaten. Details siehe
[Lizenz & Testphase](#lizenz--testphase).

### 5. In Claude Desktop / Cowork einbinden

**Anleitungen im Repo:** [docs/installation.md](docs/installation.md)
(Client anbinden, online + lokal) und [docs/dokumentation.md](docs/dokumentation.md)
(vollständige Funktionsreferenz). Im Backend selbst gibt es keinen Doku-Tab mehr.

> **Client verbinden bei `oauth_registration_mode: restricted` (Default):**
> Claude-Connectors brauchen dafür nichts zu öffnen, solange CIMD aktiv ist
> (Standard, siehe [Verbinden ohne Pairing: CIMD](#verbinden-ohne-pairing-cimd)).
> Für Clients, die sich per Dynamic Client Registration anmelden — etwa
> `mcp-remote` oder der MCP Inspector —, ist der Weg
> **MCP-Server → Status → „Registrierung für 15 Minuten öffnen"**. Das
> Fenster bleibt die vollen 15 Minuten offen, egal wie viele Versuche das kostet
> (bis 1.4.0 schloss es nach der ersten erfolgreichen Registrierung — daher
> scheiterten Retrys und ein zweiter Client). Abgewiesene Versuche stehen mit
> Grund und IP unter **MCP-Server → Aktivität**.

Schritt-für-Schritt-Anleitung für die lokale Connector-Einrichtung
(`mcp-remote`-Bridge, `claude_desktop_config.json`, OAuth, Schema-Cache +
Stolperfallen): **[docs/mcp-client-lokal-einrichten.md](docs/mcp-client-lokal-einrichten.md)**.

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

**Was dabei an den Lizenzserver geht.** Die Erneuerung meldet: das Produkt,
die Domain, das bisherige Lizenz-Token, ein zufälliges Instanz-Geheimnis als
Eigentumsnachweis — und seit 1.26.0 drei Versionsangaben: **Bundle-, Contao-
und PHP-Version.** Mehr nicht. Kein Inhalt, keine Benutzerdaten, keine Seiten- oder
Nutzungszahlen, keine Liste installierter Erweiterungen. Der Server nimmt
solche Felder auch gar nicht an. Die Versionsangaben beantworten zwei Fragen,
die sonst jeden Supportfall begleiten: welche Version bei einem Kunden läuft,
und ob ein Kompatibilitäts-Zweig für alte Bundles noch gebraucht wird.

**Was zurückkommt.** Neben dem Token seit 1.27.0 optional ein Hinweis auf eine
neuere Version: Versionsnummer, Link auf die Release Notes und ob es sich um
ein **Sicherheitsupdate** handelt. Der Hinweis steht dann im Backend unter
MCP-Server → Status und in `contao:mcp:license status`. Er informiert und sonst
nichts: Er aktualisiert nichts selbst, blockiert nichts, und auf einer
Dev-Installation (`dev-master`) erscheint er gar nicht. Ob eine Version
angekündigt wird, entscheidet Netzhirsch von Hand — ein neuer Tag ist nicht
automatisch eine Ankündigung.

**Verlängerung läuft automatisch.** Der Cron `LicenseRenewalCron` (stündlich,
gedrosselt) erneuert das Token; die Prüfung selbst ist **offline** (Ed25519).
Ein Ausfall des Lizenzservers sperrt daher niemanden aus — zusätzlich gelten
3 Tage Kulanz nach Ablauf. Voraussetzung ist ein laufender Contao-Cron.

> Verbindung zum Lizenzserver: `https://license.netzhirsch.de`, fest im Bundle
> hinterlegt — **nichts zu konfigurieren**. Übertragen werden Domain, Produkt,
> das Lizenz-Token, das Instanz-Geheimnis, die drei Versionsangaben und — nur
> beim Start einer Testphase oder eines Abos — die E-Mail des auslösenden
> Backend-Users bzw. die an `contao:mcp:license trial` übergebene.

## Anforderungen

- **PHP** `^8.1` mit Extensions: `openssl`, **`sodium`**, `pdo_mysql`, `mbstring`,
  `intl` (`sodium` ist für die Lizenzprüfung zwingend — fehlt es, bleiben alle
  Tools gesperrt; die 8.1-Untergrenze deckt Contao 5.3 LTS ab — 5.7 braucht
  PHP ≥ 8.3, 6.0 PHP ≥ 8.4)
- **Contao** 5.3 LTS, 5.7 LTS und 6.0 — genau die Versionen, gegen die die CI
  testet. 5.4, 5.5 und 5.6 sind bei Contao am Ende ihrer Laufzeit und werden
  nicht unterstützt; eine Installation darauf bleibt bei Version 1.37.1 des
  Bundles stehen, bis Contao aktualisiert ist.
- **Symfony** 6.4 (Contao 5.3), 7.4 (Contao 5.7 und 6.0) oder 8.x (Contao 6.0)
- **MySQL** ≥ 8.0 oder MariaDB ≥ 10.6 (strict mode unterstützt)
- **Speicher für `var/mcp/`**: schreibbar, mehr nicht. Seit 1.9.1 schreibt das
  Bundle seine Zustandsdateien atomar per `rename()` und braucht **kein
  funktionierendes Datei-Locking** — NFS-Mounts ohne `lockd`/`statd` sind damit
  unproblematisch. Auf ≤ 1.9.0 konnte `flock()` dort unbegrenzt hängen und einen
  Gateway Timeout auslösen (siehe CHANGELOG 1.9.1).
- **HTTPS** in Produktion — für OAuth 2.1 praktisch Pflicht.

Shared Hosting geht: Das Bundle läuft rein über HTTP, braucht keinen Daemon,
keinen offenen Port und keinen Shell-Zugang (dann über den Contao Manager
installieren).

### Contao 6

Läuft ohne Anpassung — unter Symfony 8 seit 1.37.1; davor antworteten dort
`/mcp`, `/mcp/healthz` und die `.well-known`-Metadaten mit 404, und die
Backend-Seiten unter „MCP-Server" brachen mit Fehler 500 ab. Zwei Dinge sind
beim Installieren zu beachten:

**Contao 6 verlangt PHP ≥ 8.4.** Die PHP-Untergrenze des Bundles bleibt bei 8.1,
damit Contao-5.3-Instanzen weiterlaufen — auf PHP 8.1 und 8.2 ist nur
Contao 5.3 installierbar.

**Der Installationsbefehl braucht `-W`:**

```bash
composer require netzhirsch/contao-mcp-bundle -W
```

Grund ist nicht das Bundle, sondern `php-mcp/server`: es pinnt
`phpdocumentor/reflection-docblock` auf `^5.6` und `symfony/finder` auf `^6.4 || ^7.2`,
während eine Contao-6-Installation beide höher auflöst. `-W` erlaubt Composer,
sie zurückzustufen. Beide Pakete vertragen das; ohne `-W` bricht die Auflösung
ab. Auf Contao 5 ist das Flag überflüssig.

## Transport und Protokoll

Streamable HTTP auf einem einzigen Endpunkt, `POST /mcp`. Einen **SSE-Kanal gibt
es nicht** — keinen GET-Stream und keine vom Server angestoßenen Nachrichten,
nur JSON als Anfrage und Antwort. Protokollrevision **2025-03-26**; Clients mit
**2024-11-05** werden ebenfalls angenommen. Jeder MCP-Client, der Streamable HTTP
mit OAuth spricht, sollte funktionieren; getestet wird gegen Claude. Ein
JSON-RPC-Batch nimmt höchstens 50 Aufrufe.

## Smoke-Test

```bash
vendor/bin/contao-console contao:mcp:smoke-test --env=dev
```

Geht rund 500 Prüfungen gegen den Tool-Layer durch (CRUD auf Member/Group/Form/
Newsletter/Comments/Theme/Layout/Templates/Maintenance + Content-Baum +
Rechte-Parität + External-ID + Audit-Regressions + Key-Rotation + Rate-Limit +
MCP-Activity-Log), erstellt eigene Testdaten, räumt am Ende wieder auf. Soll
grün durchlaufen.

Fehlt eines der optionalen Contao-Bundles (News, Kalender, FAQ, Kommentare,
Newsletter), überspringt er die Prüfungen, die es braucht, statt abzubrechen —
jede auf einer eigenen ⊝-Zeile. Die Zusammenfassung zählt sie getrennt von den
bestandenen (`15 section(s) skipped — not installed: …`): kein Fehlschlag, aber
ein Hinweis, dass weniger geprüft wurde als auf einer vollen Installation.

Für einzelne Prüfungen stellt der Test `var/mcp/config.json` kurz um — unter
anderem auf `auth_mode=none` und ein offenes Pairing-Fenster — und stellt die
Datei am Ende in jedem Fall wieder her, auch wenn ein Abschnitt abbricht. Für diese
Sekunden gelten die Werte aber wirklich; deshalb gehört er auf eine Staging-
oder eine nicht öffentlich erreichbare Instanz. Backend-Seiten und
HTTP-Routing prüft er nicht, er ruft die Tools im Prozess auf — das übernimmt
die CI.

Auf einer **frischen Installation** (keine Root-Seite, kein Administrator oder
keine Datei) legt der Test die fehlenden Fixtures für die Dauer des Laufs an:
einen Seitenbaum mit Artikel (mit News-Bundle dazu ein Nachrichtenarchiv),
einen Administrator mit zufälligem, nie
angezeigtem Passwort und eine Datei. Danach entfernt er sie wieder, auch wenn
ein Abschnitt abbricht. So laufen in CI dieselben Abschnitte wie auf einer
gepflegten Installation. `--keep` lässt auch die Fixtures stehen.

Zusätzlich gibt es eine PHPUnit-Suite (`composer verify` = PHPStan + PHPUnit,
im Checkout des Bundles) für alles, was ohne Datenbank prüfbar ist: OAuth und
CIMD, Rechte- und Feldrechteprüfung, Lizenz, Lösch-Schutz, Upload-Prüfung,
DeepL, RSCE, die Backend-Templates und die Kompatibilität mit Contao 6 und
Symfony 8.

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
Site-Move oder Server-Wechsel laufen lassen; das Tool braucht einen
Administrator.

Für ein Monitoring ohne Token gibt es `GET /mcp/healthz`: 200, wenn die
Datenbank antwortet, `var/mcp/` schreibbar ist, die OAuth-Schlüssel da sind (im
Modus `oauth`) und mindestens 50 MB frei sind — sonst 503 mit den Namen der
fehlgeschlagenen Prüfungen.

## Rate-Limits

- `/mcp`: 600 Anfragen pro Minute je OAuth-Client (gleitendes Fenster). Jede
  POST-Anfrage zählt, `tools/list` und `initialize` eingeschlossen, und jeder
  Aufruf in einem Batch zählt einzeln (höchstens 50 pro Batch); darüber kommt
  429 mit `Retry-After`. Mit `auth_mode: none` gibt es kein Limit.
- Pro IP: `/_mcp_oauth/register` 10 pro Stunde, `/_mcp_oauth/token` 60 pro
  Minute, `/_mcp_oauth/authorize` 30 pro Minute.
- CIMD-Abrufe: 30 pro Stunde je `client_id`-Host, 120 pro Stunde insgesamt.

## Verbinden ohne Pairing: CIMD

Seit 1.11.0 kann ein Client sich mit einer HTTPS-URL ausweisen, statt sich zu
registrieren — der Server liest die Client-Daten von dieser URL
([Client ID Metadata Document](https://datatracker.ietf.org/doc/html/draft-ietf-oauth-client-id-metadata-document-00)).
Für den Kunden heißt das: **kein Pairing-Fenster öffnen.** Eine gespeicherte
Konfiguration mit `backend_url` und eine aktive Lizenz braucht die Instanz
trotzdem.
Claude wählt diesen Weg von selbst, wenn die Instanz ihn ankündigt.

Umschaltbar im Backend unter **MCP-Server → Konfiguration**:

| Modus | Bedeutung |
|---|---|
| `trusted` *(Standard)* | nur die Hosts aus `cimd_trusted_hosts` (Standard: `claude.ai`, `claude.com`) und deren Subdomains; die Liste steht nur in `var/mcp/config.json` |
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
- Rate-Limit pro `client_id`-Host (30 pro Stunde) und insgesamt (120 pro
  Stunde), nur bei Cache-Miss
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

Datei: `var/mcp/config.json` (Rechte `0600`). Sie entsteht beim **ersten
Speichern** im Backend — Konfiguration, Tool-Panel oder Pairing-Button; bis
dahin antwortet `/mcp` mit 503.

> Die vier **MCP-Server-Backendmodule sind Administratoren vorbehalten** — sie
> schalten `auth_mode` (und damit die komplette Rechteprüfung), vergeben
> OAuth-Registrierungen, widerrufen Clients und schließen kostenpflichtige Abos
> ab. Ein Nicht-Admin bekommt auch mit gesetztem Modulrecht „Zugriff verweigert".

Felder:

| Key | Default | Bedeutung |
|---|---|---|
| `path` | `mcp` | Pfad, den die Backend-Anzeige und `oauth-protected-resource` nennen. Der Endpunkt selbst liegt fest unter `/mcp` — ein anderer Wert verschiebt ihn nicht, also auf `mcp` lassen |
| `pagination_limit` | `500` | Max Tools pro `tools/list` (irrelevant in Lazy-Mode) |
| `auth_mode` | `oauth` | `oauth` oder `none` (nur für private oder Loopback-Hosts) |
| `backend_url` | `""` | Public Base-URL des Contao-Backends (Pflicht bei OAuth) |
| `oauth_registration_mode` | `restricted` | `restricted` (Registrierung nur im Pairing-Fenster) oder `open` |
| `cimd_mode` | `trusted` | `trusted`, `open` oder `off` (siehe [CIMD](#verbinden-ohne-pairing-cimd)) |
| `cimd_trusted_hosts` | `["claude.ai", "claude.com"]` | Hosts samt Subdomains für `trusted`; kein Formularfeld, nur in der Datei |
| `lazy_mode` | `false` | Wenn `true`: `tools/list` zeigt nur die drei Meta-Tools plus `ping`, `contao_version`, `installed_bundles` |
| `disabled_tools` | `[]` | abgeschaltete Tools, gepflegt im Tool-Panel |
| `extension_tools_enabled` | `[]` | freigeschaltete Tools anderer Bundles, ebenfalls im Tool-Panel ([EXTENDING.md](EXTENDING.md)) |
| `registration_open_until` | `0` | Ende des Pairing-Fensters (Unix-Zeit), gesetzt vom Button auf der Status-Seite |
| `license_server_url` | `""` | nur für die Entwicklung: ersetzt den eingebauten Lizenzserver |

Bundle-eigene Konfig in `config/config.yaml` — die Contao Managed Edition liest
`config/packages/` nicht:

```yaml
contao_mcp:
    write:
        # Autor der Schreib-Tools, wenn kein angemeldeter Benutzer bekannt ist
        # (auth_mode=none); leer = der Administrator mit der niedrigsten ID
        default_author_id: 1
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

## Dateien hochladen

Für kleine Dateien genügt `file_upload` mit `content_base64`. Oberhalb von rund
50 KB bricht das: Der MCP-Transport kürzt lange Base64-Zeichenketten. Bisher
blieb nur `source_url` — der Server holt die Datei selbst —, und das setzt
voraus, dass sie auf einem öffentlich erreichbaren Host liegt. Für etwas, das
ein Client gerade erst erzeugt hat, ist das keine Option.

Dafür gibt es den gechunkten Weg:

```
file_upload_begin(parent_path, name, total_size_bytes, overwrite?, meta?, sha256?)
  → { upload_id, chunk_size_recommended, next_sequence }

file_upload_chunk(upload_id, sequence, content_base64)   ← mehrfach, in Reihenfolge
  → { received_bytes, remaining_bytes, next_sequence, complete }

file_upload_finish(upload_id)
  → wie file_upload

file_upload_abort(upload_id)
```

**Wichtig beim Zerlegen:** die **rohen Bytes** in Scheiben schneiden und jede
Scheibe einzeln base64-kodieren. Nicht die ganze Datei kodieren und dann die
Base64-Zeichenkette zerschneiden.

Was wann geprüft wird:

| Zeitpunkt | Prüfung |
|---|---|
| `begin` | Zielordner, Dateiname, Endung gegen `tl_settings.uploadTypes`, angekündigte Größe gegen `maxFileSize`, `meta`, bestehende Datei (ohne `overwrite`) |
| jeder `chunk` | Reihenfolge (lückenlos, genau einmal), laufende Summe ≤ angekündigte Größe |
| `finish` | Summe stimmt, `sha256` stimmt, **Magic Bytes gegen die Endung**, aktives Markup, Überschreiben |

Die Inhaltsprüfung sitzt bewusst am Ende: Ein einzelner Chunk sagt nichts über
die Datei aus, in der er landet. Eine `.png`, die in Wahrheit HTML ist, fliegt
deshalb erst beim Abschluss auf — aber sie fliegt auf, und geschrieben wird
nichts.

Die Zwischenablage liegt unter `var/mcp/uploads/<id>/`, Verzeichnis `0700`,
Dateien `0600`, außerhalb des Web-Roots. Eine Sitzung gehört dem Backend-Benutzer,
der sie geöffnet hat, und verfällt nach einer Stunde; beim nächsten `begin`
werden abgelaufene Reste entfernt. Ein eigener Cron ist dafür nicht nötig.

Lehnt `finish` das Ablegen ab — die Datei existiert inzwischen, der Inhalt passt
nicht zur Endung —, **bleibt die Sitzung bestehen**, damit nicht alles noch
einmal übertragen werden muss. Ist der Zielordner verschwunden oder stimmen
Summe oder `sha256` nicht, wird sie verworfen; dann neu mit
`file_upload_begin`.

## OpenGraph & X-Cards

Braucht [`numero2/contao-opengraph3`](https://github.com/numero2/contao-opengraph3)
(ab v5, nur unter Contao 5.7 und 6.0 — für 5.3 gibt es keine passende Fassung).
Ohne die Erweiterung melden die Tools sauber `extension_not_available`.

```bash
composer require numero2/contao-opengraph3
vendor/bin/contao-console contao:migrate
```

Die Erweiterung hängt ihre Felder an `tl_page`, `tl_news`,
`tl_calendar_events` und `tl_faq`. Der Haken dabei: Von den rund 60 Feldern
sind nur neun echte Spalten — **alle übrigen liegen in der Spalte
`og_properties`**, einer serialisierten Liste aus `[Feldname, Wert]`. Und
welche davon gültig sind, hängt am gewählten `og_type`. Das Backend-Widget
**verwirft beim nächsten Speichern stillschweigend** jede Eigenschaft, die
nicht zum Typ passt.

Deshalb gibt es drei Tools:

| Tool | Zweck |
|---|---|
| `opengraph_get(table, id)` | Spalten **und** Eigenschaften als *eine* flache Map, dazu `allowed_types`, `valid_properties` und `stale_properties` |
| `opengraph_set(table, id, fields, dry_run)` | schreibt dieselbe flache Map zurück; verteilt selbst auf Spalte oder Blob |
| `opengraph_types(table)` | welche `og_type`-Werte die Tabelle zulässt und welche Eigenschaften jeder freischaltet |

`stale_properties` ist der Blick auf vorhandenen Schaden: Eigenschaften, die
gespeichert sind, aber nicht zum aktuellen `og_type` gehören — sie
verschwinden beim nächsten Backend-Speichern.

Eine unpassende Eigenschaft wird **abgelehnt statt geschrieben**, mit Nennung
des Typs, der sie erlauben würde:

> og_type "article" does not keep "og_product_brand" (needs og_type "product").
> This is refused rather than written because the Backend widget discards
> properties outside the current type the next time the record is saved …

Typ und Eigenschaften lassen sich in **einem** Aufruf setzen — maßgeblich ist
dann der Typ aus diesem Aufruf:

```json
{ "table": "tl_news", "id": 12, "fields": {
    "og_type": "article",
    "og_title": "Neue Halle eröffnet",
    "og_description": "Kurzfassung für soziale Netzwerke",
    "og_article_author": "Redaktion",
    "og_image": "files/og/halle.jpg"
}}
```

Tabellen können den Typ einschränken: `tl_news` akzeptiert nur `article`,
`tl_calendar_events` nur `website`. `og_image` und `twitter_image` nehmen eine
Hex-UUID, eine UUID mit Bindestrichen **oder** einen Dateipfad.

Die Felder sind außerdem über die generischen Wege erreichbar: `page_get`,
`news_get`, `calendar_event_get` und `faq_get` liefern sie mit, die zugehörigen
`*_create`/`*_update` schreiben sie über `extras: {...}` — mit derselben
Typprüfung, weil alle denselben Field-Provider durchlaufen.

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

> In `numero2/contao-deepl` 1.2.0 hat `DEEPL_API_KEY` einen leeren Default: Ohne
> Schlüssel läuft die Installation weiter, und die `deepl_*`-Tools antworten
> mit `deepl_not_configured`. Ältere Fassungen setzten die Variable ohne
> Fallback — dort lässt ein fehlender Wert schon `cache:clear` mit
> *„Environment variable not found"* abbrechen.

Danach erscheinen vier Tools. Fehlt eines von beidem, antworten sie mit
`extension_not_available` bzw. `deepl_not_configured` und sagen, was fehlt —
`deepl_status` beantwortet das direkt, inklusive der Liste der Zielsprachen.

| Tool | Wofür |
|---|---|
| `deepl_status` | Verfügbarkeit, Zielsprachen, Glossar-Lage, optional der Kontostand |
| `deepl_translate` | Freitext rein, Übersetzung raus — rührt keinen Datensatz an |
| `deepl_translate_records` | Ein oder mehrere Datensätze **einer** Tabelle |
| `deepl_translate_page_tree` | Seite + Meta + Artikel + Inhalte + alle Unterseiten |

### Glossare

Ab `numero2/contao-deepl` **1.2.0** lassen sich DeepL-Glossare konfigurieren —
und sie greifen **auch über MCP**. Bis dahin galt das nur für den Backend-Knopf:
Wer über die Tools übersetzte, bekam die eigene Terminologie nicht. Konfiguriert
wird weiterhin nur an einer Stelle:

```yaml
contao:
    deepl:
        source_lang: de
        glossaries:
            de-en: "a1b2c3d4-…"   # Glossar-ID aus der DeepL-Weboberfläche
```

Die Regeln für das Paar sind dieselben wie im Backend, bewusst bis ins Detail:
Regionale Varianten fallen weg (`en-US` und `en-GB` nutzen beide das
`de-en`-Glossar), das Paar wird ohne Rücksicht auf Groß-/Kleinschreibung
verglichen, und ein Paar aus derselben Sprache bekommt kein Glossar. Ein
Unterschied bleibt: Der Backend-Knopf leitet die Quellsprache aus der
Fallback-Sprache der Website ab, MCP nimmt sie nur aus dem Aufruf
(`source_lang`) oder der Konfiguration.

**Ohne Quellsprache kein Glossar.** DeepL braucht das Paar; fehlt
`source_lang` und gibt der Aufruf auch keine mit, wird ohne Glossar übersetzt.
`deepl_status` meldet genau das unter `glossary` — einschließlich des Falls
„Glossare konfiguriert, aber `source_lang` leer", der sonst aussieht wie ein
funktionierendes Setup.

Der Übersetzungs-Cache unterscheidet Glossar- von glossarloser Übersetzung. Ein
nachträglich konfiguriertes Glossar liefert also sofort neue Ergebnisse, statt
alte aus dem Cache zu wiederholen.

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

Zusätzlich bremst `max_characters` (Default 250 000, `0` schaltet ab) **vor**
dem ersten API-Aufruf, wenn der Plan teurer wäre als erlaubt. Mehr als 1000
Datensätze nimmt ein Aufruf nicht an.

### Was ein Aufruf kostet

Jede Antwort führt mit, was sie verbraucht hat:

```json
"usage": { "characters_submitted": 482, "characters_reused": 16, "api_requests": 2 }
```

`characters_submitted` ist die Zahl, auf die DeepL abrechnet — tatsächlich
gesendete Quellzeichen. Übersetzungen werden 30 Tage zwischengespeichert
(eigener Cache, nach Zielsprache, Quellsprache, Tag-Handling **und** Glossar
geschlüsselt), deshalb kostet die empfohlene Reihenfolge *planen → ansehen →
speichern* nur einmal. Der Kontozähler aus `deepl_status` ist eine
Abrechnungsperioden-Summe und läuft der Realität hinterher — er ist **nicht** der
Preis des letzten Aufrufs.

### Der übliche Weg zu einem zweiten Sprachbaum

Übersetzt wird **an Ort und Stelle**: Der Datensatz, den man nennt, ist der
Datensatz, der sich ändert. Für eine zweite Sprache also erst kopieren, dann die
Kopie übersetzen:

1. `entity_duplicate(table: "tl_page", id: 42, into_pid: <Ziel-Root>, with_children: true, overrides: {"published": false})`
2. `deepl_translate_page_tree(id: <die Kopie>, target_lang: "EN-GB", dry_run: true)` — was kostet das?
3. dasselbe mit `save: true`
4. `entity_language_link(...)` für die Verknüpfung mit changelanguage

Schritt 1 kopiert in einen **live** geschalteten Baum, wenn es ihn schon gibt —
deshalb das `published: false`: sonst steht der noch unübersetzte Quelltext für
die Dauer der Übersetzung öffentlich im Netz. Der zurückgegebene `tree` ist die
komplette Quell→Ziel-ID-Karte, die man in Schritt 2 ohnehin braucht.

Dasselbe gilt außerhalb des Seitenbaums. `entity_duplicate` deckt ab:

```
tl_page, tl_article, tl_content,
tl_module, tl_layout,
tl_news_archive, tl_news,
tl_calendar, tl_calendar_events,
tl_faq_category, tl_faq,
tl_form, tl_form_field
```

Das ist der Weg für alles, was man sonst Spalte für Spalte in ein
`*_create` tippen müsste — eine `tl_module`-Zeile hat je nach Erweiterungen
114 bis über 250 Spalten. Eine **Sammlung** zu kopieren nimmt über die
`ctable`-Kaskade alle Einträge mit: `entity_duplicate(table:
"tl_news_archive", id: 1)` legt das Archiv samt seiner 95 Meldungen und deren
Inhaltselementen an. Für einen Sprach-Rollout ist genau das der Sinn, aber
`copied` nennt die Gesamtzahl — vorher einkalkulieren.

Kopiert wird wie beim Kopieren-Knopf im Backend: `doNotCopy`-Felder werden nicht
übernommen, sondern aus dem DCA-`default` gefüllt (eine kopierte Meldung ist
deshalb heute datiert, nicht 1970), der Alias wird aus dem richtigen Feld neu
erzeugt (`headline` bei News, `question` bei FAQs) und folgt einem `overrides`,
das die Kopie umbenennt. Name und Titel macht das Werkzeug **nicht** eindeutig —
dafür ist `overrides` da.

`overrides` ist kein Rohzugang: `id`, `pid` und `ptable` sind gesperrt (der
Elternteil ist `into_pid`/`into_ptable`, dort wird er auch geprüft), und die
Feldrechte des Kontos gelten wie auf den `*_update`-Tools. Auf `tl_content`,
`tl_module` und `tl_form_field` nehmen Overrides genau die Felder, die das
`*_update`-Tool der Tabelle für den Typ der Kopie nimmt, auf `tl_content` auch
für ihren **neuen** Elternteil. `rsce_data` wird dabei in die Einstellungen der
Quelle gemergt. So entsteht aus einem vorbereiteten RSCE-Element eine Kopie mit
anderer Button-URL.

`tl_user` und `tl_member` sind bewusst nicht dabei: Contaos Kopieren-Knopf
landet dort in der Bearbeitungsmaske, damit ein Mensch Benutzername und E-Mail
eindeutig macht, bevor gespeichert wird.

#### Beide Hälften einer Übersetzungsbeziehung

`terminal42/contao-changelanguage` hinterlegt eine Übersetzung an **zwei**
Stellen, und ohne die zweite wird die erste nicht ausgewertet:

| Ebene | Spalte | Tabellen |
|---|---|---|
| Datensatz | `languageMain` | `tl_page`, `tl_article`, `tl_news`, `tl_calendar_events`, `tl_faq` |
| Sammlung | `master` | `tl_news_archive`, `tl_calendar`, `tl_faq_category` |

Fehlt `master`, fällt der Sprachwechsler auf die Sprachwurzel zurück und der
`hreflang`-Alternate wird nicht ausgegeben — sichtbar wird das erst am
gerenderten Frontend, die Datenbank sieht korrekt aus.

`entity_language_link` deckt beide Ebenen ab und vervollständigt die
Sammlungshälfte selbst, wo das eindeutig und zulässig ist:

```
entity_language_link(table: "tl_news", default_id: 8, translations: {"en": 16})
→ linked: 1
  collections_linked: [{table: "tl_news_archive", id: 3, master: 1}]
  warnings: []
```

Geht das nicht — weil das Zielarchiv schon eine andere Übersetzung ist, oder
weil auf derselben Leserseite bereits eine Sammlung denselben Master
beansprucht —, steht in `warnings`, was fehlt und mit welchem Aufruf es zu
setzen ist. Die Sammlungen lassen sich auch direkt verknüpfen:
`entity_language_link(table: "tl_news_archive", default_id: 1, translations: {"en": 3})`.

Root-Seiten verknüpfen über `languageRoot` statt `languageMain` und werden hier
abgelehnt; dafür ist `page_update` zuständig.

**Aliase werden bewusst nicht übersetzt.** DeepL liefert Fließtext, kein Slug;
„Unsere Leistungen" gehört nicht in eine URL. Für übersetzte URLs erst den Titel
übersetzen und danach einen **leeren** Alias an `page_update` schicken — Contao
erzeugt ihn dann über den Slug-Service aus dem neuen Titel neu.

## RockSolid Custom Elements (RSCE)

Ein RSCE-Element speichert seine ganze Einstellung (Raster, Button-URL,
Hintergrund) in **einer** JSON-Spalte, `rsce_data`. RSCE meldet jedes Element als
Inhaltselement, Frontend-Modul und Formularfeld an, sofern die Config `types`
nicht einschränkt, und führt die Spalte in `tl_content`, `tl_module` und
`tl_form_field`. Palette und Felder baut RSCE erst in der Bearbeitungsmaske auf.
Mit installiertem `madeyourday/contao-rocksolid-custom-elements` schreiben
`content_create`, `content_update`, `content_create_tree`, `module_create`,
`module_update`, `form_field_create`, `form_field_update` und die Overrides von
`entity_duplicate` diese Spalte trotzdem auf allen `rsce_*`-Typen:

```
content_update(id: 812, fields: {"rsce_data": {"buttonUrl": "{{link_url::12}}", "bgColor": null}})
module_create(theme_id: 1, type: "rsce_teaser", name: "Teaser Startseite", fields: {"rsce_data": {"grid": "grid3Col"}})
```

- **Gemergt, nicht ersetzt.** Ein gesendeter Schlüssel wird ersetzt, `null`
  entfernt einen, alles Nicht-Genannte bleibt. Eine Liste (`inputType: list`) wird
  als Ganzes ersetzt.
- **Geprüft.** Einen Schlüssel, den der Typ laut `rsce_*_config.php` nicht hat,
  lehnt das Tool ab und nennt dabei die vorhandenen. Ebenso einen Wert, den ein
  Select-, Radio- oder Checkbox-Feld mit festen Optionen nicht anbietet, wie im
  Backend. Was schon gespeichert ist, geht auch dann durch, wenn die Config es
  nicht mehr kennt. Optionen aus `options_callback` oder `foreignKey` entstehen
  erst beim Bearbeiten und werden nicht geprüft.
- **Gespeichert wie vom Backend.** Werte-Listen werden serialisiert, Dateien als
  UUID abgelegt (die Hex-Form aus `content_get` wird umgerechnet),
  `true`/`false` wird zu `"1"`/`""`, Datumsfelder werden zum Timestamp (ISO 8601
  wird umgerechnet).

`content_palette_get`, `module_palette_get` und `form_field_palette_get` listen
für einen `rsce_*`-Typ unter `rsce_data` jeden Schlüssel mit Eingabetyp, Optionen
und erwartetem Wertformat. In `fields` stehen die regulären Spalten, die die
Bearbeitungsmaske des Typs in dieser Tabelle zeigt: beim Inhaltselement etwa
`headline`, Bild und `customTpl`, beim Modul Name, `headline` und `customTpl`,
beim Formularfeld `text`, CSS-Klasse und `customTpl`. Die Config liest RSCE
selbst, Theme-Ordner und Twig-Templates werden also aufgelöst wie im Backend.

## Wie Tools Fehler melden

Ein Tool, das nicht tun kann, was es soll, gibt ein strukturiertes Ergebnis
zurück statt einer Ausnahme — mit `error`, einer `message` im Klartext und,
wo es hilft, der Liste des Erlaubten. Vier Fälle, die man kennen sollte:

**Ein Feld, das der Datensatztyp nicht hat**, wird abgelehnt und nennt den Typ:

```
Field "gibtsNicht" is not valid for content type "text".
Use content_palette_get("text") to see allowed fields. Currently allowed: pid, ptable, …
```

**Ein Feld, das vom Elternelement kommt**, gilt nur dort. Contao legt
`sectionHeadline` (den Titel eines Akkordeon-Abschnitts) nur Elementen **in**
einem Akkordeon in die Palette. `content_palette_get` nennt solche Felder unter
`context_fields`, und außerhalb sagt die Ablehnung, wo das Feld gilt:

```
Field "sectionHeadline" only exists on an element inside an element of type "accordion" — …
```

**Ein Parameter, den das Tool nicht hat**, ebenso — mit Vorschlag bei einem
Tippfehler (seit 1.10.0; davor wurde er stillschweigend verworfen und der
Aufruf meldete Erfolg, ohne etwas zu ändern):

```
Tool "page_update" has no parameter "pageTitel" (did you mean "pageTitle"?).
Nothing was changed. Allowed parameters: id, pid, title, type, sorting, …
```

**Ein Fehler, den das Tool nicht erklären kann** — meist aus der Datenbank —,
kommt ohne Rohmeldung (seit 1.32.0): derselbe `error`-Code (etwa `save_failed`),
eine `message` mit `reference <hex>` und, wo vorhanden, der `sqlstate` (`23000`
Constraint, `22007` Wert passt nicht zur Spalte). Die Details stehen unter
dieser Referenz im Contao-Log.

Das alles gilt für direkte `tools/call` **und** für den `contao_call`-Proxy im
Lazy-Mode.

## Bekannte Einschränkungen

- **Ein MCP-Zugang mit dem Recht `tpl_editor` ist ein Zugang zur
  Codeausführung.** Ein `.html5`-Template ist reines PHP, das Contao beim
  Rendern ausführt; wer Templates schreiben darf, kann `fe_page.html5`
  überschreiben. Das ist keine Lücke des Bundles, sondern dieselbe Grenze wie
  im Backend — dort gilt genau dasselbe für denselben Benutzer. Der Unterschied
  ist die Reichweite: Ein Backend-Benutzer klickt selbst, ein Agent kann durch
  Text, den er irgendwo gelesen hat, dazu gebracht werden. **Gebt `tpl_editor`
  nur Benutzern, deren Token ihr auch für einen Deploy hergeben würdet.**
  Dasselbe gilt für die Layout-Felder `head`, `script` und `onload`: Sie landen
  wörtlich auf jeder Seite des Layouts. Unter Contao 6 rendert Contao keine
  `.html5`-Templates mehr; die Codeausführung auf dem Server fällt dort weg, ein
  Twig-Override kann aber weiterhin Skript in jede Seite schreiben.
- **Testabdeckung:** PHPUnit deckt ab, was ohne Datenbank prüfbar ist; den
  Tool-Layer prüft der Smoke-Test end-to-end, in der CI gegen Contao 5.3, 5.7
  und 6.0 (auch ohne die optionalen Bundles). Backend-Seiten, das Speichern der
  Konfiguration und den Endpunkt prüft ein eigener CI-Schritt über HTTP.
- **Replay-Erkennung für Refresh-Tokens** (seit 1.30.0) wirkt nur, solange die
  rotierte Zeile existiert. `contao:mcp:oauth:cleanup` löscht widerrufene
  Refresh-Tokens sofort; ein danach vorgelegtes Token wird abgewiesen, beendet
  aber nicht mehr die ganze Sitzung.
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

Das Bundle persistiert fünf separate Daten-Surfaces. Ein vollständiger
Restore braucht alle fünf — sonst bleiben OAuth-Tokens ungültig (Keys weg),
die Lizenz lässt sich nicht erneuern (Lizenzdatei weg) oder Tool-Calls können
keine externen Referenzen zuordnen (External-IDs weg).

| Surface | Pfad | Restore-Verhalten |
|---|---|---|
| OAuth-RSA-Keys + Encryption-Key | `var/mcp/oauth/*.pem`, `var/mcp/oauth/encryption.key` | Pflicht. Fehlt → alle Refresh-Tokens ungültig, alle Access-Tokens müssen neu ausgestellt werden. Private Schlüssel und `encryption.key` gehören auf `0600` (`public.pem` darf `0644`); `system_health_check` meldet Abweichungen. |
| Lizenz | `var/mcp/license.json` | Pflicht. Token und Instanz-Geheimnis, Rechte `0600`. Fehlt → Tools gesperrt, und eine erneute Aktivierung derselben Domain scheitert an `instance_mismatch`, bis Netzhirsch die Bindung löst. |
| Bundle-Config | `var/mcp/config.json` | Pflicht. Fehlt → `/mcp` antwortet 503, bis die Konfiguration wieder gespeichert ist; `backend_url`, Tool-Auswahl, CIMD und Lazy-Mode sind neu zu setzen. |
| OAuth-Tabellen | `tl_mcp_oauth_client`, `tl_mcp_oauth_access_token`, `tl_mcp_oauth_refresh_token`, `tl_mcp_oauth_authcode`, `tl_mcp_oauth_iat` | Pflicht für nahtlose Migration. Fehlt → Clients müssen sich neu registrieren (DCR); CIMD-Clients wie Claude melden sich nur neu an. |
| External-ID-Spalten | `external_id_namespace` + `external_id_key` auf 24 Entity-Tabellen | Pflicht für Skill-2-Integrationen. Fehlt → Updates müssen via Contao-PK statt externer Referenz erfolgen, schmerzhafte Doppel-Pflege. |

Empfehlung: `tar -p` über `var/mcp/` (ohne `uploads/`) + mysqldump auf die fünf
`tl_mcp_oauth_*`-Tabellen + ein DB-Dump des kompletten Contao-Schemas
(External-ID-Spalten leben auf Entity-Tabellen, kein eigener Backup-
Container möglich).

## Entwicklung: Prüfkette vor einem Release

```bash
composer verify
```

Bündelt PHPStan + PHPUnit — den schnellen Teil der CI. Die CI prüft
zusätzlich `composer.json` und die PHP-Syntax, fährt `composer audit`, den
Smoke-Test gegen Contao 5.3, 5.7 und 6.0 (auch ohne die optionalen Bundles)
samt HTTP-Prüfung der Backend-Module und des Endpunkts, ein Update vom letzten
Release und eine Installation mit dem alten Patch-Block. Einmal pro Klon
`composer setup-hooks` ausführen: Danach lehnt ein `pre-push`-Hook einen Push
ab, an dem PHPStan oder PHPUnit scheitert (`git push --no-verify` umgeht ihn im
Notfall).

Der **Smoke-Test** braucht ein laufendes Contao samt Datenbank und ist deshalb
nicht Teil von `composer verify`. Die CI fährt ihn bei jedem Lauf; lokal gehört
er trotzdem vor jeden Release-Tag:

```bash
vendor/bin/contao-console contao:mcp:smoke-test --env=dev
```

Reihenfolge für einen Release: `composer verify` → Smoke-Test → committen →
pushen → **CI grün abwarten** → erst dann taggen.

Getaggt wird lokal (`git tag -a vX.Y.Z -m "vX.Y.Z: …"`) oder über den Workflow
**Tag** (Actions → Tag → Run workflow: Version, Kurztext, optional der Commit).
Er setzt nur den annotierten Tag, kein GitHub-Release, und bricht ab, wenn der
Commit nicht auf master liegt, der Tag schon existiert oder das CHANGELOG an
diesem Commit keinen Abschnitt für die Version hat.

## Sicherheitslücken melden

Bitte **nicht** über ein öffentliches Issue, sondern über die
[Security-Policy](SECURITY.md) (GitHub Security Advisory oder
<kalus@netzhirsch.de>).

## Bug-Reports

Issues / Findings bitte ins Repo, plus Anhang:

- Output von `system_health_check`
- Backend-User-Rolle + Contao-Version
- Relevante Einträge aus dem Contao-Log — `var/logs/prod-<Datum>.log` unter
  Contao 5, `var/log/prod-<Datum>.log` unter Contao 6; bei einer Meldung mit
  `reference …` die Zeile mit dieser Referenz

## Update von einer Version ≤ 1.4.0

**Nichts zu tun** — `composer update netzhirsch/contao-mcp-bundle` läuft durch,
auch wenn in der Root-`composer.json` noch der frühere Patch-Block steht. Die
`patches/`-Dateien liegen dafür bis 2.0.0 weiter im Paket. Wo der alte Block
noch steht, wendet `cweagans/composer-patches` sie weiter an — wirkungslos, weil
`ContaoDispatcher` die gepatchten Methoden überschreibt.

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

Contao Manager und `composer update` leeren dabei den Cache. Wer ohne die
Composer-Skripte deployt, führt danach `vendor/bin/contao-console cache:clear`
aus — Contao merkt sich die Template-Hierarchie, und nach dem Update auf 1.37.1
antworteten die Backend-Seiten unter „MCP-Server" sonst mit Fehler 500. Eigene
Overrides der früheren `be_mcp_*.html5`-Templates greifen unter Contao 6 nicht
mehr; die Anpassung gehört in `be_mcp_*.html.twig`.

Das Bundle braucht **keine Vendor-Patches** mehr: was es am Dispatcher
braucht (Lazy-Mode-Filter, Post-Call-Cleanup), liegt in
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
| `contao:mcp:oauth:cleanup` | löscht abgelaufene (älter als 24 h) und alle widerrufenen Auth-Codes und Refresh-Tokens sowie abgelaufene Access-Tokens und IATs | täglich, als eigener System-Cron |
| `contao:mcp:oauth:rotate-keys` | rotiert die RSA-Signing-Keys, sobald sie älter als 90 Tage sind (`--max-age`; `--force` sofort), den Vorgänger nach 30 Tagen weg (`--prune-old`) — Dual-Key, ohne Ausloggen | monatlich, als eigener System-Cron |
| `contao:mcp:permission-debug` | nachvollziehen, warum ein Backend-User ein Tool (nicht) darf | zur Fehlersuche |
| `contao:mcp:smoke-test` | End-to-End-Selbsttest des Tool-Layers; überspringt, was fehlende optionale Bundles braucht, und stellt `config.json` für einzelne Prüfungen kurz um (siehe [Smoke-Test](#smoke-test)) | nach Updates/Serverumzug, auf Staging |

Der Contao-Cron muss laufen (`contao:cron` bzw. der Web-Cron) — an ihm hängt
die automatische Lizenzverlängerung (stündlich). Cleanup und Key-Rotation laufen
**nicht** darüber, sie brauchen einen eigenen Cron-Eintrag.

---
*Maintainer: Jan-Philipp Kalus &lt;kalus@netzhirsch.de&gt; — Netzhirsch*
