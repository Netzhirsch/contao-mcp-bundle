# Dokumentation: Contao MCP Server

Dies ist die Funktionsreferenz zum Bundle `netzhirsch/contao-mcp-bundle`. Wie ein MCP-Client (Claude Web, Claude Desktop, MCP Inspector, …) angebunden wird, beschreibt die separate [Installationsanleitung](./installation.md).

Der **Contao-MCP-Server** stellt Tools für Read- und Write-Operationen auf Contao-Entitäten (News, Pages, Articles, Calendar, FAQ, URL-Rewrites, …) über das [Model Context Protocol](https://modelcontextprotocol.io/) bereit. KI-Clients wie Claude Desktop oder ChatGPT-Connector können sich verbinden und damit das CMS bedienen.

## 1. Wie der Server läuft

Apache/nginx leitet jeden `POST /mcp`-Request an PHP-FPM weiter, ein Symfony-Controller im Bundle bedient ihn wie eine normale Contao-Route. Kein langlaufender Prozess, keine Port-Öffnung, keine Reverse-Proxy-Config. Funktioniert auf Shared-Hosting (Plesk, cPanel etc.) ohne Sonderrechte.

Endpoint:

```
https://<backend-url>/mcp
```

Voraussetzungen: deine Apache-/nginx-Vhost-Config muss `/mcp` an PHP-FPM weiterleiten (was sie bei Contao-Standard-Setups automatisch macht). Die `backend_url`-Option (Menüpunkt **MCP-Server → Konfiguration**) muss auf die öffentliche URL deines Backends zeigen — sie wird in der OAuth-Discovery-Metadata veröffentlicht.

## 2. Templates anpassen

Contao kennt zwei Template-Welten:

- **Originale** liegen in `vendor/<bundle>/contao/templates/…` und sind _read-only_ — sie werden bei `composer update` überschrieben.
- **Overrides** liegen im Projekt unter `templates/`. Contaos Template-Loader bevorzugt diese vor den Originalen.

Beide Formate sind unterstützt: `.html5` (PHP-Legacy) und `.html.twig` (modernes Twig, Contao 5+). Subfolders sind erlaubt und folgen der Twig-Konvention (z.B. `templates/content_element/text.html.twig`). Pfade in den Tools sind **relativ zu `templates/`**.

- `templates_list(prefix?)` — Übersicht aller verfügbaren Template-Namen (Bundle-Originale + Project-Overrides, dedupliziert), gruppiert nach Präfix (`ce_`, `mod_`, …).
- `template_overrides_list(prefix?)` — listet nur die Files, die tatsächlich unter `templates/` liegen (mit Pfad, Größe, mtime).
- `template_get(path, source?)` — Inhalt eines Templates lesen. `source`: `"auto"` (Standard) liest erst den Override und fällt sonst auf das Original zurück; `"override"` nur Override; `"original"` nur Bundle-Source.
- `template_create(path, content? | copy_from?, overwrite?)` — neuen Override anlegen. Entweder `content` direkt mitgeben oder `copy_from` auf einen Bundle-Template-Pfad setzen (z.B. `news_full.html5`) — Tool kopiert den Original-Inhalt als Startpunkt. Parent-Subfolders werden automatisch angelegt.
- `template_update(path, content)` — Inhalt eines Overrides ersetzen. Geht nur für existierende Files; für neue → `template_create`.
- `template_rename(path, new_path)` — Override umbenennen oder in einen anderen Subfolder verschieben. Zielpfad muss noch nicht existieren.
- `template_delete(path)` — Override entfernen. Das Bundle-Original bleibt unangetastet, Contao fällt automatisch wieder darauf zurück. Leere Subfolder werden mit aufgeräumt.
- `template_lookup(identifier, theme?)` — listet die komplette Inheritance-Chain für eine Template (Bundle → Theme → Project), mit `active`-Flag für den Layer, der tatsächlich gerendert wird. Powered by Contaos `ContaoFilesystemLoader` — selbe Hierarchie wie das Template-Studio-Backend.
- `template_dependencies(path)` — parst eine `.html.twig`-Datei und liefert ihre Abhängigkeiten (`extends`, `includes`, `embeds`, `uses`, `imports`). Nützlich um vor einer Änderung zu wissen, welche anderen Templates mit reinspielen.

### Template Studio-Integration (Contao 5.6+)

- **Twig-Lint vor Save:** `template_create` und `template_update` parsen `.html.twig`-Content über Twigs `tokenize()` + `parse()` bevor die Datei geschrieben wird. Bei Syntax-Fehler kommt `{error: "twig_syntax_error", message, line, source_excerpt}` zurück — mit ±2 Zeilen Code-Excerpt und `→`-Cursor auf der kaputten Zeile. So speichert der LLM nie kaputte Templates.
- **Component-Templates** (Twig-Partials mit `_`-Prefix wie `_card.html.twig`) werden in der `template_overrides_list` mit `is_component: true` markiert. Sie werden nicht direkt gerendert, sondern via `{% include %}` / `{% embed %}` eingebunden.
- **Theme-Overrides** liegen unter `templates/<theme-slug>/…`. `template_overrides_list` reportet pro Eintrag `theme: ?string` und akzeptiert einen `theme`-Filter (leerer String = nur nicht-themed Overrides). `template_create` nimmt einen optionalen `theme`-Parameter und legt den Override unter `templates/<theme>/<path>` an.

**Sicherheit:** Path-Traversal-Schutz (`..`, NUL, absolute Pfade abgelehnt), Extension-Whitelist auf `.html5` + `.html.twig`, alle Schreib-Operationen werden auf den `templates/`-Tree beschränkt.

## 3. Dateien hochladen und verwalten

Für `tl_files` + das `files/`-Verzeichnis gibt es 9 Tools. Pfade sind **immer relativ zum Upload-Ordner** — der LLM gibt `content/foo.jpg` an, nicht `files/content/foo.jpg`:

- `files_list(path = "", limit, offset, type)` — listet Verzeichnis-Inhalte. `path=""` ist die Wurzel, `type` filtert auf `"files"`, `"folders"` oder `"all"`.
- `file_get(path, include_content)` — Metadaten zur Datei. Mit `include_content=true` kommt zusätzlich `content_base64` zurück, aber nur wenn die Datei **kleiner als 1 MB** ist. Sonst gibt's `content_omitted: {reason: "too_large", …}`.
- `file_upload(parent_path, name, content_base64, overwrite, meta)` — legt eine neue Datei an. Der Inhalt muss als Base64-String übergeben werden (MCP-JSON-RPC kennt keine Binär-Streams). Validierung gegen `tl_settings.uploadTypes` und `tl_settings.maxFileSize`. Standard: keine Überschreibung — `overwrite=true` zum Ersetzen. `meta` ist optional: `{de: {title, alt, caption, link}, en: {...}}`.
- `file_update_meta(path, meta)` — ersetzt die i18n-Metadaten einer Datei.
- `file_rename(path, new_name)` — benennt im selben Ordner um.
- `file_move(path, new_parent_path)` — verschiebt in einen anderen Ordner.
- `file_delete(path)` — entfernt eine Datei vom Disk + aus `tl_files`.
- `folder_create(parent_path, name)` — legt einen neuen Ordner an.
- `folder_delete(path, force)` — löscht einen Ordner. Sicher per Default: `force=true` nötig wenn er nicht leer ist (kaskadiert).

**Sicherheit:**

- _Path-Traversal-Schutz:_ `..`, `NUL`-Bytes, absolute Pfade, Windows-Laufwerksbuchstaben werden abgelehnt. `realpath`-Check stellt sicher, dass alle Operationen im `files/`-Tree bleiben.
- _Extension-Whitelist:_ Uploads gegen `tl_settings.uploadTypes` validiert (sichtbar in _System → Einstellungen → Uploads_). Falsche Extensions kommen mit einer Liste der erlaubten zurück, damit der LLM weiß was geht.
- _Größenlimit:_ `tl_settings.maxFileSize` wird bei Uploads geprüft. `file_get` hat ein zusätzliches eigenes Limit von 1 MB für base64-Content.
- _DBAFS-Sync:_ Jede Schreib-Operation aktualisiert `tl_files` automatisch via `Contao\Dbafs`. Filesystem und Datenbank bleiben konsistent.

## 4. Theme-Welt: Themen, Layouts, Module, Bildgrößen

Vier Tool-Familien für das Frontend-Design — Pendant zu Contao Backend „Layout"-Modul. Die Entitäten hängen alle an einem `tl_theme`:

### Themes (`tl_theme`)

Wurzel der gesamten Design-Welt. Ein Theme bündelt Layouts, Frontend-Module und Bildgrößen.

- `themes_list`, `theme_get(id)` — Übersicht / Details (mit Counts der Kinder).
- `theme_create(name, author, fields?)` — neues Theme. `fields` akzeptiert optional `templates` (Template-Folder-Pfad), `folders` (Liste von Pfaden relativ zu `files/`), `screenshot` (Pfad zu einem Bild).
- `theme_update(id, fields)` — einzelne Spalten ändern.
- `theme_delete(id, force=false)` — safe-by-default: weigert sich bei vorhandenen Layouts/Modulen/Bildgrößen. Mit `force=true` kaskadiert es durch (löscht ALLES).

### Seitenlayouts (`tl_layout`)

Templates für Frontend-Seiten — Header/Main/Footer-Aufbau, CSS-Framework, externe Scripts, eingebundene Module pro Section.

- `layouts_list(theme_id?)`, `layout_get(id)` — Übersicht / Detail (inkl. `page_references`: wie viele `tl_page`-Rows nutzen das Layout via `.layout` / `.subpageLayout`).
- `layout_create(theme_id, name, fields?)` — neues Layout. `fields` ist ein JSON-Objekt mit allen ~30 `tl_layout`-Spalten:
  - `rows` (`1rw`|`2rwh`|`2rwf`|`3rw`), `cols` (`1cl`|`2cll`|`2clr`|`3cl`)
  - `framework`: Liste aus `layout.css`, `responsive.css`, `grid.css`, `reset.css`, `form.css`, `icons.css`
  - `external`, `external_js`: Listen von Datei-Pfaden (relativ zu `files/`) — werden intern auf UUIDs gemappt
  - `modules`: Liste von `{mod: <tl_module.id>, col: "main"|"header"|…, enable?: bool}`
  - `sections`: Liste von `{id, title, position?, template?, cssID?}` für custom Sections
  - `jquery`, `mootools`, `analytics`, `scripts`: Listen von Template-Namen
  - `head`, `script`: freier HTML/JS-Code im `<head>` bzw. vor `</body>`
  - Plus alle Standard-Flags: `combine_scripts`, `minify_markup`, `add_jquery`, `add_mootools`, `static`, `viewport`, `title_tag`, `css_class`, `onload`, `width`, `align`, `lightbox_size`, `default_image_densities`
- `layout_update(id, fields)` — Spalten ändern. `fields` ist ein JSON-Objekt (nicht eine Liste!). Beispiel: `{"external": ["files/layout/app.scss"]}`.
- `layout_delete(id, force=false)` — refuses wenn Pages noch referenzieren; mit `force=true` werden die `tl_page.layout` / `.subpageLayout` Werte auf 0 zurückgesetzt (fallen dann auf inherited layout).

### Frontend-Module (`tl_module`)

~30 verschiedene Modul-Typen (Navigation, NewsList, EventList, Login, FAQ, …) mit dynamischer Palette pro Type — analog zu Inhaltselementen.

- `module_types_list` — alle verfügbaren Modul-Typen, gruppiert nach Kategorie (`navigationMenu`, `user`, `news`, `calendar`, `faq`, …). Output kommt direkt aus `$GLOBALS['FE_MOD']`, also auch Bundle-Beiträge.
- `module_palette_get(type)` — welche Felder akzeptiert der Modul-Type? Resolves live gegen das tl_module-DCA-Palette + Sub-Paletten.
- `modules_list(theme_id?, type?)`, `module_get(id)` — Übersicht / Detail. `module_get` reportet zusätzlich `layout_references`: wie viele Layouts binden das Modul ein.
- `module_create(theme_id, type, name, fields?)` — Bag-Style: alle Type-spezifischen Felder im `fields`-Objekt. Beispiel für eine Navigation: `module_create(theme_id=1, type="navigation", name="Hauptnavi", fields={pages: [3, 7, 11], showLevel: 2})`
- `module_update(id, fields)` — Felder ändern. `type` innerhalb von `fields` wechselt den Modul-Type (Palette des neuen Types wird zur Validierung genommen).
- `module_delete(id)` — löscht das Modul. **Achtung:** Layouts, die das Modul in ihrer `modules`-Liste haben, werden NICHT auto-bereinigt — der return-Wert meldet `stale_layout_references` mit Anzahl, das Aufräumen via `layout_update` ist dein Job.

### Bildgrößen (`tl_image_size` + `tl_image_size_item`)

Responsive-Image-Definitionen für Contaos Picreator. Eine Bildgröße kann mehrere Items für unterschiedliche Media-Queries haben (Mobile/Desktop-Crops).

- `image_sizes_list(theme_id?)`, `image_size_get(id)` — Übersicht / Detail. `get` liefert auch `item_count`.
- `image_size_create(theme_id, name, fields?)` — neue Größe. `fields` akzeptiert `width`, `height` (int), `resize_mode` (`proportional`|`box`|`crop`), `zoom` (0-100), `image_quality` (0-100), `css_class`, `densities`, `sizes`, `formats` (Liste wie `["jpg:webp,jpg"]`), `preserve_metadata` (`default`|`overwrite`|`delete`), `skip_if_dimensions_match`, `lazy_loading` (bool).
- `image_size_update(id, fields)`, `image_size_delete(id, force=false)` — Update / Delete. Delete kaskadiert bei `force=true` auf alle Items.
- `image_size_items_list(image_size_id)`, `image_size_item_get/create/update/delete` — CRUD auf Sub-Items. `media` ist eine CSS-Media-Query-String (z.B. `"(max-width: 600px)"`), `active` ist die positive Form von `invisible`.
- `image_size_options_list` — Discovery-Helper: returnt die kompletten `size`-Werte, die du in Content-Elementen / Layouts verwenden kannst (built-in Aliases + custom Sizes mit Theme-Hint).

**Typischer Workflow für ein komplettes Theme**: `theme_create` → `image_size_create` (mehrfach) → `module_create` (Navigation, Header, Footer-Module) → `layout_create` mit verweisen auf die Module-IDs → `page_update(layout=<new id>)` auf der Root-Seite.

## 4a. Mitglieder, Formulare, Newsletter, Kommentare, Leads

Weitere Tool-Familien für nutzerbezogene und marketing-nahe Inhalte:

### Mitglieder (`tl_member`) + Mitglieder-Gruppen (`tl_member_group`)

- `members_list` (mit Suche + Gruppen-Filter), `member_get` — Übersicht / Detail. Passwort, Secret, 2FA-State werden NIE im Response zurückgegeben.
- `member_create` — Plichtfelder im `fields`-Object: `username`, `email`, `firstname`, `lastname`, `password` (Klartext, intern via `password_hash(PASSWORD_DEFAULT)` gehasht). Optional: `login` (Default false), `active` (Default true, Inverse von `disable`), Profil-Daten, Adresse, Kontakte, `groups` (Liste von Member-Group-IDs), `home_dir` (Pfad in `files/`), `start`/`stop`.
- `member_update` — Passwort weglassen = bleibt unverändert. `active=false` deaktiviert den Account, `login=false` macht ihn komplett nicht einloggbar.
- `member_delete` — hart löschen. Für Soft-Disable lieber `member_update({active:false})`.
- `member_groups_list/get/create/update/delete` — analoges Pattern. Beim Delete wird zwar die Gruppe weg, aber stale References in `tl_member.groups`/`tl_page.groups` werden NICHT auto-bereinigt (Counts werden im Response gemeldet).

### Formulare (`tl_form`) + Formularfelder (`tl_form_field`)

- `forms_list` (mit Title-Suche), `form_get`, `form_create`, `form_update`, `form_delete` (cascadet mit `force=true` auf Fields). Form-Container hat festes Schema: Method (POST/GET), Format (raw/xml), Recipient, Subject, custom Template.
- `form_fields_list`, `form_field_get/create/update/delete` — analoge CRUD-Tools.
- `form_field_types_list` + `form_field_palette_get(type)` — Discovery der ~14 Field-Types (text, textarea, select, radio, checkbox, captcha, submit, html, hidden, password, fieldsetStart, fieldsetStop, explanation, …) und ihrer dynamischen Paletten. Pattern wie tl_module/tl_content.
- `form_field_create` mit Bag-Style: `(form_id, type)` + `fields: {name, label, mandatory, …}`. Auto-Sorting (max+128).

### Newsletter (optional, `contao/newsletter-bundle`)

Tools sind immer in der Liste; bei fehlendem Bundle returnen sie `extension_not_available`.

- Channel: `newsletter_channels_list`, `newsletter_channel_get/create/update/delete` (Delete kaskadiert mit `force=true` auf Mailings + Subscribers).
- Einzelner Versand: `newsletters_list` (Filter: channel, sent), `newsletter_get/create/update/delete`. Wichtig: **es gibt KEIN `newsletter_send`** — Mail-Versand bleibt aus Sicherheitsgründen außerhalb des LLM-Zugriffs, im Backend-Modul.
- Empfänger: `newsletter_recipients_list` (Filter: active), `newsletter_recipient_create` (Subscribe), `newsletter_recipient_update(id, active)` (Pause/Resume), `newsletter_recipient_delete` (Unsubscribe).

### Kommentare (optional, `contao/comments-bundle`)

Vollständiges CRUD inkl. `comment_create`. **Achtung Spam-Risiko**: bei `auth_mode=none` auf einem öffentlich erreichbaren Server kann ein böswilliger MCP-Client massenhaft Kommentare erzeugen. Empfohlene Posture: OAuth + Default `published=false` beim Erstellen (Moderation vor Veröffentlichung).

- `comments_list(source?, parent?, unpublished_only?)` — typisch `comments_list(source="tl_news", parent=42)` für alle Kommentare einer News. `unpublished_only=true` = Moderations-Queue.
- `comment_get` — voller Payload inkl. Reply-Feld.
- `comment_create(source, parent, name, email, comment, fields?)` — Default `published=false`. Fields: `website`, `member` (tl_member.id), `date`, `ip`, `add_reply`+`reply_author`+`reply`.
- `comment_update` — typischer Use-Case: Moderation via `{published: true}`. Auch Reply-Felder ergänzbar.
- `comment_delete` — hart löschen. Für Soft-Hide besser `{published: false}`.

### Leads / Formular-Einsendungen (optional, `terminal42/contao-leads`)

**Nur lesend.** Leads sind echte Formular-Einsendungen aus dem Frontend — sie
werden vom Leads-Bundle erfasst und sind auch im Contao-Backend nicht editierbar
(`tl_lead` ist `notEditable` + `closed`). Es gibt daher bewusst **kein**
create/update/delete; eine Einsendung „schreiben" ergäbe fachlich keinen Sinn.
Tools sind immer in der Liste; bei fehlendem Bundle returnen sie
`extension_not_available`.

- `leads_list` — Einsendungen aus `tl_lead`, neueste zuerst. Optionale Filter:
  `form_id` (das eingereichte Formular), `member_id` (0 = anonym), `language`.
  Jeder Eintrag trägt den aufgelösten `form_title` (Join auf `tl_form`) sowie
  `main_id` (das „Master"-Formular, das den Lead-Store besitzt — gleich
  `form_id`, außer mehrere Formulare laufen in einen gemeinsamen Store).
- `lead_get(id)` — eine einzelne Einsendung inkl. der aufgelösten Feldwerte aus
  `tl_lead_data` als `data: [{field_id, name, label, value}]` (in Formular-
  Reihenfolge). Der rohe POST-Blob (`post_data`) wird **nicht** ausgegeben —
  die normalisierten Feldzeilen sind die saubere Repräsentation; `has_post_data`
  zeigt nur an, ob ein Roh-Blob existiert.

Rechte: gated über das Backend-Modul `lead` (BE_MOD-Gruppe „leads") — wer die
Leads im Backend sehen darf, darf sie auch per MCP lesen (Admins immer).

## 4b. Wartung (entspricht `/contao?do=maintenance`)

Zwei Tools für die „Daten löschen"-Aktionen, die normalerweise im Backend unter _System → Wartung_ liegen:

- `maintenance_jobs_list` — listet alle verfügbaren Wartungs-Jobs gruppiert nach `tables` / `folders` / `custom`, mit aktuellen Counts (Tabellen-Zeilen, Datei-Anzahl pro Ordner) und einem `destructive: bool`-Flag pro Eintrag. Außerdem die Liste `destructive_jobs`, die `maintenance_run` erzwingt.
- `maintenance_run(jobs[], confirm_destructive=false)` — führt einen oder mehrere Jobs aus, atomar. Pro Job kommt `{id, group, success, before, after, duration_ms}` zurück.

### Verfügbare Job-IDs

| Job-ID | Gruppe | Was passiert | destruktiv? |
|---|---|---|---|
| `log` | tables | Leert `tl_log` — System-Log weg. | ja |
| `versions` | tables | Leert `tl_version` — komplette Backend-Versionshistorie weg. | ja |
| `undo` | tables | Leert `tl_undo` — Backend-User können nichts mehr zurückrollen. | ja |
| `index` | tables | Leert die Such-Index-Tabellen (`tl_search`, …) — vom nächsten Crawl neu gebaut. | ja |
| `crawl_queue` | tables | Leert die Crawl-Queue. | ja |
| `images` | folders | Image-Cache (resized JPGs/WebPs) leeren — werden on-demand neu erzeugt. | nein |
| `previews` | folders | Preview-Cache leeren. | nein |
| `scripts` | folders | `assets/js` + `assets/css` leeren. | nein |
| `temp` | folders | `system/tmp` leeren. | nein |
| `pages` | custom | HTTP-Page-Cache invalidieren. | nein |
| `xml` | custom | Sitemap-XML-Dateien neu generieren. | nein |
| `symlinks` | custom | Project-Symlinks neu erzeugen (unter Windows oft Permission-Errors — Job returnt dann `success: false`). | nein |

### Destructive-Schutz

Wenn **einer** der Jobs in der Liste destruktiv ist und `confirm_destructive` nicht `true` gesetzt ist, lehnt `maintenance_run` die **komplette** Anfrage ab — keiner der Jobs läuft. Response:

```json
{
  "error": "destructive_confirmation_required",
  "message": "These jobs would wipe data that is hard to recover: versions. Re-call with confirm_destructive=true to proceed.",
  "destructive": ["versions"]
}
```

Re-issue mit `confirm_destructive: true` für tatsächliche Ausführung.

### Beispiel: Cache-Aufräumung

```jsonc
maintenance_run(jobs: ["images","previews","scripts","temp","pages"])
// → { success: true, duration_ms: 412,
//     jobs: [
//       { id: "images",   success: true, before: {files_per_folder: {...}}, after: {...} },
//       { id: "previews", success: true, ... },
//       …
//     ] }
```

## 4c. Site-Building-Helfer

Fünf Tools für die Arbeit an einer ganzen Site — Settings ändern, Insert-Tags finden, Reihenfolge ändern, Frontend-URLs bauen, Page-Cache invalidieren:

### Settings + Insert-Tags

- `system_settings_update(settings, confirm_dangerous?)` — schreibt globale tl_settings (z.B. `{websiteTitle: "Acme GmbH", adminEmail: "info@acme.test", dateFormat: "d.m.Y"}`). Whitelist sicherer Keys (siehe System-Tool-Doku). Gefährliche Keys wie `encryptionKey` / `rootPasswordHash` erzwingen `confirm_dangerous=true`. Setzt sowohl persistent (`system/config/dcaconfig.php`) als auch im laufenden Process (`$GLOBALS['TL_CONFIG']`), damit `Config::get()` sofort den neuen Wert sieht.
- `insert_tags_list` — Discovery aller Contao Insert-Tags (`{{date::d.m.Y}}`, `{{page::title}}`, `{{news_url::42}}`, …) mit Beispielen, gruppiert nach Kategorie. Hilft dem LLM, sinnvolle dynamische Inhalte zu schreiben.

### Sortieren + Verschieben

- `entity_move(table, id, position, target_id?, into_pid?, into_ptable?)` — sortiert **und/oder** hängt eine Zeile um (Contaos Drag-Sort + Cut/Paste in einem). **Tables:** tl_content, tl_article, tl_news, tl_calendar_events, tl_faq, tl_form_field, tl_module, tl_image_size_item, tl_page.
  - **Sortieren** (ohne `into_pid`): `position` = `"before"`/`"after"` (mit `target_id`), `"first"`, `"last"`. `sorting` wird automatisch berechnet; bei gesättigtem Sort-Space (Diff < 2) werden die Siblings vorher aufs 128-Step-Grid normalisiert.
  - **Verschieben/Umhängen** (mit `into_pid`): hängt die Zeile unter ein **neues Elternteil** und positioniert sie dort (`target_id` ist dann ein Sibling **am Ziel**). `tl_content` → neue Artikel-/Element-ID (+ `into_ptable`, Default = aktuelles ptable; `"tl_content"` zum Verschachteln in einen Container); `tl_article` → Ziel-Seiten-ID; `tl_page` → neue Eltern-Seiten-ID (`0` = Root).
  - Re-Parent validiert die Existenz des Ziels, **lehnt Zyklen ab** (Seite unter eigene Unterseite, Element unter sich selbst/Nachfahren) und erzwingt **Rechte-Parität** (Quelle editierbar + Ziel beschreibbar, gleiche Voter/Pagemount-Scope wie die CRUD-Tools). DBAL-Transaktion mit Row-Locks, kein Versions-Snapshot (Sort/Move ist in Contao auch nicht versioniert).

### Duplizieren

- `entity_duplicate(table, id, into_pid?, into_ptable?, with_children?, overrides?)` — dupliziert einen Datensatz **mit seinem Kind-Baum**, das MCP-Äquivalent zum „Kopieren"-Knopf im Backend. **Tables:** `tl_page`, `tl_article`, `tl_content`. DCA-getrieben, spiegelt Contaos eigenes Kopierverhalten:
  - **Kaskade automatisch** (DCA `ctable`): Artikel → Inhaltselemente (inkl. verschachtelte), Container-CE → Kinder, Seite → Artikel (+ deren Inhalt).
  - `with_children=true` (nur `tl_page`) kopiert zusätzlich den ganzen **Unterseiten-Baum**.
  - `into_pid`/`into_ptable`: neues Elternziel (Default: gleiche Eltern → Kopie an Ort und Stelle).
  - `overrides`: Felder, die auf der **obersten** Kopie gesetzt werden (z. B. `{"title": "…"}`).
  - **Wie der Backend-Copy:** `doNotCopy`-Felder werden nicht übernommen, der **Alias wird neu generiert** (eindeutig, via Slug), die **External-ID-Spalten auf NULL** zurückgesetzt, die Kopie ans Sortier-Ende gehängt. Eine duplizierte **Root-Seite** bekommt `fallback`+`dns` geleert (Eindeutigkeit) — danach via `page_update` setzen.
  - **Rechte-Parität:** Quelle lesbar **und** Ziel beschreibbar (gleiche Voter/Pagemount-Scope wie die CRUD-Tools). Versions-Snapshot + tl_log für den primären Datensatz.

### Frontend-URL + Preview

- `page_url(page_id, absolute=true)` — baut die Frontend-URL einer tl_page-Zeile via Contaos `ContentUrlGenerator` (mit Language-Prefix, urlSuffix, dns aus der Root-Page).
- `page_preview(page_id, excerpt_only=false)` — fetcht das gerenderte HTML der Frontend-Seite (Loopback gegen die eigene Site, 10s Timeout, max 64 KB Body). Mit `excerpt_only=true` kommt nur eine Zusammenfassung `{title, h1, meta_description, body_text}` zurück — gut für Validierung „rendert die Seite wie erwartet?" ohne kompletten HTML-Markup in den LLM-Context zu schieben. **Achtung:** Aufruf ist unauthenticated — Login-geschützte Bereiche zeigen die Anonymen-Variante.

### Page-Cache

- `page_cache_invalidate(page_ids?, paths?)` — invalidiert den HTTP-Reverse-Proxy-Cache. **Per Page-ID** (Tag-basiert via `contao.db.tl_page.<id>`), **per URL-Path** oder **global** (ohne Argumente). In Maintenance-Group einsortiert weil cache-related, aber gezielt pro Page möglich.

### External-ID-Mapping (Idempotenz für Automation-Pipelines)

Wenn ein externer Builder (Skill 2, Sync-Job, Manifest-Importer) dieselbe Logik mehrfach auf dieselbe Contao-Instanz anwendet, braucht er stabile Handles, damit aus dem zweiten Lauf kein zweites Set Rows wird. Dafür hat jede unterstützte Entity-Tabelle zwei Zusatzspalten:

- `external_id_namespace` (VARCHAR 64) — Partition pro Caller (z.B. `skill2-builder`, `manifest-acme`).
- `external_id_key` (VARCHAR 255) — sprechende stabile ID aus dem Caller-Manifest (z.B. `kunde-mueller:el.hero.intro`).

UNIQUE-Index pro Tabelle auf `(external_id_namespace, external_id_key)`. Beide Spalten NULL bedeutet „nicht von Automation verwaltet" — Backend-Operator sieht im Editor-Modus die Spalten unter dem Aufklapp-Bereich _„Externe ID (Automation/Pipelines)"_ und kann sie bei Bedarf manuell korrigieren.

Vier Tools:

- `external_id_set(namespace, external_key, table, row_id)` — bindet die ID an die angegebene Row. Idempotent bei gleicher Row + gleichem Key. **Kein Re-bind:** wenn die Row bereits eine andere Mapping hat oder der Key an einer anderen Row hängt, gibt's einen `mapping_conflict`- bzw. `row_already_mapped`-Fehler. Aufrufer muss dann explizit `external_id_unset` aufrufen.
- `external_id_lookup(namespace, external_key, table)` — löst Key zu `row_id` auf. Cheap, indexed.
- `external_id_unset(namespace, external_key, table)` — löscht die Mapping (setzt beide Spalten NULL). Kein `row_id` nötig, der Key selbst identifiziert die Row.
- `external_ids_list(namespace?, table?)` — listet Mappings. Ohne Args: nur Discovery (`supported_tables`-Metadaten). Mit Namespace: UNION über alle unterstützten Tabellen.

**Cascade-Verhalten:** Wenn die Contao-Row gelöscht wird (Backend, Tool, CLI), verschwindet das Mapping mit ihr — die Spalten leben in der Row selbst. Kein eigenes Cleanup nötig.

**Unterstützte Tabellen** (Stand v0.2.0): `tl_theme`, `tl_image_size`, `tl_image_size_item`, `tl_layout`, `tl_page`, `tl_article`, `tl_content`, `tl_module`, `tl_files`, `tl_form`, `tl_form_field`, `tl_member`, `tl_member_group`, `tl_news_archive`, `tl_news`, `tl_calendar`, `tl_calendar_events`, `tl_faq_category`, `tl_faq`, `tl_url_rewrite`, `tl_newsletter_channel`, `tl_newsletter`, `tl_newsletter_recipients`, `tl_comments`. Backend-User-Tabellen (`tl_user`, `tl_user_group`) sind bewusst ausgeschlossen.

**Typischer Pipeline-Flow**:

```
existing = external_id_lookup("skill2", "el.hero.intro", "tl_content")
if existing.found:
    content_update(existing.row_id, fields={...})
else:
    new = content_create(pid=..., type="text", fields={...})
    external_id_set("skill2", "el.hero.intro", "tl_content", new.id)
```

## 5. Authentifizierung (OAuth 2.1) — Server-Seite

Der Server kennt zwei Auth-Modi (Menüpunkt **MCP-Server → Konfiguration**):

- **Keine Authentifizierung** — Default. Jeder, der den Endpunkt erreicht, kann alle Tools nutzen. _Nur sicher auf einem nicht öffentlich erreichbaren Host (z.B. `127.0.0.1`)_.
- **OAuth 2.1** (Authorization Code + PKCE) — vollwertiger Flow nach [OAuth 2.1](https://datatracker.ietf.org/doc/html/draft-ietf-oauth-v2-1), RFC 7591 Dynamic Client Registration, RFC 8414 Authorization Server Metadata. Access-Tokens sind JWTs (RS256, RSA-2048-Keypair auto-generiert in `var/mcp/oauth/`), 1h gültig; Refresh-Tokens 30 Tage, mit Rotation. Auth-Code ist 10min gültig.

### Setup-Schritte

1. **Backend-URL setzen** (**MCP-Server → Konfiguration**): die öffentliche Basis-URL des Contao-Backends, z.B. `http://contao-mcp.test` oder `https://www.kunde.de`. Der MCP-Server gibt sie in seiner OAuth-Discovery-Metadata aus.
2. **Auth-Mode auf „OAuth 2.1" umstellen** und speichern.
3. **Fertig — kein Neustart nötig.** Beim ersten OAuth-Request im `oauth`-Mode generiert `KeyManager` automatisch das RSA-Keypair und einen 32-Byte-Encryption-Key.
4. **Client-Registrierung wählen:** entweder „Eingeschränkt" (empfohlen) oder „Offen". Mehr dazu unten.

### Wichtige Sicherheits-Defaults

- **PKCE ist Pflicht** — alle Authorization-Code-Flows erzwingen `code_challenge_method=S256`. Anti-CSRF / Anti-Replay für native Apps.
- **Redirect-URI-Whitelist** bei Client-Registrierung: zulässig sind `https://*`, `http://localhost`, `http://127.0.0.1`, `http://[::1]` und benutzerdefinierte Schemes wie `claude://`, `mcp-inspector://`. Plain `http://example.com` wird für public Clients abgelehnt.
- **CSRF auf der Consent-Seite**: das Approve/Deny-Formular trägt einen Contao-Token, der bei POST validiert wird.
- **Rate Limits** (per Source-IP): `/register` 10/h, `/token` 60/min, `/authorize` 30/min. Bei Überschreitung HTTP 429 + `Retry-After`.
- **Code-Reuse-Detection**: wird ein bereits eingelöster Auth-Code ein zweites Mal verwendet, werden ALLE Access- + Refresh-Tokens dieses Client+User-Paares kaskadiert revokiert (OAuth 2.1 §4.1.2 — angenommen Code-Leak).

### Per-Benutzer-Rechte

Unter `auth_mode=oauth` handelt jeder MCP-Aufruf im Namen des angemeldeten Backend-Benutzers — und mit **genau dessen Backend-Rechten**: Modul-/Tabellen-Zugriff, Seiten-/Datei-Mounts und Feld-Rechte werden über Contaos eigene Security-Voter geprüft. Ein eingeschränkter Redakteur kann über MCP also nichts, was er nicht auch im Backend dürfte. Administratoren dürfen alles.

Zusätzlich muss MCP pro Benutzer freigeschaltet sein: das Feld _„MCP-Server-Zugriff erlauben"_ in `tl_user` bzw. `tl_user_group` (Standard: aus). Nicht-Admins ohne dieses Häkchen (am Benutzer oder einer Gruppe) werden komplett abgewiesen. Admins brauchen es nicht.

### Client-Registrierung — Pairing-Fenster + IAT

Der Registrierungsmodus steht in `var/mcp/config.json` (`oauth_registration_mode`, Standard `restricted`) und hat bewusst kein Formularfeld mehr — neue Clients verbindet man über das Pairing-Fenster, Skripte über IATs. Die zwei Modi:

- **Eingeschränkt (Standard)** — `POST /_mcp_oauth/register` verlangt einen einmaligen _Initial Access Token_ (IAT) als `Authorization: Bearer iat_…`. Der IAT ist 1h gültig, wird genau einmal eingelöst und ist danach gesperrt. Das ist der Weg für **Skripte/Automationen**, die Header senden können — Standard-MCP-Clients (mcp-remote, Claude Desktop) können das bei der Registrierung NICHT.
- **Offen** — entspricht dem RFC-7591-Default: jeder, der die URL erreicht, darf einen Client registrieren. Praktisch fürs schnelle Testen, aber auf öffentlich erreichbaren Servern nicht zu empfehlen.

**Pairing-Fenster (empfohlener Weg für neue Clients):** Der Button _„Registrierung für 10 Minuten öffnen"_ (Menüpunkt **MCP-Server → Status**, Abschnitt „Neuen Client verbinden") lässt im „Eingeschränkt"-Modus vorübergehend eine anonyme Registrierung zu — für maximal 10 Minuten oder genau EINE erfolgreiche Registrierung, je nachdem, was zuerst eintritt. Danach verriegelt sich das Fenster selbst; das frühere manuelle Umschalten auf „Offen" (und das Zurückstellen-Vergessen) entfällt. Sicherheitsnetz: Auch ein im Fenster registrierter Client erhält erst nach Backend-Login + Consent ein Token.

### Client-Anbindung

Wie Claude Desktop, MCP Inspector oder andere Clients sich gegen diesen Server authentifizieren, steht in der separaten [Installationsanleitung](./installation.md). Dort findest du die Custom-Connector-Anleitungen, `claude_desktop_config.json`-Snippets und den OAuth-Roundtrip-Walkthrough. Für die erste Registrierung im „Eingeschränkt"-Modus: Pairing-Fenster öffnen, Client verbinden, fertig.

### OAuth-Verwaltung im Backend

Die OAuth-Verwaltung im Menüpunkt **MCP-Server → Status** zeigt:

- **Initial Access Tokens** — Button „Neues IAT erzeugen" liefert einen `iat_…`-String, der EINMAL als Confirmation-Message angezeigt wird (sofort kopieren — wird nicht erneut angezeigt). Tabelle listet alle IATs mit Status: aktiv / eingelöst / abgelaufen.
- **Registrierte Clients** — Tabelle aller per RFC 7591 registrierten Clients mit Name, _„Autorisiert von"_ (der Backend-Benutzer, der den Consent erteilt hat — wird beim Authorize gespeichert; „—" = registriert, aber noch nie autorisiert), Client-ID, Redirect-URIs und Datum. Der rote _„Widerrufen"_-Button löscht den Client komplett — sämtliche Access- + Refresh-Tokens werden ebenfalls revokiert, betroffene Apps müssen sich neu autorisieren.

### HTTPS-Warnung

Wenn `auth_mode=oauth` mit einer `http://`-Backend-URL läuft, die **kein** Loopback ist (`localhost`, `127.0.0.1`, `[::1]`), erscheint im Backend (Menüpunkt **MCP-Server → Status**) eine rote Warnung: Access-Tokens reisen dann im Klartext über das Netz. In Produktion immer HTTPS verwenden (Apache/nginx mit gültigem Zertifikat).

Der MCP-Server terminiert selbst **kein** TLS — das übernimmt der Webserver bzw. Reverse-Proxy davor (Apache/nginx, Plesk/Let's Encrypt). Die in der OAuth-Discovery beworbenen Endpunkte werden aus der konfigurierten `backend_url` gebaut, _nicht_ aus dem Request-Schema — trage dort also die öffentliche `https://`-URL ein, dann stimmt die Discovery auch hinter einem Reverse-Proxy. (Lokales Dev-HTTPS: siehe [lokales-https.md](./lokales-https.md) im Bundle.)

### Aufräumen

Abgelaufene Auth-Codes, Tokens und IATs sammeln sich in der DB. Der Cleanup-Command räumt alles ab, was länger als 24h abgelaufen oder revokiert ist — am besten täglich via Cron oder Contao-Job:

```bash
php vendor/bin/contao-console contao:mcp:oauth:cleanup --quiet
```

## 6. Lazy-Mode (empfohlen für Claude)

Das Bundle hat ~100 Tools. Claude Desktop / claude.ai laden bei jedem Connect **alle** Tool-Schemas in den System-Prompt — das sind etwa 12 KB pro Turn und führt bei vielen Tools zu schlechterer Tool-Auswahl (Halluzinationen). `tools/list` wird vom Anthropic-Stack nicht paginiert; `nextCursor` ignoriert er.

**Lösung**: Lazy-Mode aktivieren (**MCP-Server → Konfiguration** → Checkbox „Lazy-Mode"). Dann liefert `tools/list` nur noch **sechs** Tools:

- `contao_search_tools(query, group?, limit=20)` — Volltext-Suche durch alle ~100 Tool-Namen + Descriptions. Optional gefiltert nach `group` (`news`, `layout`, `module`, `image_size`, …). Leere Query + Group listet alle Tools dieser Gruppe.
- `contao_describe_tool(name)` — vollständiges JSON-Schema + Description für ein einzelnes Tool. Aufruf vor `contao_call` um zu wissen welche Parameter erwartet werden.
- `contao_call(name, args)` — Proxy-Aufruf für jedes verstecktes Tool. `args` ist ein JSON-Objekt mit den Parametern des Ziel-Tools.
- `ping`, `contao_version`, `installed_bundles` — Health-Probes, die Claude oft direkt braucht.

Die anderen ~100 Tools sind **nicht weg** — sie bleiben über `contao_call` aufrufbar. Der LLM lernt das Pattern im ersten oder zweiten Aufruf und nutzt es flüssig.

**Typische Sequenz** bei „lege eine News an":

```
1.  contao_search_tools(query="create news")
    → [{name:"news_create", group:"news"}, {name:"news_archive_create", …}]

2.  contao_describe_tool(name="news_create")
    → {input_schema: {properties: {pid, headline, alias, ...}, required: [...]}}

3.  contao_call(name="news_create", args={pid: 5, headline: "Test", …})
    → {created: true, id: 42, …}
```

**Wann ausschalten?** Beim Debuggen mit MCP Inspector — der zeigt sonst auch nur die sechs Discovery-Tools. Auch für Scripts, die direkt `tools/call` aufrufen, ist Lazy-Mode irrelevant (der direkte Aufruf eines „versteckten" Tools funktioniert weiterhin).

## 7. Konfiguration

Das Backend (Menüpunkt **MCP-Server → Konfiguration**) speichert die Werte in `var/mcp/config.json`. Der MCP-Controller liest die Datei bei jedem Request — Änderungen greifen sofort (ggf. einmal den Symfony-Cache leeren).

| Feld | Default | Bedeutung |
|---|---|---|
| `path` | `mcp` | URL-Pfad des MCP-Endpunkts ohne führenden Slash. Endgültige Endpunkt-URL: `<backend_url>/path`. Selten zu ändern. |
| `pagination_limit` | `500` | Maximale Tools pro `tools/list`-Seite. Claude paginiert _nicht_, also muss der Wert oberhalb der Tool-Gesamtzahl liegen. **Im Lazy-Mode irrelevant** (dort sind's eh nur 6 Tools). |
| `auth_mode` | `none` | Sicherheits-Toggle. `none` = offener Server, nur sicher auf `127.0.0.1`. `oauth` = OAuth 2.1 mit PKCE (siehe §5). |
| `backend_url` | (leer) | Öffentliche Basis-URL des Contao-Backends, z.B. `https://www.kunde.de`. **Pflicht wenn `auth_mode=oauth`** — der MCP-Server gibt die OAuth-Endpunkte in seiner `/.well-known`-Metadata aus. |
| `oauth_registration_mode` | `restricted` | `restricted` = Dynamic Client Registration verlangt ein Initial Access Token ODER ein aktives Pairing-Fenster. `open` = jeder kann sich registrieren (RFC-7591-Default) — nur für lokale Dev-Setups, im Internet-exponierten Setup immer `restricted`. Bewusst NUR per config.json editierbar (kein Formularfeld); steht der Key auf `open`, warnt der Menüpunkt **MCP-Server → Status**. |
| `lazy_mode` | `false` | Wenn aktiv: `tools/list` exposed nur die 6 Discovery-Tools (siehe §6). Empfohlen für Claude Desktop / claude.ai. Aus für Inspector-Debugging und Scripts mit erwarteter flacher Tool-Liste. |
| `extension_tools_enabled` | `[]` | Allowlist für Tools, die andere Bundles beisteuern (Services mit Tag `netzhirsch_mcp.tool`). Ein Fremd-Tool ist erst aufrufbar, wenn sein `#[McpTool]`-Name in dieser Liste steht — `composer require` allein schaltet nichts frei. Standard leer = nur die mitgelieferten Core-Tools sind aktiv. Core-Namen gewinnen jede Kollision. Komfortabel pflegbar über das Panel „Tools" (Badge EXT). Siehe `EXTENDING.md`. |
| `disabled_tools` | `[]` | Opt-out-Liste für Core-Tools: Jeder Name hier verschwindet vollständig aus dem MCP-Katalog (`tools/list`, Discovery UND `tools/call`) — für alle Clients und Benutzer. Gepflegt über das Panel „Tools" mit Gruppen-Schaltern und Suche; gilt auch für die Tools der unterstützten Fremd-Plugins (url_rewrite, changelanguage). Die Discovery-/System-Tools (`contao_search_tools`, `contao_describe_tool`, `contao_call`, `ping`) sind geschützt und nicht deaktivierbar. Änderungen greifen ab dem nächsten MCP-Request; verbundene Clients sehen sie nach einem Reconnect. |

## 8. Fehlersuche

- _Tools tauchen in Claude nicht auf:_
  - **Lazy-Mode ist aus** + alle Tools sollten sichtbar sein, sind aber nicht da → im Inspector `tools/list` aufrufen, prüfen ob ein `nextCursor` kommt — wenn ja, `pagination_limit` erhöhen.
  - **Lazy-Mode ist an** + Claude sieht nur 6 Tools → das ist Absicht! Claude muss `contao_search_tools` → `contao_describe_tool` → `contao_call` nutzen. Falls Claude das Pattern nicht aufnimmt: einmal Claude Desktop komplett beenden + neu starten (Tools werden initial gecached).
- _Code-Änderungen greifen nicht:_ Symfony-Cache leeren: `vendor/bin/contao-console cache:clear --env=prod`.
- _Backend-User für Write-Operationen:_
  - Bei `auth_mode=oauth` + eingeloggtem Browser-User: Schreib-Operationen werden dem echten `tl_user` als Author zugeordnet. `tl_log.username` erhält das Format `<username> (mcp:<client_name>)`, z.B. `kalus (mcp:Claude Desktop)`. `tl_log.source` ist `mcp_oauth` — filtierbar im Backend-System-Log.
  - Bei `auth_mode=none`: kein User-Kontext bekannt → Fallback auf `default_author_id` aus `config/packages/netzhirsch_contao_mcp.yaml`, sonst niedrigster Admin-User. `tl_log.username`: `<default_user> (mcp)`, `source`: `mcp`.
- _OAuth-Token rejected:_ Wenn Claude / mcp-remote sich nicht mehr verbinden kann und im Log `MCP OAuth auth rejected` erscheint: einmal `~/.mcp-auth/` (im User-Home) löschen, dann Claude Desktop neu starten — das triggert einen frischen OAuth-Flow.
- _Plesk / Shared-Hosting mit Basic-Auth:_ wenn die Domain hinter `.htpasswd` liegt, müssen `/mcp`, `/.well-known/oauth-*` und `/_mcp_oauth/*` davon ausgenommen werden, sonst scheitern MCP-Clients schon am 401 der Basic-Auth statt am OAuth-401. Beispiel (Apache 2.4, in der `.htaccess` mit der Auth-Konfiguration):

  ```apache
  SetEnvIf Request_URI "^/mcp" allow_mcp
  SetEnvIf Request_URI "^/_mcp_oauth/" allow_mcp
  SetEnvIf Request_URI "^/\.well-known/oauth-" allow_mcp

  AuthType Basic
  AuthName "Protected"
  AuthUserFile /pfad/zur/.htpasswd
  <RequireAny>
      Require env allow_mcp
      Require valid-user
  </RequireAny>
  ```
