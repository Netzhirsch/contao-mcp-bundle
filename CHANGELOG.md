# Changelog

Alle nennenswerten Änderungen am `netzhirsch/contao-mcp-bundle`.
Format: [Keep a Changelog](https://keepachangelog.com/de/1.1.0/),
Versionierung nach [SemVer 2.0](https://semver.org/lang/de/).

## [Unreleased]

### Added
- **Übersetzen über DeepL** — vier Tools auf Basis von
  [`numero2/contao-deepl`](https://github.com/numero2/contao-deepl):
  `deepl_status`, `deepl_translate`, `deepl_translate_records` und
  `deepl_translate_page_tree`. Ohne das Bundle bzw. ohne API-Schlüssel
  antworten sie mit `extension_not_available` oder `deepl_not_configured` und
  sagen, welches von beidem fehlt.

  **Warum nicht einfach der Service des Host-Bundles?** Dessen
  `numero2_deepl.api` übersetzt genau einen String pro Aufruf und **ohne**
  `tag_handling`. Contaos Fließtext ist HTML — Teaser, `tl_content.text`,
  FAQ-Antworten —, und DeepL zerlegt Attribute, wenn HTML als Klartext
  ankommt. Außerdem gibt der Service nichts über die Kosten zurück. Übernommen
  wird deshalb seine **Konfiguration**: Der Schlüssel kommt aus
  `contao.deepl.api_key`, dem Parameter, den numero2 aus `DEEPL_API_KEY` setzt.
  Einmal konfigurieren, Backend-Button und MCP nutzen dasselbe.

  Der Button des Host-Bundles war nicht wiederverwendbar: Sein Listener hängt
  sich nur in einen Backend-Request mit `do=` und `act=edit`, und seine
  Sprachauflöser erwarten einen `DataContainer`. Beides existiert über MCP nicht.

  **Zwölf Tabellen** sind übersetzbar: `tl_page`, `tl_article`, `tl_content`,
  `tl_news`, `tl_news_archive`, `tl_calendar_events`, `tl_calendar`, `tl_faq`,
  `tl_faq_category`, `tl_form`, `tl_form_field`, `tl_module`. Welche Spalte
  Fließtext ist, steht in einer **von Hand gepflegten** Liste und wird nicht aus
  der DCA abgeleitet: Ein `inputType: text` sagt nicht, ob dahinter ein Satz
  steht oder ein Maschinenwert wie `alias`, `cssID` oder `customTpl`. Ein
  vergessenes Feld übersetzt man selbst, ein fälschlich aufgenommenes zerlegt
  still die Website.

  Contaos Strukturwerte bleiben erhalten: Eine Überschrift behält ihr `h2`
  (übersetzt wird nur `value`), ein Listen-Element seine Reihenfolge, ein
  Tabellen-Element seinen Zeilenschnitt.

- **Drei Modi über zwei Schalter**, weil „übersetzen", „Geld ausgeben" und
  „Inhalt überschreiben" drei verschiedene Entscheidungen sind:
  `dry_run: true` plant nur — kein API-Aufruf, kein Schreibzugriff, dafür die
  exakte Zeichenzahl des echten Laufs. Beides `false` übersetzt und **gibt
  zurück** (auf 50 Datensätze begrenzt, weil jede Quelle mitkommt).
  `save: true` schreibt über das `*_update`-Tool der jeweiligen Tabelle — mit
  Versions-Snapshot, `tl_log`-Eintrag, `changed_fields` und einer Rechteprüfung
  **pro Datensatz**. Ein Seitenbaum schreibt `tl_page`, `tl_article` und
  `tl_content`; eine Prüfung nur auf `tl_page` hätte die anderen beiden
  mitgenommen.

  `max_characters` (Default 100 000) bricht **vor** dem ersten API-Aufruf ab,
  wenn der Plan teurer wäre.

- **Kosten stehen in jeder Antwort**: `characters_submitted` sind die
  tatsächlich gesendeten Quellzeichen — die Zahl, auf die DeepL abrechnet.
  `characters_reused` ist, was Wiederholungen und der Cache gespart haben.
  Der Kontozähler aus `deepl_status` bleibt bewusst getrennt und ist als
  Perioden-Summe ausgewiesen: Gemessen hat sich der Zähler nach einem
  92-Zeichen-Batch **nicht** bewegt und erst später aufgeholt — als
  Preisschild für den letzten Aufruf ist er unbrauchbar.

- **Eigener Übersetzungs-Cache, 30 Tage.** Nicht der des Host-Bundles: dessen
  Schlüssel ist `md5(text).zielsprache` ohne Tag-Handling, seine Einträge
  verfallen nie — eine HTML-bewusste Übersetzung käme dort später als Antwort
  auf denselben String als Klartext zurück. Unserer schlüsselt über
  Zielsprache, Quellsprache **und** Tag-Handling. Damit kostet die empfohlene
  Reihenfolge *planen → ansehen → speichern* nur einmal Geld, obwohl es drei
  getrennte HTTP-Requests und damit drei PHP-Prozesse sind.

- Ein Seitenbaum geht in **gebündelten** Requests zu DeepL (bis zu 50 Texte
  pro Aufruf), nicht Feld für Feld.

### Fixed
- Beide READMEs beschrieben noch den **IAT-Button**, den es seit 1.6 nicht mehr
  gibt, und nannten `restricted` ein „Initial-Access-Token-Gate" — der Modus
  meint das Pairing-Fenster, der Token-Pfad ist für Skripte. `PairingWordingTest`
  fängt jetzt auch diese beiden Formulierungen ab.
- Die Tool-Zahl in beiden READMEs stand seit mehreren Releases auf 175; es sind
  182.

### Notes
- Übersetzt wird **an Ort und Stelle**. Für einen zweiten Sprachbaum erst
  `entity_duplicate(..., with_children: true)`, dann die Kopie übersetzen.
- **`alias` ist absichtlich nicht übersetzbar.** DeepL liefert Fließtext, kein
  Slug, und die Update-Tools schreiben einen nicht-leeren Alias unverändert —
  „Unsere Leistungen" landete so in einer URL. Der saubere Weg existiert schon:
  Titel übersetzen, danach einen **leeren** Alias an `page_update` schicken,
  Contao erzeugt ihn über den Slug-Service neu.
- Wer `numero2/contao-deepl` installiert, **muss** `DEEPL_API_KEY` setzen: Das
  Bundle deklariert `%env(DEEPL_API_KEY)%` ohne Fallback, ein fehlender Wert
  lässt schon `cache:clear` mit „Environment variable not found" abbrechen.
  Das ist Verhalten des Host-Bundles, hier nur dokumentiert.
- Smoke-Test: 339 Asserts (vorher 319), davon 20 für DeepL. Sechs davon prüfen
  Registrierung und Schema und laufen auch ohne das Host-Bundle — dort, wo es
  fehlt, wird stattdessen `extension_not_available` auf allen drei Tools
  nachgewiesen. PHPUnit: 290 Tests.


## [1.7.0] – 2026-08-20

> **Verhaltensänderung, die eine Automatisierung merken kann:**
> `system_settings_update` **lehnt** acht Schlüssel jetzt **ab**, die es vorher
> mit `success: true` quittiert hat — `gdMaxImgWidth`, `gdMaxImgHeight`,
> `characterSet`, `debugMode`, `displayErrors`, `enableSearch`,
> `fileSyncExclude`, `useFTP`, `maxResizeWidth`. Keiner davon existiert in
> Contao 5; geschrieben haben sie nie etwas. Wer einen davon setzt, bekommt
> statt einer Bestätigung `unknown_settings` mit Nennung des Schlüssels. Das
> ist Absicht: Ein Fehler ist reparierbar, ein stilles Nichtstun nicht.
>
> **Sonst nichts zu tun.** Das Update ist schemafrei — keine Migration, keine
> DCA-Änderung, kein neuer Container-Dienst. In der CI von `v1.6.0`
> hochgezogen und gegengemessen: die aktualisierte Instanz liefert exakt
> dieselben 305 Smoke-Asserts wie eine frisch installierte.

### Added
- **`folder_set_public` — Ordner per MCP öffentlich machen** (Briefing aus AL-02,
  Projekt Autohaus Lau). Schreibt Contaos `.public`-Marker und legt den Symlink
  `public/files/<ordner>` an, also genau das, was der Dateimanager unter
  „Öffentlich machen" tut. Ohne das liefert der Webserver nichts direkt aus —
  Webfonts aus dem kompilierten CSS, eigenes JavaScript, Favicons und
  `site.webmanifest`, unbearbeitete SVGs. Der Aufbau einer Instanz endete
  bisher genau hier mit einem Klick im Backend.

  Der Umweg über `file_upload(name: ".public")` bleibt gesperrt: Dotfiles haben
  auf einem Upload-Kanal nichts verloren, und die Sperre war nie das Problem —
  nur der falsche Weg zum Ziel.

  Schlägt das Anlegen des Symlinks fehl (fehlende Rechte, Windows ohne
  Privileg), wird der Marker trotzdem geschrieben und `symlink_created: false`
  samt Grund in `warnings` zurückgegeben. Ein `contao:symlinks`-Lauf oder das
  nächste Deployment vollendet es dann — ein Abbruch würde den Aufrufer glauben
  lassen, es sei gar nichts passiert.

- **`files_list` und `file_get` melden `public` pro Ordner.** Vorher war der
  Zustand über MCP nicht feststellbar, es gab also weder Soll-Ist-Abgleich noch
  einen wiederholbaren Aufbaulauf.
- **`pages_create_tree` legt einen ganzen Seitenbaum in einem Aufruf an.** Der
  Grund ist gemessen, nicht geschätzt: Jeder einzelne Tool-Call kostet ~160 ms
  Framework-Boot bei ~30 ms echter Arbeit je Seite — 9 von 10 Teilen der
  Serverzeit sind Hochfahren. Ein 25-Seiten-Baum braucht gebündelt 914 ms
  statt 4 800 ms (Faktor 5,3), und aus 25 Modell-Runden wird eine.

  Verschachtelung steckt in der **Struktur**, nicht in einer Referenzsprache:
  Ein Knoten hat `children`, die Eltern-ID ergibt sich aus der Position. Kein
  `$ref`, keine Workflow-Engine im MCP-Server. `sorting` vergibt das Tool je
  Ebene in 128er-Schritten — eine ganze Fehlerklasse weniger für den Aufrufer.

  Fehlerverhalten, weil ein halb gebauter Baum der teure Fall ist: Formfehler
  (fehlender Titel/Typ, unbekanntes Feld) werden für den **ganzen** Baum vor
  dem ersten Schreibvorgang gemeldet — dann entsteht nichts. Scheitert ein
  Knoten zur Laufzeit, wird sein Teilbaum übersprungen und die Geschwister
  laufen weiter; das Ergebnis nennt Pfad, Fehler und Zahl der übersprungenen
  Kinder. Es gibt keine Transaktion und kein Sammel-Undo. Obergrenze 200
  Seiten je Aufruf, weil dieser Transport keine Fortschrittsmeldungen kennt.

### Fixed
- **`system_settings_update` bestätigte Schlüssel, die es in dieser
  Contao-Version nicht gibt.** `gdMaxImgWidth` und Konsorten stammen aus der
  GD-Ära; `Config::persist()` schreibt sie klaglos in die lokale
  Konfiguration, wo sie nie jemand liest. Das Tool meldete `updated` —
  dasselbe falsche Erfolgssignal wie bei `theme_create` in AL-01.

  Nachgemessen an Contao 5.7.11: **14 der 33 gepflegten Schlüssel existieren
  dort nicht**, darunter beide „gefährlichen" (`encryptionKey`,
  `rootPasswordHash`), deren Bestätigungsdialog also Felder schützte, die es
  nicht mehr gibt. Die Liste ist bereinigt — solche Schlüssel werden jetzt
  **abgelehnt** statt bestätigt.

  Der naheliegende Weg, die Liste zur Laufzeit aus Contao abzuleiten, geht
  nicht: Es gibt in Contao 5 keinen vollständigen maschinenlesbaren Katalog.
  Die `tl_settings`-DCA deckt nur die 20 Felder der Backend-Maske ab,
  `default.php` nur eine Teilmenge — `websiteTitle` steht in keinem von
  beiden und ist trotzdem gültig. Und `TL_CONFIG` ist Defaults **plus alles je
  Persistierte**, ein Unsinns-Schlüssel wird darin also „bekannt", sobald ihn
  einmal jemand geschrieben hat. Beide Richtungen falsch. Der Versuch fiel in
  der CI auf: lokal grün, auf einer frischen Instanz wurde `websiteTitle`
  abgelehnt. Die Liste bleibt daher gepflegt, und die Toolbeschreibung sagt
  offen, dass ein zwischen 5.3 und 5.7 entfernter Schlüssel durchrutschen
  kann — mit dem Hinweis, im Zweifel zurückzulesen.

- **Ein Tool mit Schreibverb in der Mitte des Namens wurde als Lesezugriff
  geprüft.** `ToolPermissionMap::inferOperation()` sah nur die Endung, also
  hätte `pages_create_tree` (endet auf `_tree`) einem Nur-Lese-Benutzer
  erlaubt, 200 Seiten anzulegen. Aufgefallen beim Bau des Tools, nicht im
  Betrieb: Von den bestehenden Namen trifft es nur `file_update_meta`, und das
  ist explizit erfasst. Neben dem expliziten Eintrag prüft die Heuristik jetzt
  auf ein Schreibverb an beliebiger Stelle — in dieser Richtung darf sie nicht
  irren.


## [1.6.0] – 2026-08-19

> Abgenommen auf `lau.netzhirsch.de` gegen `netzhirsch/contao-bootstrap-bundle`
> v0.20.0: Theme mit Bootstrap-Feldern angelegt und ausgelesen, partielles
> Update lässt den Modus stehen, fehlerhaftes SCSS wird mit Compiler-Meldung
> (Zeile/Spalte) abgelehnt **ohne zu schreiben**, ungültiger Modus nennt die
> erlaubten Werte, unbekannter Schlüssel fällt nicht mehr stillschweigend
> heraus. Testdatensatz wieder entfernt.

### Added
- **`tl_theme` nimmt Erweiterungsfelder an** (Briefing aus AL-01, Projekt Autohaus
  Lau; alle drei Befunde gegen den Code nachgeprüft). `theme_create`,
  `theme_update` und `theme_get` konsultieren jetzt Field-Provider, so wie es
  `tl_article` schon tat. Ein Bundle wie `netzhirsch/contao-bootstrap-bundle`
  kann damit seine Theme-Spalten über MCP schreib- und lesbar machen, und der
  Aufbau einer Instanz bricht nicht mehr an der Stelle ab, an der die
  Design-Tokens ins Theme müssen.

  **`tl_layout` und `tl_content` gleich mit.** Content war der Sonderfall: Der
  Mapper leitet seine Feldliste aus der DCA-Palette ab und *wirft* bei
  unbekannten Feldern — Erweiterungsspalten stehen aber in keiner Palette und
  wurden deshalb abgewiesen, bevor ihr Provider überhaupt gefragt wurde. Sie
  gelten jetzt als erlaubt. `content_get` und `layout_get` geben die Felder
  zurück, bei Content schlägt die Provider-Darstellung den Rohwert der Spalte.

  Die Mechanik liegt als `Service\ProviderFields` an **einer** Stelle statt pro
  Tabelle: `declaredFor()`, `serialize()`, `apply()`. `tl_layout` und
  `tl_content` können sie mit zwei Aufrufen übernehmen, ohne dass wieder eine
  Sonderlösung entsteht. Provider-`apply()` darf ablehnen — die Meldungen werden
  gesammelt und verhindern das Speichern, sodass etwa fehlerhaftes SCSS als
  Fehler des Tool-Calls ankommt statt als stilles „erfolgreich, Seite ohne
  Stylesheet". Ein Feld, dessen Erweiterung fehlt, nennt die Erweiterung beim
  Namen statt „unbekanntes Feld".

### Fixed
- **`*_create` verwarf unbekannte Feldschlüssel stillschweigend** — bei
  `theme_create`, `form_create`, `image_size_create`, `layout_create` und
  `member_create`. Das jeweilige `*_update` meldete denselben Fall seit jeher als
  `no_mappable_fields`; nur `create` antwortete „created: true" und schrieb
  nichts. Für einen Agenten ist das der schlechteste Ausgang: Er hält den
  Datensatz für konfiguriert und baut darauf auf.

  Alle fünf melden jetzt denselben Fehler. Wo Pflichtfelder mit in die
  Zuordnung wandern (`image_size_create`, `layout_create`) und der
  `applied`-Zähler deshalb nie null werden kann, wird der Mapper selbst gefragt,
  statt eine zweite Feldliste zu pflegen, die auseinanderdriftet.
  **`ignored_keys` liefern jetzt alle fünf**, nicht nur `theme_*`. Möglich wurde
  das, weil die Mapper melden, welche Schlüssel sie tatsächlich verarbeitet
  haben (`applied_keys`) — der Mapper bleibt damit die einzige Wahrheit. Eine
  zweite, handgepflegte Feldliste hätte beim ersten neuen Feld auseinander-
  gedriftet und dann entweder Gültiges abgewiesen oder Unbekanntes
  durchgewinkt. `Service\SubmittedKeys` zieht die Differenz an einer Stelle.

## [1.5.1] – 2026-08-19

### Fixed
- **`page_preview` scheiterte an HTTP-Basic-Auth** (Briefing aus dem
  Bootstrap-Projekt, gegen den echten Code verifiziert). Das Tool holt die Seite
  über ihre **öffentliche** URL — `ContentUrlGenerator` mit `ABSOLUTE_URL`, also
  `dns`/Domain der Root-Seite. Liegt davor ein Basic-Auth-Schutz (typisch auf
  Staging), antwortet der Webserver mit 401, bevor Contao überhaupt läuft, und
  die KI kann nach einem Edit nichts verifizieren.

  Neu: `MCP_PREVIEW_BASIC_AUTH="user:pass"` in der `.env.local` der Instanz
  (Bundle-Parameter `netzhirsch_contao_mcp.preview.basic_auth`). Ist nichts
  gesetzt, geht der Request unverändert raus wie bisher — keine Regression für
  Instanzen ohne Basic Auth. Zugangsdaten landen weder in der Antwort noch im
  Log.

  Dazu ein Hinweis bei 401/403, der den Unterschied benennt: keine Credentials
  konfiguriert vs. konfiguriert und abgelehnt. Ohne den sieht der Aufrufer nur
  einen Statuscode.

  Verifiziert am echten Container: ohne Credentials kein `Authorization`-Header,
  mit Credentials `Authorization: Basic …`, kein Leak in der Tool-Antwort.

- Die Tool-Beschreibung behauptete, `page_preview` rufe „the daemon's OWN site
  (loopback)" auf. Genau deshalb greift der Public-vhost-Schutz überhaupt — der
  Request geht über die öffentliche DNS. Text korrigiert.

- Die Konfig-Tabelle im README nannte `restricted` „IAT-Pflicht" — die sechste
  Stelle derselben Falschaussage. Beide READMEs hängen jetzt mit im
  `PairingWordingTest`.
### Removed
- **Der Knopf „IAT erzeugen" ist weg.** Ein Initial Access Token automatisierte
  nur die *Registrierung*, nie die Autorisierung: Es gibt ausschließlich die
  Grants `authorization_code` und `refresh_token`, und `/authorize` verlangt
  einen eingeloggten Backend-User. Der Knopf sparte also genau einen Klick
  (das Öffnen des Pairing-Fensters) — und zwar nur für Aufrufer, die einen
  HTTP-Header setzen können. Dafür kostete er jeden anderen einen plausibel
  aussehenden Irrweg, wie diese Woche mehrfach in der Praxis.

  Was bleibt: Der Registrierungs-Endpunkt akzeptiert ein noch gültiges Token
  aus dem Altbestand weiter, und die Liste zeigt es bis zum Ablauf. Neue
  werden nicht mehr ausgegeben. Damit verweist auch die Ablehnungsmeldung
  nicht mehr auf das IAT — sie nennt nur noch das Pairing-Fenster, sonst
  zeigte sie auf etwas, das niemand mehr erzeugen kann.

  `PairingWordingTest` hält fest, dass weder Template noch Backend-Modul die
  Erzeugung wieder anbieten.

## [1.5.0] – 2026-08-18

> **Update von ≤ 1.4.0: kein Handlungsbedarf.** `composer update` läuft durch,
> ohne dass an der Root-`composer.json` etwas geändert werden muss. Die
> `patches/`-Dateien bleiben deshalb bis 2.0.0 im Paket liegen — jede
> Installation bis 1.4.0 zeigt mit ihrem `extra.patches`-Block genau auf diese
> Pfade, und ein Wegfall wäre nicht harmlos: `cweagans/composer-patches`
> behandelt einen nicht existierenden lokalen Pfad als URL und stirbt in
> `RemoteFilesystem::copy()` an einem `TypeError`. Ein `TypeError` ist ein
> `\Error`, kein `\Exception` — der `catch`, der sonst „Could not apply patch!
> Skipping." ausgibt und weiterläuft, greift also nicht. `composer install`
> bräche mit Exit 1 ab, ohne auch nur `vendor/autoload.php` zu schreiben.
>
> Der Patch-Block darf jederzeit raus (Anleitung: [`patches/README.md`](patches/README.md)),
> nötig ist er nur nicht mehr. Neuinstallationen sehen von alldem nichts.

### Fixed
- **Ein MCP-Client konnte nicht zuverlässig herausfinden, wo er sich anmelden
  soll.** Der `WWW-Authenticate`-Header einer 401 zeigte mit `resource_metadata`
  auf das RFC-8414-Dokument (Authorization-Server-Metadata) — laut RFC 9728
  gehört dort die **Protected Resource Metadata** hin. Die gab es gar nicht:
  `/.well-known/oauth-protected-resource` lieferte 404. Clients mit Fallback
  kamen durch, strikt spec-konforme nicht — daher scheiterte es *manchmal*.

  Das Dokument wird jetzt ausgeliefert, an beiden Pfaden (`/.well-known/
  oauth-protected-resource` und resource-suffixed `…/mcp`, weil Clients sich
  uneins sind, welchen sie bauen), und der Header zeigt darauf. Nachgemessen
  über echtes HTTP: 401 → `resource_metadata` → PRM (200, mit
  `authorization_servers`) → AS-Metadata (200).

- **Das Pairing-Fenster schloss sich nach der ersten Registrierung.** Damit lief
  jeder Retry, jeder zweite Client und jeder Neuversuch nach einem
  abgebrochenen Authorize-Schritt in eine verschlossene Tür — sichtbar beim
  Nutzer nur als „Verbindung fehlgeschlagen", und das Fenster musste im Backend
  von Hand neu geöffnet werden. Es bleibt jetzt die volle Zeit offen; die Dauer
  steigt von 10 auf 15 Minuten. Die Tür geht weiterhin nur auf, wenn ein Admin
  sie öffnet, und nur kurz.

- **Ein Refresh-Token-Rennen warf den Client in den Browser zurück.** Bei jedem
  `/token`-Austausch wird rotiert: der alte Token wird widerrufen, ein neuer
  ausgestellt. Driften die gespeicherten Stände auseinander — zwei parallele
  Refreshes, eine verlorene Antwort, ein Retry nach Timeout — legt der Client
  einen gerade widerrufenen Token vor, wird abgewiesen und hat nur noch den
  vollen Authorization-Code-Flow. Ein rotierter Token gilt jetzt noch 60
  Sekunden weiter. Ein geleakter Token ist eine Minute später trotzdem wertlos;
  gelöschte oder unbekannte Token bleiben sofort ungültig, sonst würde das
  Widerrufen eines Clients wirkungslos.

### Added
- **Abgelehnte Registrierungen landen im Log.** Bisher wurde nur der Erfolgsfall
  protokolliert — ausgerechnet in der Situation, die Erklärung braucht (ein
  Client klopft an, wird abgewiesen, der Nutzer sieht nur „failed"), stand
  nichts im Log. Jede Ablehnung erscheint jetzt mit Grund und IP unter
  MCP-Server → Aktivität.

### Changed
- **Auch die Ablehnungsmeldung führte mit dem IAT.** Der Text, der im
  Aktivitätslog landet und den der Client zurückbekommt, begann mit „requires
  an Initial Access Token" und nannte das Pairing-Fenster erst als Nachsatz.
  Wer den Log liest, liest den Anfang. Er beginnt jetzt mit der Handlung:
  „Client registration is closed. Open the pairing window in MCP-Server →
  Status …" — das IAT steht am Ende, als Skript-Alternative.
- **Das Backend behauptete, ein IAT sei Pflicht.** Unter „Client-Registrierungs-
  modus" hieß die Auswahl „Eingeschränkt (Initial Access Token erforderlich)",
  und der Hilfetext darunter schickte einen namentlich zum Knopf „Neues IAT
  erzeugen". Beides war schlicht falsch: Derselbe Modus wird genauso vom
  Pairing-Fenster erfüllt, und ein Standard-MCP-Client kann überhaupt kein IAT
  senden. Wer der Oberfläche folgte, erzeugte pflichtbewusst Token, die nichts
  verbinden konnten. Die Aussage steckte in vier Strings gleichzeitig
  (Auswahl-Option, Hilfetext, Status-Zeile, Template-Fallback) — ein Unit-Test
  hält jetzt alle vier zusammen.
- Der IAT-Knopf heißt jetzt „IAT erzeugen (nur für Skripte)". Er stand bisher
  gleichrangig neben dem Pairing-Fenster in derselben Button-Leiste, und im
  Alltag sieht man den Knopf, nicht den Tooltip — entsprechend oft wurde ein
  IAT erzeugt, das keinen einzigen Client verbinden kann.
- Die hartcodierten Fallback-Texte im Backend-Template zogen nicht mit: Ohne
  geladene XLF stand dort weiterhin „10 minutes" und „exactly ONE successful
  registration".
- Das Backend sagt jetzt, welcher Knopf gemeint ist: Für Claude und jeden
  anderen Standard-MCP-Client ist das **Pairing-Fenster** der Weg. Ein Initial
  Access Token kann keiner dieser Clients benutzen — RFC-7591-Registrierung
  lässt keinen `Authorization`-Header zu; IATs bleiben für Skripte.

### Fixed
- **Installation über den Contao Manager war unmöglich.** Das Bundle verlangte
  `cweagans/composer-patches`, um zwei Patches gegen `php-mcp/server`
  anzuwenden. Das ist ein Composer-**Plugin**, und Composer verweigert die
  Ausführung nicht freigegebener Plugins — der Contao Manager kann aber nichts
  zu `allow-plugins` in der Root-`composer.json` hinzufügen. Ergebnis auf einer
  frischen Instanz:

  > cweagans/composer-patches contains a Composer plugin which is blocked by
  > your allow-plugins config.

  `composer install` brach mit Exit-Code 1 ab. Betroffen war damit ausgerechnet
  der Weg, auf dem Contao-Erweiterungen normalerweise installiert werden.

  **Das Bundle wendet keine Patches mehr an** — weg sind die
  Plugin-Abhängigkeit, der `extra.patches`-Block und der
  `allow-plugins`-Eintrag. Installation ist wieder ein nacktes
  `composer require netzhirsch/contao-mcp-bundle`. Die beiden `.patch`-Dateien
  selbst bleiben bis 2.0.0 im Paket, damit Bestandsinstallationen mit ihrem
  alten Root-Block nicht auflaufen (siehe Kasten oben).

  Möglich war das, weil die beiden Patches unterschiedlich viel wert waren:
  - Der **Transport-Patch** hing an `StreamableHttpServerTransport` — dem
    ReactPHP-Daemon, den das Bundle seit der Umstellung auf HTTP-only gar nicht
    mehr benutzt. Er hat toten Code gepatcht.
  - Der **Dispatcher-Patch** (Lazy-Mode-Filter, Post-Call-Cleanup) war echt, ließ
    sich aber ersetzen: `Dispatcher` ist weder final noch geschlossen, also
    erledigt das jetzt `Server\ContaoDispatcher` als Subklasse. Die Factory baut
    sie direkt, statt den intern erzeugten Dispatcher herauszureflektieren — was
    nebenbei den Reflection-Hack für den `ObjectAwareSchemaValidator` überflüssig
    macht, der bisher nachträglich hineingetauscht wurde.

  Verifiziert gegen einen **ungepatchten** Vendor: Lazy-Mode aus → 175 Tools in
  `tools/list`, Lazy-Mode an → genau die sechs Discovery-/Probe-Tools, versteckte
  Tools weiterhin über `contao_call` erreichbar. Smoke-Test 273/273.

### Changed
- README (deutsch und englisch): Installationsabschnitt auf den einen
  Composer-Befehl eingedampft — der Patch-Block, den bisher jede Root-
  `composer.json` von Hand brauchte, entfällt ersatzlos.
- `patches/` liegt weiter im Paket, aber als reine Altlast: die Dateien werden
  von nichts mehr angewendet und verschwinden mit 2.0.0. Sie bleiben nur, damit
  der alte `extra.patches`-Block in Bestandsinstallationen weiter ins Leere
  zeigen darf, ohne Composer abzuschießen. Ein Unit-Test
  (`tests/Unit/Compat/ShippedPatchFilesTest.php`) verhindert, dass sie vorher
  versehentlich verschwinden.
- Smoke-Test deckt jetzt den Dispatcher selbst ab (8 neue Asserts, 281 gesamt).
  Die Tool-Sektionen rufen die Services direkt auf, gingen also nie durch
  `ContaoDispatcher` — der Ersatz für den Patch war damit ausgerechnet an der
  Stelle ungetestet, an der er den Patch ersetzt: Lazy-Mode-Filter in
  `tools/list`, versteckte Tools weiterhin über die Registry auflösbar,
  `tools/call` über den Dispatcher, und der Post-Call-Hook auf **beiden**
  Pfaden — Erfolg wie Exception (das `finally`; ohne das würde eine werfende
  Tool-Ausführung die Identität des vorigen Aufrufers stehen lassen).

## [1.4.0] – 2026-08-18

> **Verhaltensänderung:** `file_rename`, `file_move` und `template_rename`
> können jetzt mit `still_in_use` abgelehnt werden. Wie beim Löschen hebelt
> `ignore_references=true` das aus.

### Added
- **Umbenennen und Verschieben laufen durch denselben Referenz-Check** —
  `file_rename`, `file_move`, `template_rename`. Das war die offensichtliche
  Lücke in 1.3.0: eine Datei zu verschieben bricht ein `{{file::files/x.svg}}`
  genauso wie sie zu löschen.

  Entscheidend ist dabei, **nicht pauschal zu blockieren**. Contao behält beim
  Umbenennen die Zeile, die ID und die UUID und schreibt nur `tl_files.path`
  neu (nachgeprüft gegen `Dbafs::moveResource`: ID und UUID bleiben über
  `file_rename` *und* `file_move` identisch). Also überlebt eine Referenz per
  `singleSRC`/`multiSRC` oder `{{file::<uuid>}}` das Umbenennen, während
  `{{file::files/x.svg}}`, ein SCSS-`@import` und ein hartcodierter Pfad im
  Template brechen.

  Jede gefundene Referenz trägt deshalb jetzt `identity` — `uuid`, `path`,
  `name` oder `id`. Gelöscht wird alles, umbenannt nur der Pfad bzw. der Name:
  - `*_delete` → blockiert bei jeder Identität
  - `file_rename` / `file_move` → blockiert nur bei `path`
  - `template_rename` → blockiert nur, wenn sich der Template-**Name**
    tatsächlich ändert. Ein `.html5` in einen anderen Ordner zu verschieben
    ändert ihn nicht (Contao findet Legacy-Templates über den Basisnamen), ein
    `.html.twig` schon (dessen Name ist der volle Pfad). Ersteres wird daher
    gar nicht erst blockiert.

  Referenzen, die die Operation übersteht, verschwinden nicht — sie stehen in
  `other_findings`, nur eben nicht als Grund für die Ablehnung. Ein Wächter,
  der auch bei harmlosen Operationen anschlägt, wird abgeschaltet.

### Fixed
- Ordner-Referenzen per Insert-Tag wurden übersehen:
  `{{file::files/theme/logo.svg}}` ist auch eine Referenz auf den Ordner
  `files/theme`, denn dessen Löschung oder Umbenennung nimmt den Pfad mit. Das
  Muster erlaubt für Ordner-Ziele jetzt einen `/`-Suffix — ohne dabei auf ein
  Nachbarverzeichnis wie `files/theme2` anzuspringen.

## [1.3.0] – 2026-08-18

> **Verhaltensänderung:** `*_delete`-Aufrufe, die bisher durchliefen, können
> jetzt mit `still_in_use` abgelehnt werden. Das ist der Sinn der Sache — wer
> bewusst trotzdem löschen will, übergibt `ignore_references=true`. Keine
> Signatur ändert sich, bestehende Automatisierungen brauchen aber ggf. dieses
> eine zusätzliche Argument.

### Added
- **`usage_find` — „wo wird das benutzt?" — und ein Löschschutz, der darauf
  aufbaut.** Bisher konnte die KI eine Seite, ein Bild oder ein Modul löschen,
  das anderswo noch verlinkt war; der Schaden fiel erst später auf. Jetzt sucht
  ein Scan an den vier Stellen, an denen Contao überhaupt referenziert:
  1. **DB-Felder** — aus der DCA abgeleitet (`relation`, `foreignKey`,
     `pageTree`/`fileTree`/`imageSize`), also inklusive Extension-Feldern statt
     einer gepflegten Liste. Ergänzt um die zwei Referenzen, die Contao gar
     nicht deklariert: `tl_layout.modules` und `tl_content.cteAlias`.
  2. **Insert-Tags in beliebigen Textspalten** — `{{link::42}}` ebenso wie
     `{{link::mein-alias}}`, `{{insert_module::7}}`, `{{file::<uuid>}}`.
  3. **Datei-Inhalte** (bei `type=file`/`folder`) — `@import`/`@use`/`url()` in
     SCSS/CSS und hartcodierte Pfade in Templates. Deckt damit den Fall ab, den
     keine Datenbankabfrage findet: `_colors.scss` wird als `@import 'colors'`
     eingebunden, ohne Pfad, ohne Endung, ohne Unterstrich.
  4. **Templates** (bei `type=template`) — jede `customTpl`/`template`/`…Tpl`-
     Spalte, die das Template auswählt (also das Content-Element oder Modul,
     das darüber gerendert wird), plus andere Templates, die es per
     `{% extends %}`/`{% include %}`/`{% embed %}`/`{% use %}` oder per
     `$this->extend()`/`$this->insert()` einbinden. Die Namensform hängt an der
     Dateiendung: `.html5` wird unter dem Basisnamen gespeichert
     (`nav_default`), `.html.twig` unter dem vollen Pfad
     (`content_element/text/slider`) — beides gegen echte Datenbankwerte
     verifiziert.

  Derselbe Scan läuft **automatisch vor jedem `*_delete`** — an denselben zwei
  Stellen wie Rechteprüfung und Undo-Snapshot (Controller + `contao_call`), also
  auch für später hinzukommende Delete-Tools. Wer trotzdem löschen will, übergibt
  `ignore_references=true`; das wird in `tl_log` protokolliert.

  Zwei Dinge, die Fehlalarme verhindern und deshalb bewusst so gebaut sind:
  Zeilen, die **die Löschung ohnehin mitnimmt** (die Artikel einer Seite, die
  Dateien eines Ordners), zählen nicht als Referenz — sonst wäre jede Seite
  unlöschbar. Und **Backend-Rechte-Mounts** (`tl_user.pagemounts`, `filemount` …)
  werden berichtet, blockieren aber nicht: Contao ignoriert dort tote IDs, und
  praktisch jede Startseite steckt in irgendeinem Mount. Blockiert wird nur, was
  beweisbar **und** schädlich ist; alles Schwächere landet in `other_findings`.

  Caches und Historie (`tl_search`, `tl_version`, `tl_undo`, `tl_log`) werden
  übersprungen — ein Treffer dort ist die Vergangenheit, nicht die aktuelle
  Nutzung. Die aus der DCA abgeleitete Feldkarte wird gecacht (`cache:clear`
  invalidiert sie), weil ihr Aufbau jede DCA im Projekt lädt: ohne Cache ~3 s,
  mit Cache liegt der eigentliche Scan bei ~0,2 s.

  `usage_find` ist **Admin-only** — es liest quer über alle Tabellen, was sich
  nicht auf eine einzelne DataContainer-Berechtigung abbilden lässt.

  Getestet: 46 neue Unit-Tests (laufen in der CI, ohne Datenbank) über die
  Insert-Tag-Erkennung, die Wert-Verifikation je Kodierung, die Template-
  Namensableitung und den Datei-/Template-Scan; dazu 26 neue Smoke-Test-
  Asserts gegen ein echtes Contao. Zwei Fehler sind dabei aufgefallen und
  behoben worden: eine nicht-fangende Regex-Gruppe, die den kompletten
  Insert-Tag-Scan hat werfen lassen, und Pfadangaben, die absolut statt
  projektrelativ zurückkamen, wenn `kernel.project_dir` ein Symlink oder ein
  Windows-8.3-Kurzname ist.

### Changed
- Der Kaskaden-Walk (welche Zeilen eine Löschung mitnimmt) liegt jetzt in
  `DeletionScope` statt in `UndoRecorder`, weil ihn beide brauchen: der
  Undo-Snapshot und der Löschschutz dürfen sich über den Umfang einer Löschung
  nicht uneinig sein. Dabei ist aufgefallen, dass ein `pid` allein noch keinen
  Baum macht — `tl_files.pid` ist die binäre UUID des Ordners; der Walk prüft
  jetzt, dass die Spalte wirklich ganzzahlig ist.

- **`SECURITY.md`** — privater Meldeweg für Sicherheitslücken (GitHub Security
  Advisory oder E-Mail) statt eines öffentlichen Issues, dazu Reaktionszeiten,
  Geltungsbereich und die bewusst akzeptierten Grenzen (patchbares Gate,
  `auth_mode=none`).
- **`composer verify`** — PHPStan + PHPUnit in einem Kommando, identisch zur CI.
  Dazu `composer setup-hooks`: aktiviert einen **`pre-push`-Hook**, der Pushes
  ablehnt, die die CI rot machen würden. Anlass war 1.2.2 — die Konstruktor-
  Änderung aus 1.2.1 hatte die PHPUnit-Suite gebrochen, weil vor dem Tag nur
  PHPStan und der Smoke-Test liefen. Der Hook delegiert an `composer verify`,
  damit beide nicht auseinanderdriften können.

## [1.2.2] – 2026-08-16

### Fixed
- **PHPUnit-Suite repariert.** Der Sicherheitsfix in 1.2.1 erweiterte den
  Konstruktor von `BackendUserContext` um Contaos `UserChecker` und einen Logger;
  ein Unit-Test instanziiert die Klasse direkt und lief dadurch in einen
  `ArgumentCountError` (4 Fehler, CI rot). Nur Testcode betroffen — die
  ausgelieferte Funktionalität von 1.2.1 war und ist in Ordnung.

## [1.2.1] – 2026-08-14 — Sicherheitsfix

Beide Punkte wurden von außen gemeldet ([#1](https://github.com/Netzhirsch/contao-mcp-bundle/issues/1),
danke an [@zoglo](https://github.com/zoglo)).

### Security
- **Gesperrte Backend-Konten hatten weiterhin MCP-Zugriff.**
  `BackendUserContext::tokenFor()` lud den Benutzer über den User-Provider und
  baute den Security-Token selbst — Contaos **`UserChecker`** lief dabei nie, und
  der ist es, der `disable`, die Login-Erlaubnis und das `start`/`stop`-Zeitfenster
  durchsetzt. Ein Konto zu deaktivieren (der Standardweg beim Offboarding)
  **kappte den MCP-Zugang also nicht**; wer noch ein gültiges OAuth-Token hatte,
  arbeitete mit alten Rechten weiter. Jetzt wird Contaos Checker gefragt, sowohl
  beim Auflösen des Tokens als auch am groben Zugriffs-Gate (sonst blieben Tools
  ohne Datensatz-Recht, etwa Discovery, offen).
  *Regression:* die frühere statische Token-Auth hatte `disable` und Start/Stop
  noch geprüft; beim Umbau auf OAuth ging das verloren.
- **Keine rohen Exception-Meldungen mehr nach außen.** Betroffen waren der
  MCP-Controller (2 Stellen), der `contao_call`-Proxy und — am unangenehmsten —
  der **unauthentifizierte** `/mcp/healthz`, der bei DB-Ausfall die
  DBAL-Meldung samt Host, Datenbank und Benutzer auslieferte. Ab jetzt: volle
  Exception ins Log, nach außen eine generische Meldung mit **Korrelations-ID**,
  die in Antwort und Logzeile steht (Support bleibt möglich).
  Die OAuth-Endpunkte bleiben unverändert — dort werden gezielt
  `OAuthServerException`s gefangen, deren Texte die standardisierten
  OAuth-Fehlerbeschreibungen für Clients sind.

### Tests
- Zwei neue Smoke-Test-Prüfungen mit einem eigens angelegten **gesperrten**
  Benutzer (eigene ID, weil `BackendUserContext` je Request cacht). 237 Asserts.

## [1.2.0] – 2026-08-14 — „KI-Löschungen sind im Backend wiederherstellbar"

### Added
- **Löschungen über MCP landen jetzt in `tl_undo`** — und damit im **normalen
  Contao-Backend unter „Rückgängig"**. Vorher füllte nur Contaos eigener
  `DC_Table::delete()` diese Tabelle; die MCP-Tools löschen über Models/DBAL,
  eine von der KI gelöschte Seite war also **unwiederbringlich** (auch der
  Versions-Snapshot hilft dort nicht: ein Restore ist ein `UPDATE`, und die
  Zeile existiert nicht mehr).
- Der Snapshot umfasst wie im Backend **den Datensatz samt Kind-Datensätzen** —
  DCA-`ctable`-Relationen inklusive `dynamicPtable` (Inhaltselemente) und
  Baumstrukturen (eine gelöschte Seite nimmt ihre Unterseiten mit). Er wird dem
  **handelnden Backend-Benutzer** zugeordnet, weshalb Contaos `UndoVoter` ihn
  genau der Person zeigt, in deren Sitzung gelöscht wurde.

### Notes
- **Bewusst gibt es kein `undo_restore`-Tool.** Wiederherstellen bleibt eine
  menschliche Handlung im Backend: Die KI darf löschen, aber nicht
  stillschweigend wieder herstellen — und Contaos Oberfläche dafür existiert
  bereits, sie war für MCP-Löschungen nur leer.
- Verdrahtet an den **zwei** Stellen, die auch die Rechteprüfung durchsetzen
  (Controller und Lazy-Mode-Proxy `contao_call`), statt in 21 Löschmethoden —
  damit sind auch künftige Lösch-Tools automatisch abgedeckt. Tabelle und ID
  kommen aus derselben Auflösung wie die Rechteprüfung.
- Ein Snapshot wird **wieder verworfen**, wenn das Tool doch nicht löscht
  (fehlendes `confirm_destructive`, Datensatz nicht gefunden, Kaskadensperre,
  Fehler) — sonst stünde im Backend ein Eintrag, der nicht existierende
  Löschungen anbietet.
- Schlägt das Snapshotten fehl, wird es **protokolliert, aber nicht blockiert**:
  Die vom Benutzer angeforderte Aktion läuft weiter (Verhalten wie bisher).

## [1.1.0] – 2026-08-14 — „Volltextsuche über die Website"

### Added
- **Zwei neue Tools auf Contaos Suchindex** (`tl_search`), damit die KI Inhalte
  **findet**, statt Listen durchzublättern:
  - **`search_query`** — Volltextsuche mit derselben Engine wie das
    Frontend-Suchmodul (`Search::query()`): Phrasen in Anführungszeichen,
    `+pflicht`/`-ausschluss`, Wildcards, ODER- und Fuzzy-Suche, Filter auf
    bestimmte Seiten, Paging (Limit auf 50 gedeckelt). Liefert Titel, URL,
    Seiten-ID, Sprache, Relevanz und ein **Snippet rund um den Treffer**.
    Durchsucht den **gerenderten** Seitentext — findet also auch Inhalte aus
    Modulen, Includes und Erweiterungen, die über die CRUD-Tools unsichtbar sind.
  - **`search_index_status`** — Anzahl Dokumente, Aufteilung nach Sprache,
    geschützte Einträge, Zeitpunkt der letzten Indizierung. Beantwortet die
    häufigste Rückfrage bei leeren Treffern: der Index wurde nie gecrawlt.
- **Geschützte Seiten werden nie zurückgegeben.** Ihr Zugriff hängt an
  **Frontend**-Mitgliedergruppen, die nichts darüber aussagen, was der
  aufrufende Backend-Benutzer sehen darf — sie werden herausgefiltert und als
  `protected_skipped` gezählt, damit ein unvollständiges Ergebnis erkennbar ist.
  Rechte-Zuordnung: Lesen des Index = Lesen von `tl_page`.

## [1.0.11] – 2026-08-14

### Fixed
- **„Abo verwalten" erscheint nicht mehr bei internen Lizenzen.** Eine interne
  Lizenz trägt `type: full` und landete damit im selben Zweig wie ein bezahltes
  Abo — sie hat aber gar kein Stripe-Kundenkonto, der Klick konnte also nur in
  einem Fehler enden. Bei aktiver interner Lizenz werden jetzt **gar keine
  Abrechnungs-Buttons** angezeigt (die Statuszeile erklärt den Zustand bereits);
  ist eine interne Lizenz inaktiv, steht nur „Abonnieren" bereit — ein Portal
  ohne Kundenkonto gibt es weiterhin nicht. Ein von Hand aufgerufener
  `manage_billing`-Link meldet jetzt sauber, dass es nichts zu verwalten gibt,
  statt den Serverfehler durchzureichen.

## [1.0.10] – 2026-08-14

### Fixed
- **„Abonnieren" während einer laufenden Testphase** meldete „Lizenz aktiviert",
  statt die Stripe-Bezahlseite zu öffnen: die Vorabprüfung wertete das soeben
  erneuerte **Trial**-Token als bestehendes Entitlement. Sie greift jetzt nur
  noch bei einer **bezahlten oder internen** Lizenz (`type` aus der
  Server-Antwort); während eines Trials führt der Klick wie erwartet zum
  Checkout. Der Schutz vor Doppelbelastung bleibt unverändert.
- **Interne Lizenzen lasen sich wie ein Ablaufdatum.** Angezeigt wurde
  „full — noch 35 Tage" (die Token-Laufzeit), obwohl sie sich unbefristet
  erneuern. Der vom Server gemeldete Plan wird jetzt gespeichert und der Status
  zeigt **„intern — unbefristet (verlängert sich automatisch)"**.

### Changed
- **Feld „Lizenz-Server-URL" aus dem Konfigurationsformular entfernt.** Die
  Produktions-URL steckt seit 1.0.5 fest im Bundle; das Feld konnte nur noch
  Schaden anrichten. Der Config-Schlüssel bleibt als Dev-Override funktionsfähig
  und wird beim Speichern **durchgereicht** statt geleert.

## [1.0.9] – 2026-08-14 — „Lizenz an die Installation binden"

### Security (Lizenz-Integrität)
- **Besitznachweis `instance_secret`.** `/renew`, `/trial` und `/portal-session`
  schicken jetzt ein Instanz-Geheimnis mit; liefert der Server bei der
  Erstaktivierung eines zurück, wird es in `var/mcp/license.json` gespeichert und
  ab dann jedem Aufruf beigelegt (nie geloggt, nie im Backend angezeigt).
  Hintergrund: bisher genügte die **Kenntnis der Domain**, um sich ein gültiges
  Token ausstellen zu lassen — Kundendomains sind öffentlich. Damit war die
  Lizenz umgehbar und über `/portal-session` sogar das fremde Stripe-Portal
  erreichbar. **Die Serverhälfte muss nachgezogen werden**, siehe
  [docs/licensing/server-briefing-instance-binding.md](docs/licensing/server-briefing-instance-binding.md);
  bis dahin ignoriert der Server die Felder folgenlos.
- Neuer Fehlercode **`403 instance_mismatch`** wird sauber behandelt: klare
  Meldung, und es wird **weder** eine Testphase gestartet **noch** ein Checkout
  geöffnet (sonst verbrannter Trial bzw. Doppelbelastung).

### Fixed
- **Rückkehr von Stripe hängt nicht mehr bis zu ~32 s.** Die bis zu drei
  `/renew`-Versuche liefen mit dem Hintergrund-Timeout von 10 s; interaktive
  Aufrufe nutzen jetzt **4 s**. Zusätzlich bricht die Schleife bei endgültigen
  Antworten (`revoked`, `instance_mismatch`) sofort ab, statt dreimal zu warten
  und danach fälschlich „wird aktiviert" zu melden.
- **Backend-Klicks verzögern den Cron nicht mehr.** Ein erzwungener, aber
  fehlgeschlagener `renew` schrieb `last_renew_at` und schob damit die nächste
  echte Cron-Erneuerung um bis zu 6 Stunden. Der Zeitstempel wandert jetzt nur
  noch bei Erfolg oder auf dem Cron-Pfad.

## [1.0.8] – 2026-08-14 — „Abo-/Trial-Buttons aktivieren sich selbst"

### Changed
- **„Abonnieren" und „Testphase starten" holen zuerst eine bereits bestehende
  Lizenz ab** (`POST /renew` per Domain — funktioniert auch ohne gespeicherten
  Token). Hat der Lizenzserver für die Domain schon ein Entitlement (intern
  ausgestellte Lizenz oder bereits bezahltes Abo), wird der Token geholt und
  gespeichert → Tools sofort frei. **Kein Token-Kopieren per Konsole, kein
  Warten auf den stündlichen Cron, und kein Stripe-Checkout für eine Instanz,
  die bereits lizenziert ist** (das hätte doppelt belastet).
- **Widerrufene Lizenz** führt nicht mehr in einen neuen Checkout, sondern
  meldet klar, dass die Lizenz widerrufen wurde.
- **Rückkehr von Stripe** pollt `/renew` bis zu 3× (1 s Abstand) gegen das
  Webhook-Rennen → Kartenzahlungen schalten sofort frei; SEPA (asynchron)
  meldet „wird automatisch aktiviert", der stündliche Cron schließt ab.

### Notes
- Serverseitig ergänzt (Repo `Netzhirsch/license-server`, commit `9a70fbd`):
  `/trial` degradiert eine bestehende Voll-/Internal-Lizenz **nicht mehr** zum
  Trial und verbraucht dafür auch keinen Trial-Versuch.

## [1.0.7] – 2026-08-14

### Changed
- **Paketbeschreibung aktualisiert** (`composer.json`, sichtbar auf Packagist und
  im Contao-Manager-Katalog): „~160 MCP tools" → **„~170"** (tatsächlich 172) und
  Hinweis auf das kommerzielle Modell (30 Tage Testphase, danach Abo je
  Contao-Installation), damit vor der Installation klar ist, was einen erwartet.

## [1.0.6] – 2026-08-13

### Security (BREAKING für Nicht-Admin-Zugriff)
- **Die vier MCP-Server-Backendmodule sind jetzt Administratoren vorbehalten.**
  Bisher waren sie über die normalen `tl_user`/`tl_user_group`-Modulrechte auch
  an Nicht-Admins vergebbar. Wer die **Konfiguration** erhielt, konnte
  `auth_mode` auf `none` stellen und damit die komplette Per-User-Rechteprüfung
  des MCP-Servers in den Trusted-Modus schalten (Rechteausweitung); der
  **Status**-Bereich vergibt zudem OAuth-Registrierungen (IAT/Pairing), widerruft
  Clients und schließt **kostenpflichtige Abos** ab. Der Check sitzt in
  `AbstractMcpModule::compile()` **vor** der Action-Ausführung, prüft `ROLE_ADMIN`
  (nicht die Magic-Property `isAdmin`) und ist pro Modul über
  `requiresAdmin()` überschreibbar.

## [1.0.5] – 2026-08-13 — Pre-Release-Audit (Security + Funktion + Doku)

### Security
- **SQL-Injection in der Rechteprüfung geschlossen (HIGH).** Für die
  `dc_arg`-Tools (`external_id_set/unset`, `entity_move`, `entity_language_link`)
  stammte der Tabellenname aus den **Aufrufargumenten** und wurde in
  `McpPermissionGuard::loadRecord()` unquotiert ins SQL interpoliert; geprüft
  wurde nur `str_starts_with('tl_')`. Da der Permission-Check **vor** der
  Schema-Validierung und vor der Allowlist des Tools läuft, konnte ein
  **authentifizierter Nicht-Admin** mit MCP-Zugang blind beliebige Tabellen
  auslesen (Rechteausweitung). Jetzt strikte Identifier-Prüfung
  (`^tl_[a-z0-9_]+$`) in `ToolPermissionMap::hydrate()` **und** zusätzlich
  `quoteIdentifier()` + erneute Prüfung in `loadRecord()`.
- **`license_server_url` wird validiert** (nur vollständige http(s)-URL mit Host),
  damit der optionale Dev-Override kein beliebiges Request-Ziel werden kann.
- **`/mcp/healthz` gibt keine absoluten Serverpfade mehr aus** (`var/mcp` statt
  vollem Dateisystempfad) — der Endpunkt ist bewusst offen.

### Fixed
- **Auto-Renew lief in CLI/Cron ins Leere, wenn `backend_url` leer ist.** Ohne
  Request gab es keinen Host → `domain: ''` → der Server lehnte jede Erneuerung
  ab und die Lizenz lief still aus — ausgerechnet auf dem für schwach
  frequentierte Sites empfohlenen System-Cron. Jetzt Fallback auf die Domain des
  gespeicherten Tokens (nur CLI; die Domainbindung auf dem HTTP-Pfad bleibt
  unangetastet). `resolveDomain()` akzeptiert zusätzlich einen `backend_url`
  ohne Schema (`kunde.de`).
- **Clock-Skew-Toleranz (300 s)** bei `issued_at`: eine leicht nachgehende
  Server-Uhr führte direkt nach der Aktivierung zu `clock_tampered` — ein Zustand
  ohne Kulanz, also sofortige Totalsperre. Rollback-Schutz bleibt voll wirksam.
- **Abgelaufene bezahlte Lizenz kam nicht mehr ins Stripe-Portal:** nach Ablauf
  der Kulanz zeigte das Backend „Testphase starten" (die der Server mit 409
  ablehnt) statt „Abo verwalten" — genau der Weg, um eine fehlgeschlagene
  Zahlung zu reparieren. Jetzt zustandsabhängig korrekt.
- **Renew-Drosselung greift jetzt auch bei Fehlschlägen:** der Zeitstempel wurde
  nur bei Erfolg gesetzt, wodurch fehlerhafte Installationen den Lizenzserver
  stündlich statt alle 6 h kontaktierten.
- `days_left` wird nicht mehr negativ (Kulanzfenster).
- Der High-Water-Mark wird nur noch bei relevantem Fortschritt (> 1 h)
  geschrieben statt bei praktisch jedem Tool-Call — weniger I/O und ein deutlich
  kleineres Fenster, in dem ein frisch erneuertes Token überschrieben werden kann.

### Docs
- **README auf den Release-Stand gebracht:** Installations-Constraint `^0.8` →
  **`^1.0`** (mit `^0.8` wäre 1.0.x gar nicht installierbar gewesen), neuer
  Abschnitt **„Lizenz & Testphase"** (Testphase, Preise, Bestellen im Backend,
  CLI, Auto-Renew), **`ext-sodium`** als Anforderung ergänzt (auch in
  `composer.json`), Pairing-Stolperfalle bei `restricted`, Tabelle der
  Console-Kommandos, Zahlen korrigiert (24 statt 23 Entity-Tabellen, ~170 Tools),
  überholte Einschränkungen (`/mcp/healthz` existiert längst) und interne
  Verweise entfernt.

## [1.0.4] – 2026-08-13

### Added
- **Stripe-Rückkehr landet auf der MCP-Server-Statusseite mit Meldung.** Nach
  Checkout/Portal leitet ein Backend-Listener (`BillingReturnListener`) die
  Rückkehr-URL `?mcp_billing=success|cancel` (die auf der blanken `/contao`
  landete) auf `?do=netzhirsch_mcp_status` um; das Modul zeigt eine Bestätigungs-
  bzw. Abbruch-Meldung und holt bei `success` **sofort ein frisches Token** (Status
  springt aufs bezahlte Abo, ohne auf den Cron zu warten). Der Parameter wird
  danach aus der URL entfernt.

## [1.0.3] – 2026-08-13

### Fixed
- **Backend-Buttons je Lizenzzustand korrekt.** Im **Trial** stand fälschlich „Abo
  verwalten" → scheiterte mit „No billing account exists for this domain yet"
  (Stripe-Kundenkonto entsteht erst beim Abo). Jetzt: Trial → „Abonnieren"
  (Umwandlung in ein bezahltes Abo); „Abo verwalten" nur bei aktivem, bezahltem
  Abo (`type=full`). „Abo verwalten" hat jetzt außerdem ein Icon (`header_edit_all`
  statt des iconlosen `header_edit`).

## [1.0.2] – 2026-08-13

### Fixed
- **Backend-Lizenzaktionen schickten eine leere `account_email`** → der
  Lizenzserver lehnte „Testphase starten"/„Abonnieren" mit **`HTTP 422
  missing_fields`** ab. Ursache: `property_exists($user, 'email')` ist bei Contaos
  `BackendUser` **immer `false`** (Werte liegen in `arrData`, Zugriff via `__get`).
  Jetzt `instanceof BackendUser` + Magic-Property. Gleiche Ursache mitgefixt:
  IAT-Generierung (User-ID war immer 0) und die Backend-Sprachwahl
  (`resolveLocale` fiel immer auf den Default zurück).

## [1.0.1] – 2026-08-13

### Fixed
- **Bestell-/Trial-Buttons im Backend erscheinen jetzt immer** (MCP-Server →
  Status), nicht mehr nur bei gesetztem `license_server_url`. Da die URL seit
  1.0.0 einbetoniert ist, hätten Kunden mit leerem Feld sonst keine „Testphase
  starten"/„Abonnieren"-Buttons gesehen und nicht bestellen können.

## [1.0.0] – 2026-08-13 — „Kommerzielle GA: eine Edition + Auto-Renew/Revoke + Lizenzserver-Anbindung"

### Changed (Security / Lizenzierung)
- **Nur noch EINE Edition — Enforcement gilt unbedingt.** Der echte Vendor-
  Public-Key ist eingebacken; die frühere „frei vs. kommerziell"-Weiche
  (`LicenseToken::isEnforcementBuild()`, Platzhalter-Key) entfällt. Der
  Unterschied zwischen zahlendem Kunden und Netzhirsch-Instanz liegt allein im
  ausgestellten Token (Server-Plan `internal` statt `full`), nicht im Code. Ein
  einziges öffentliches Paket ist damit Contao-Manager-/Katalog-tauglich, weil das
  Gate ohne gültigen Token ohnehin sperrt.

### Added
- **`src/Cron/LicenseRenewalCron.php`** — Contao-Cron (stündlich) erneuert den
  Token automatisch und zieht Revocations. Gedrosselt auf einen echten
  Server-Call / 6 h. Häufiges Renew ⇒ der Token hat fast immer seine volle
  Laufzeit übrig, daher überbrückt es lange Server-Ausfälle (zusätzlich zur
  bestehenden 3-Tage-Grace).
- **Revoke-Kanal:** `RenewalClient::renew()` löscht den Token **sofort** (ohne
  Grace), wenn der Server `403 {"error":"revoked"}` liefert — greift für jeden
  Tier, auch dauerhafte `internal`-Lizenzen. Reine Nicht-Erreichbarkeit bleibt
  Grace.
- **License-Server-URL fest im Bundle** (`RenewalClient::DEFAULT_LICENSE_SERVER_URL`
  = `https://license.netzhirsch.de`); Config-Feld `license_server_url` nur noch
  optionaler Override für Dev/Test (leer = Default). Kunden setzen die URL nie.

### Fixed
- **`page_preview` scheiterte an HTTP-Basic-Auth** (Briefing aus dem
  Bootstrap-Projekt, gegen den echten Code verifiziert). Das Tool holt die Seite
  über ihre **öffentliche** URL — `ContentUrlGenerator` mit `ABSOLUTE_URL`, also
  `dns`/Domain der Root-Seite. Liegt davor ein Basic-Auth-Schutz (typisch auf
  Staging), antwortet der Webserver mit 401, bevor Contao überhaupt läuft, und
  die KI kann nach einem Edit nichts verifizieren.

  Neu: `MCP_PREVIEW_BASIC_AUTH="user:pass"` in der `.env.local` der Instanz
  (Bundle-Parameter `netzhirsch_contao_mcp.preview.basic_auth`). Ist nichts
  gesetzt, geht der Request unverändert raus wie bisher — keine Regression für
  Instanzen ohne Basic Auth. Zugangsdaten landen weder in der Antwort noch im
  Log.

  Dazu ein Hinweis bei 401/403, der den Unterschied benennt: keine Credentials
  konfiguriert vs. konfiguriert und abgelehnt. Ohne den sieht der Aufrufer nur
  einen Statuscode.

  Verifiziert am echten Container: ohne Credentials kein `Authorization`-Header,
  mit Credentials `Authorization: Basic …`, kein Leak in der Tool-Antwort.

- Die Tool-Beschreibung behauptete, `page_preview` rufe „the daemon's OWN site
  (loopback)" auf. Genau deshalb greift der Public-vhost-Schutz überhaupt — der
  Request geht über die öffentliche DNS. Text korrigiert.

- Die Konfig-Tabelle im README nannte `restricted` „IAT-Pflicht" — die sechste
  Stelle derselben Falschaussage. Beide READMEs hängen jetzt mit im
  `PairingWordingTest`.
### Removed
- **`bin/build-pro-edition.php`** und **`.github/workflows/release-pro.yml`** —
  keine `-pro`-Build-Transform / kein zweites Paket mehr (durch die Ein-Edition
  obsolet).
- `LicenseToken::isEnforcementBuild()` sowie das `enforced`-Feld aus
  `LicenseGate::state()` (Enforcement ist jetzt konstant an; XLF
  `license_not_enforced` entfernt).

### Docs
- `docs/licensing/editions.md` neu als „eine Edition + Token-Tiers"; http-contract
  §3/§5 (Revoke `403`, Auto-Cron, Tier-Tabelle) und das Server-Briefing (§1/§3/§10)
  entsprechend aktualisiert.

## [0.8.11] – 2026-08-12 — „Zwei Editionen (frei / kommerziell) statt umgehbarem Env-Flag"

### Changed (Security)
- **Enforcement hängt nicht mehr am Env-Flag `NETZHIRSCH_MCP_LICENSE_ENFORCE`.**
  Ein Env-Flag ist auf der Kundenmaschine editierbar → trivialer Config-Bypass.
  Stattdessen leitet sich Enforcement aus der **Edition** ab: ob ein **echter
  Vendor-Public-Key** eingebacken ist (`LicenseToken::isEnforcementBuild()`,
  32-Byte-Ed25519 statt Platzhalter). Freie Edition = Platzhalter → dormant;
  kommerzielle Edition = echter Key → aktiv. Kein Schalter in config.json/env.
  Env-Flag-Plumbing (DI-Parameter, `_defaults`-Bind, Extension-Helper) entfernt.

### Added (Editionen — ein Codestand, kein Fork)
- **`bin/build-pro-edition.php`** — erzeugt aus dem freien Checkout die
  kommerzielle Edition in-place: backt den echten Key in `LicenseToken` ein und
  benennt das Composer-Paket in `netzhirsch/contao-mcp-bundle-pro` um (+ `replace`,
  damit beide Editionen nie zusammen installierbar sind).
- **`.github/workflows/release-pro.yml`** — CI baut/publiziert die `-pro`-Edition
  mit dem Key aus dem Repo-Secret `NETZHIRSCH_MCP_LICENSE_PUBLIC_KEY`; ohne Secret
  No-op (freier Repo unberührt). Publish-Schritt als dokumentierter Platzhalter
  (Private Packagist / privates Mirror-Repo).
- **[docs/licensing/editions.md](docs/licensing/editions.md)** — frei vs.
  kommerziell, Build-Transform, Rollout. http-contract §5 + Briefing entsprechend
  aktualisiert.

## [0.8.10] – 2026-08-12 — „License-Server-URL im Backend konfigurierbar"

### Added
- **Feld „License server URL"** auf der Seite MCP-Server → Konfiguration
  (`license_server_url`). Bisher nur direkt in `var/mcp/config.json` setzbar —
  und ein Speichern des Konfig-Formulars hätte den Wert **genullt**, weil das
  Formular das Feld nicht mitschickte/durchreichte. Jetzt sauber als UI-Feld
  gepflegt (DE/EN-Label + Hilfe).

## [0.8.9] – 2026-08-12 — „Abo-Aktionen im Backend (Stripe-Checkout/Portal, backend-getrieben)"

### Added
- **„Lizenz/Abo"-Aktionen im Backend „MCP-Server → Status"**: Buttons
  _Testphase starten_ / _Abonnieren_ / _Abo verwalten_ (letzterer bei aktiver
  Lizenz). Sichtbar nur, wenn `license_server_url` gesetzt ist.
- **`RenewalClient::checkoutSession()` + `portalSession()`** — holen eine
  **Stripe-gehostete https-URL** vom Lizenz-Server (`POST /checkout-session`,
  `POST /portal-session`); das Backend leitet dorthin weiter. **Karten-/SEPA-Daten
  fassen weder Bundle noch Server je an** (PCI bleibt bei Stripe; nur https-URLs
  werden gefolgt).
- HTTP-Contract um §3b (Checkout/Portal) erweitert; neues Server-Briefing
  [docs/licensing/license-server-briefing.md](docs/licensing/license-server-briefing.md)
  (multi-bundle-fähig, Preisverwaltung via Stripe Billing).

### Notes
- **Kein Fork nötig:** frei/intern und kommerziell teilen sich denselben Codestand
  — Unterschied ist nur das Deploy-Flag `NETZHIRSCH_MCP_LICENSE_ENFORCE` + ob ein
  Token vorliegt. Diese Erweiterung ist dormant, solange `license_server_url` leer
  ist; bestehende Instanzen ändern sich nicht.

## [0.8.8] – 2026-08-12 — „Lizenz-Gate (Trial → Monatsabo), standardmäßig schlafend"

### Added
- **License-Layer (`src/License/`)** für ein Trial-→-Abo-Modell (all-or-nothing,
  kein Freemium). Ein vom Anbieter signiertes **Ed25519-Token** (offline
  verifiziert) schaltet den gesamten Tool-Layer frei; ohne aktives Token werden
  `tools/call` sauber abgelehnt (`license_inactive`) — **Core-Contao bleibt
  unberührt, die Seite läuft weiter**. `ping` bleibt für Health-Checks frei.
  - `LicenseToken` (Signatur + Domain-Bindung + Ablauf + Clock-Rollback-Schutz),
    `LicenseStore` (`var/mcp/license.json`), `LicenseGate` (Entscheidung + 3-Tage-
    Grace), `RenewalClient` (Trial-/Renew-HTTP-Client).
  - Console: `contao:mcp:license {status|activate|trial|renew|keygen}`
    (`renew` ist cron-tauglich, `keygen` erzeugt das Anbieter-Keypair).
  - Config-Key `license_server_url` in `var/mcp/config.json`.
  - Backend „MCP-Server → Status" zeigt den Lizenzstatus.
- **HTTP-Contract** für den (separat gehosteten) Lizenz-Server:
  [docs/licensing/http-contract.md](docs/licensing/http-contract.md) — `POST /trial`
  (Restart-Sperre: ein Trial pro Domain/Konto, serverseitig) + `POST /renew`
  (nur bei bezahltem Abo).

### Security / Design
- **Standardmäßig schlafend:** Enforcement hängt am Deploy-Env-Flag
  `NETZHIRSCH_MCP_LICENSE_ENFORCE` (Default aus) — **nicht** an einem
  `config.json`-Feld (das wäre ein Ein-Zeilen-Bypass). Dieses Release ändert das
  Laufzeitverhalten also nicht; bestehende freie/interne Instanzen laufen
  unverändert weiter. Der Public Key ist eine Code-Konstante (nicht kunden-
  editierbar). Ehrliche Grenze: bei Self-hosted-PHP ist der Gate-Code patchbar —
  echter Schutz = kein gültiges Token ohne Anbieter-Secret-Key + Update-Zugang
  via Composer/Private Packagist.

### Tests
- Smoke-Test-Sektion „License token": 6 Verifier-Asserts (gültig, falsche Domain,
  abgelaufen, falsches Produkt, manipuliert, fremd-signiert). Standalone
  zusätzlich verifiziert (inkl. Host-Normalisierung + Clock-Rollback).

## [0.8.7] – 2026-08-10 — „Contao 5.3 offiziell unterstützt + CI-verifiziert"

### Changed
- **PHP-Untergrenze `^8.3` → `^8.1`.** Der Code nutzt (statisch auditiert)
  keinerlei 8.2/8.3-Sprachfeatures, und Contao 5.3 läuft auf PHP 8.1+. Der
  bisherige `^8.3`-Constraint hätte Contao-5.3-Kunden auf PHP 8.1/8.2 die
  Installation komplett verwehrt (`composer require` bricht vor jeder
  Code-Frage ab). Reine Erweiterung der Kompatibilität — 5.7-Nutzer (ohnehin
  auf 8.3+) sind nicht betroffen.
- **Smoke-Test versionsrobust:** der `template_dependencies`-Assert parste
  bisher das Core-Template `news_full`, das auf Contao 5.3 noch `.html5`
  (kein Twig) ist → er parst jetzt die selbst angelegte `.html.twig`-Fixture.
  Grün auf 5.3 **und** 5.7. Das Tool selbst verhielt sich immer korrekt
  (auf 5.3 sauberes `not_found` statt Crash).

### CI
- **Neuer Matrix-Leg Contao 5.3 / PHP 8.1** im Smoke-Test (neben 5.7 auf
  PHP 8.3 + 8.4), damit der 5.3-Support dauerhaft verifiziert bleibt.

### Verified
- Frischer Contao **5.3.49** + Symfony **6.4** + DBAL **3** — sowohl gegen das
  PHP-8.1- als auch das PHP-8.3-aufgelöste Dependency-Set (Bridge 2.x bzw. 7.x):
  `composer install`, `contao:migrate` und der volle Smoke-Test (**203/203**)
  laufen grün. Keine Änderung am Tool-Layer nötig.

## [0.8.6] – 2026-06-29 — „Lesender Zugriff auf Formular-Leads (terminal42/contao-leads)"

### Added
- **Leads-Integration (nur lesend).** Zwei neue Extension-Tools für
  `terminal42/contao-leads`, analog zu url_rewrite/changelanguage: erscheinen
  automatisch, sobald das Bundle installiert ist, und returnen sonst
  `extension_not_available`.
  - `leads_list` — Formular-Einsendungen aus `tl_lead`, neueste zuerst, mit
    optionalen Filtern `form_id` / `member_id` / `language` und aufgelöstem
    `form_title` (Join auf `tl_form`).
  - `lead_get` — eine Einsendung inkl. der normalisierten Feldwerte aus
    `tl_lead_data` (`data: [{field_id, name, label, value}]`). Der rohe
    `post_data`-Blob wird bewusst nicht ausgegeben (`has_post_data`-Flag genügt).
- **Bewusst kein CRUD:** Leads sind echte Frontend-Einsendungen und auch im
  Contao-Backend nicht editierbar (`tl_lead` ist `notEditable` + `closed`) —
  ein „Lead schreiben" gibt es daher nicht.

### Changed
- `installed_bundles` meldet die Leads-Integration jetzt unter
  `mcp_entity_extensions` (mit `available`-Flag), die Tool-Gruppe „Form leads
  (terminal42, read-only)" erscheint im Backend-Tool-Panel.
- Permission-Parität: `leads_list` + `lead_get` sind über das Backend-Modul
  `lead` (BE_MOD-Gruppe „leads") gated — wer Leads im Backend sehen darf, darf
  sie auch per MCP lesen; Admins immer.

## [0.8.5] – 2026-06-23 — „Client-Registrierungsmodus im Backend konfigurierbar"

### Added
- **`oauth_registration_mode` in der Backend-Konfiguration.** Der Modus
  (`restricted` / `open`) war bisher nur via direktem Edit von
  `var/mcp/config.json` umstellbar. Er ist jetzt als Select-Feld auf der
  Konfigurationsseite (MCP-Server → Konfiguration) sichtbar und speicherbar.
- **Warnung auf der Status-Seite** wenn `oauth_registration_mode = open` aktiv
  ist: roter Hinweis-Block mit Link zur Konfigurationsseite ersetzt den
  bisher nur schwach sichtbaren orangenen Indikator-Punkt.

## [0.8.4] – 2026-06-12 — „External-ID-Backend-Labels (Legend pro Tabelle)"

### Fixed
- **Backend-Übersetzungen der External-ID-Felder.** Die von
  `ExternalIdDcaInjector` in 23 Tabellen injizierten Spalten zeigten im Backend
  kein korrektes Fieldset-Label: Contao löst Palette-Legenden **pro Tabelle**
  über `$GLOBALS['TL_LANG'][$table][<legend>]` auf (kein MSC-Fallback, siehe
  `DC_Table`), unsere Übersetzung lag aber nur in `MSC.external_id_legend` →
  der Abschnitts-Header rendete roh „external_id_legend". Der Injector spiegelt
  das Legend-Label jetzt in jede unterstützte Tabelle und lädt die
  `default`-Sprachdatei defensiv, bevor er die MSC-Referenzen der Feld-Labels
  (`external_id_namespace`/`external_id_key`) setzt — robust gegen die
  loadDataContainer-vor-loadLanguageFile-Reihenfolge. Smoke-Test deckt Feld-
  und Legend-Label ab.

## [0.8.3] – 2026-06-12 — „Smoke-Test an feldspezifische Layout-Blobs angepasst"

### Changed
- **Nur Tests, kein Runtime-Change.** Die Smoke-Test-Assertion für die
  tl_layout-Blob-Spalten verlangte noch von allen vier Feldern `a:0:{}` — nach
  0.8.2 falsch. Sie prüft jetzt feldspezifisch (`sections=''`,
  `modules`/`external`/`externalJs=a:0:{}`) und sichert damit beide
  Crash-Klassen (Backend-sectionWizard, Frontend-`foreach(null)`) als
  Regression ab.

## [0.8.2] – 2026-06-12 — „Hotfix: Frontend-Regression aus 0.8.1 (leere modules)"

### Fixed
- **Regression aus 0.8.1 — Frontend-HTTP-500 bei MCP-erzeugten Layouts.**
  0.8.1 speicherte *alle* leeren Layout-Blob-Felder als `''`. Für `modules` ist
  das fatal: Contaos Frontend liest `modules` **ohne** force-array
  (`PageRegular::compile()` → `foreach (StringUtil::deserialize($layout->modules))`),
  `''` deserialisiert zu `null`, und `foreach (null)` killt jede Seite mit einem
  so erzeugten Layout (HTTP 500). Jetzt feldspezifisch korrekt: **nur
  `sections` → `''`** (das ist der Backend-sectionWizard-Crash aus 0.8.1, und
  alle Frontend-Leser von `sections` sind null-geguardet), **`modules` /
  `external` / `externalJs` → `a:0:{}`** (frontend-sicher, da → `[]`). Wer 0.8.1
  bereits ausgerollt hat, sollte direkt auf 0.8.2.

## [0.8.1] – 2026-06-12 — „Layout-Backend-Crash-Fix + public-Symlink-Check + Windows-Robustheit"

### Fixed
- **Backend-Crash (HTTP 500) beim Bearbeiten MCP-erzeugter Layouts.**
  `layout_create`/`layout_update` legten leere `sections` (sowie
  `modules`/`external`/`externalJs`) als serialisiertes leeres Array `a:0:{}`
  ab. Contaos `SectionWizard::generate()` macht einen ungeguardeten
  `!$this->varValue[0]`-Zugriff — auf einem deserialisierten `[]` wirft das
  „Undefined array key 0", und die Layout-Bearbeitung stirbt mit HTTP 500.
  Leere Mehrwert-/Wizard-Felder werden jetzt als `''` gespeichert (exakt wie
  Contaos Backend selbst), sowohl beim Create-Seeding als auch im FieldMapper
  (greift damit auch beim Leeren via `layout_update` mit `sections: []`).
  Bestehende kaputte Zeilen lassen sich per `layout_update` reparieren (oder
  `UPDATE tl_layout SET sections='' WHERE sections='a:0:{}'`).

### Added
- **`system_health_check` prüft jetzt die public-Symlinks** (`public/files`,
  `public/assets`). Fehlen oder brechen sie, gibt der Check eine Warnung aus
  („every /files/… URL will 404 …") samt Fix-Hinweis (`contao:symlinks`) —
  fängt genau den Fall ab, in dem das Frontend ohne CSS/JS/Bilder lädt. Neue
  Ergebnis-Sektion `filesystem` mit `public_dir` + `symlinks`.

### Changed
- **Windows-Robustheit beim Tool-Dispatch.** Eine transiente Dateisystem-
  IOException (kalter DCA-Cache + parallele Requests → atomic rename von
  `var/cache/<env>/contao/dca/*.php` scheitert mit „Access is denied") wird
  jetzt bis zu 3× mit kurzem Backoff wiederholt, statt den Tool-Call scheitern
  zu lassen. Betrifft nur Windows-Dev; Linux/Prod wärmen den DCA-Cache beim
  Deploy und sind davon nicht betroffen.

## [0.8.0] – 2026-06-11 — „Erstes Stable-Release"

Promotion der Beta-Linie (zuletzt `0.8.0-beta17`) zu einem stabilen Release —
**funktional identisch mit `0.8.0-beta17`, keine Code-Änderungen.** Die über die
Beta-Phase gewachsene und in beta17 eingefrorene API gilt ab hier als stabil.

Konsumierende Instanzen können die Composer-Constraint von `dev-master` auf
`^0.8` umstellen (siehe README → Installation); im Contao Manager erscheint dann
`0.8.0` statt `dev-master`. `minimum-stability` muss dafür **nicht** angepasst
werden (`0.8.0` ist ein stabiles Release).

## [0.8.0-beta17] – 2026-06-11 — „Real-World-Briefing hp-hl.de: Upload-Robustheit, Layout-Korruption, cssID"

### Fixed
- **Datenkorruption in `layout_update`/`layout_create`:** `modules[].mod`
  wurde per `(int)` gecastet — `content-149` (Referenz auf ein Content-
  Element) wurde damit still zu `0` und die Referenz ging verloren. `mod`
  bleibt jetzt erhalten: numerische Werte als int (Modul-ID / `0`-Platzhalter),
  alles andere (z. B. `content-<id>`) verbatim als String.
- **`file_upload` → `invalid_base64` bei größeren Dateien:** `base64_decode`
  lief im Strict-Modus und scheiterte an JEDEM Whitespace/Zeilenumbruch — ein
  vom Transport umgebrochener (aber intakter) Base64-String wurde fälschlich
  abgelehnt. Whitespace wird jetzt vor dem Decoden gestrippt; die Fehlermeldung
  nennt zusätzlich empfangene Länge + die letzten Zeichen (Truncation-Diagnose).

### Added
- **`file_upload` per `source_url` (Server-Pull)** — der robuste Weg für
  Dateien über ~50 KB, die der Inline-Base64-Transport sonst abschneidet. Der
  Server lädt die Datei selbst. **SSRF-Guard:** nur http/https, öffentliche
  Hosts (private/reservierte/Loopback/Link-local inkl. Cloud-Metadata
  169.254.169.254 werden abgelehnt), keine Redirects, 64-MB-Pull-Deckel vor
  der konfigurierten `maxFileSize`-Prüfung. Entweder `content_base64` ODER
  `source_url`.
- **`content_create`/`content_update` akzeptieren `cssID`/`space` als Objekt**
  (`{id,class}` / `{top,bottom}`) — konsistent mit `article_*`; Plain-String
  bleibt für Rückwärtskompatibilität erlaubt. Veraltete „object form not
  supported"-Doku korrigiert.

### Offene Punkte aus dem Briefing (separat)
- Vorschau unveröffentlichter Seiten (`page_url`/`page_preview` mit
  Backend-Preview-Token) — eigener Aufwand, folgt separat.
- Konsistenz-Check für verwaiste Layout-Referenzen beim Auslesen — Nice-to-have.

## [0.8.0-beta16] – 2026-06-11 — „entity_move kann jetzt umhängen (Cut/Paste)"

### Added
- **`entity_move` re-parented jetzt** (optional `into_pid` + `into_ptable`) —
  das MCP-Äquivalent zu Contaos Cut/Paste, zusätzlich zum bisherigen
  Drag-Sortieren. Eine Zeile lässt sich unter ein neues Elternteil hängen und
  dort positionieren (`target_id` = Sibling am Ziel): Content-Element in einen
  anderen Artikel/Container (`into_ptable`), Artikel auf eine andere Seite,
  Seite an einen anderen Baum-Knoten (`into_pid=0` = Root).
  - Re-Parent validiert die Ziel-Existenz, **lehnt Zyklen ab** (Seite unter
    eigene Unterseite, Element unter sich selbst/Nachfahren) und erzwingt
    **Rechte-Parität** (Quelle editierbar + Ziel beschreibbar) über dieselben
    Voter wie die CRUD-Tools.
  - Reine Sortierung ohne `into_pid` verhält sich unverändert.
  - Atomar in einer DBAL-Transaktion (Re-Parent + Sortierung in einem UPDATE),
    Antwort um `reparented`/`into_pid`/`into_ptable` erweitert.

## [0.8.0-beta15] – 2026-06-11 — „entity_duplicate: Datensätze samt Kind-Baum kopieren"

### Added
- **`entity_duplicate`** — generisches, DCA-getriebenes Duplizieren für
  `tl_page`, `tl_article`, `tl_content`; das MCP-Äquivalent zum
  Backend-„Kopieren". Kind-Bäume kaskadieren automatisch (Artikel → Inhalts-
  elemente inkl. verschachtelte, Container-CE → Kinder, Seite → Artikel),
  `with_children` kopiert zusätzlich den Unterseiten-Baum. Spiegelt Contaos
  Kopierverhalten: `doNotCopy`-Felder werden ausgelassen, der **Alias neu
  generiert** (eindeutig, via Slug), **External-ID-Spalten auf NULL**
  zurückgesetzt, Sortierung ans Ende gehängt; Root-Seiten bekommen
  `fallback`+`dns` geleert. Rechte-Parität (Quelle lesen + Ziel anlegen),
  Versions-Snapshot + tl_log. Neuer Service `RecordDuplicator` (engine),
  `overrides`-Param als `#[Schema(object)]`.
- Live gegen cwa verifiziert: Container-CE (Gruppe + 2 verschachtelte),
  Artikel-mit-Inhalt (+ Alias/External-ID-Reset + title-Override), Seite-mit-
  Artikeln — inkl. sauberem Cleanup.

### Fixed (während der Implementierung gefunden)
- Roh-INSERT quotet Spalten nicht → Reserved-Word `groups` (auf
  tl_content/tl_article/tl_page) sprengte die Query; Spalten werden jetzt
  per `quoteIdentifier` gequotet.
- External-ID-Reset auf `''` kollidierte mit dem Composite-UNIQUE (nur ein
  `''`, aber beliebig viele NULL) → auf **NULL** umgestellt.
- Roh-INSERT umgeht den Alias-`save_callback` → leerer Alias (Seiten-Routing
  kaputt); Alias wird jetzt nach dem Insert via Slug eindeutig regeneriert.

## [0.8.0-beta14] – 2026-06-11 — „Fix: Kopfleisten-Aktionen (Pairing/IAT/Widerruf) lösten nicht aus"

### Fixed
- **Pairing-Fenster öffnen / schließen, IAT erzeugen, Client widerrufen
  funktionieren wieder.** Die GET-Kopfleisten-Aktionen (seit dem
  OAuth→Status-Merge, beta10) wurden still verworfen: der CSRF-Check verglich
  `Input::get('rt')` per `===` mit `getDefaultTokenValue()`. Contaos
  CSRF-Tokens sind aber **pro Generierung randomisiert** — jeder Backend-Link
  trägt ein anderes `rt`, alle gültig für denselben Token-Namen. Der
  String-Vergleich schlug damit praktisch immer fehl. Jetzt exakt wie der
  Contao-Core: `isTokenValid(new CsrfToken(<token-name>, rt))` (Backend.php /
  DC_Table.php). Betrifft alle vier GET-Aktionen der Status-Seite.

## [0.8.0-beta13] – 2026-06-10 — „Coverage-Lauf: Nested-Content, table-Matrix, Meta-Schema, atomarer Upload"

### Fixed
- **Nested-Content (Contao 5):** `content_create`/`content_update` akzeptieren
  jetzt `ptable="tl_content"` + `pid=<container-id>` — Kinder von
  accordion/swiper/element_group lassen sich anlegen und verschieben. Mit
  Zyklen-Schutz (kein Element unter sich selbst oder einen Nachfahren) und
  Tiefen-Cap gegen Renderer-Endlosschleifen. (Briefing-Befund 1, Blocker)
- **`tableitems` (table-CE):** wurde fälschlich als Integer-Liste validiert
  (zerstörte die Matrix zu Zeilenzahlen, lehnte Updates ab). Jetzt korrekt als
  2D-String-Matrix (`list<list<string>>`) validiert, serialisiert und beim
  Lesen zurück dekodiert. (Befund 2)
- **`file_upload`/`file_update_meta` `meta`:** war type-los (`mixed`) → vom
  Client verworfen (CWA-26-Familie). Jetzt konkretes Objekt-Schema
  (`{locale: {title, alt, link, caption}}`). (Befund 3)
- **`file_upload` ist jetzt atomar:** `meta` wird VOR dem Schreiben validiert —
  ein ungültiges `meta` hinterlässt keine Datei-Leiche mehr in FS/DBAFS, über
  die ein Retry mit „file already exists" stolpert. (Befund 4)
- **Layout-Doku:** Artikel-/Inhalts-Platzhalter in `modules` ist die Pseudo-ID
  **`mod: 0`**, nicht `-1` (mit `-1` blieb `<main>` leer). Beschreibung +
  Beispiel korrigiert. (Befund 5)

### Changed
- **Schema-Audit (CWA-26-Familie) abgeschlossen:** systematischer Dump aller
  169 Tools auf type-lose Properties → **0 verbleibend**. Neben `meta`
  typisiert: `article.cssID`/`space` (Objekt-Form), `extras` in
  article/calendar_event/faq/news/page, `url_rewrite.requestRequirements`/
  `conditionalResponseUri`, `page_translations_tree.root_id`. Fragile Clients
  (mcp-remote, Claude-Code-Loader) übertragen diese Werte jetzt zuverlässig.

> Hinweis: param-level `#[Schema(definition: …)]` wird vom SchemaGenerator
> NICHT entfaltet (landet als toter `definition`-Key) — Objekt-Params brauchen
> die expliziten Felder `type`/`properties`/`additionalProperties`.

## [0.8.0-beta12] – 2026-06-10 — „Robot-Icon, 7-Tage-Notiz zurückgenommen"

### Changed
- **Gruppen-Icon:** Font-Awesome-Pro `robot` (light) ersetzt
  `chart-network`. Nach dem Update `assets:install` ausführen.
- **7-Tage-Registrierungsnotiz auf der Status-Seite wieder entfernt:**
  Contao kennt keine wegklickbaren Backend-Hinweise, und Custom-JS dafür
  widerspräche dem Standard-UI-Kurs — eine nicht schließbare Dauer-Notiz
  wäre nur Rauschen gewesen. Der `tl_log`-Eintrag pro Registrierung
  (beta11, sichtbar in Aktivität + Systemlog) bleibt als Audit-Trail
  bestehen.

## [0.8.0-beta11] – 2026-06-10 — „Status-Feinschliff + Hinweis bei neuen Client-Registrierungen"

### Added
- **Backend-Hinweis bei neuen Registrierungen:** Jede erfolgreiche
  Client-Registrierung schreibt jetzt einen tl_log-Eintrag (Source
  `mcp_oauth`, sichtbar in MCP-Server → Aktivität und im Systemlog) inkl.
  Registrierungsweg (Pairing-Fenster / IAT / offen). Zusätzlich zeigt die
  Status-Seite für jeden in den **letzten 7 Tagen** registrierten Client
  eine `tl_new`-Notiz mit Konsens-Stand — „noch NICHT autorisiert —
  widerrufen, falls unbekannt" macht verwaiste oder fremde Registrierungen
  sofort sichtbar.

### Fixed
- Status-Seite: Zwischenüberschriften „Initial Access Tokens" und
  „Registrierte Clients" nutzen jetzt Contaos Standard-Klasse
  `sub_headline` und fluchten damit mit den Tabellen (vorher bündig am
  linken Panelrand).

## [0.8.0-beta10] – 2026-06-10 — „Status = OAuth-Zentrale, Doku als Repo-Markdown, neues Gruppen-Icon"

### Changed (Breaking für beta7–9-Bookmarks/Modulrechte)
- **Menü auf vier Punkte verschlankt: Status, Konfiguration, Aktivität,
  Tools.** Der Menüpunkt OAuth ist in **Status** aufgegangen — Betriebszustand
  und die Hebel dazu gehören auf eine Seite: Kopfleisten-Buttons (Pairing
  öffnen/schließen, Neues IAT — Erklärtexte als Tooltips statt Info-Boxen),
  Status-Tabelle inkl. Registrierungszustand, IAT-Tabelle (nur wenn
  vorhanden) und Client-Tabelle mit Widerrufen-Operation. Keine Prosa-Boxen
  mehr; OAuth-Daten werden nur bei `auth_mode=oauth` geladen/angezeigt.
- **Backend-Doku entfernt, lebt jetzt als Markdown im Repo:**
  [docs/installation.md](docs/installation.md) (Client anbinden,
  Schritt-für-Schritt, getrennt nach Online/Lokal) und
  [docs/dokumentation.md](docs/dokumentation.md) (Funktionsreferenz inkl.
  Config-Tabelle, Fehlersuche mit `.htaccess`-Snippet für
  Basic-Auth-Hosting). Der Menüpunkt Dokumentation, `ModuleMcpDocs` und die
  vier HTML-Doku-Templates sind gelöscht; README verlinkt die neuen Dateien.
- **Gruppen-Icon:** Font-Awesome-Pro `chart-network` (16×16, Core-Grau)
  ersetzt die Eigenbau-Glyphe. Nach dem Update `assets:install` ausführen.
- Modulrechte für `netzhirsch_mcp_oauth`/`netzhirsch_mcp_docs` verfallen;
  Bookmarks auf `do=netzhirsch_mcp_oauth`/`…_docs` laufen ins Leere.

## [0.8.0-beta9] – 2026-06-10 — „BE-Feinschliff: OAuth-Fatal, Standard-Hinweise, native Tools-UI, Install-Doku"

### Fixed
- **Fatal auf der OAuth-Seite** („Using $this when not in object context"):
  die Action-Link-Closure war `static` deklariert und griff auf
  `$this->actionUrl` zu — URL wird jetzt vorab in eine Variable gezogen.

### Changed
- **Hinweise im Contao-Standard:** Seiten-/Abschnittshinweise (Aktivität,
  OAuth, Tools) nutzen jetzt die Standard-Info-Box
  (`tl_message` + `tl_info`, dasselbe Markup wie `Message::addInfo()`)
  statt freischwebender `tl_help`-Absätze.
- **Tools-Seite in nativer Contao-Edit-UI:** je Gruppe ein aufklappbares
  Paletten-Fieldset (wie beim Seiten-Editieren), darin native
  Checkbox-Container mit „Alle auswählen"
  (`Backend.toggleCheckboxGroup`, in Gruppen mit System-Tools bewusst
  ohne); Beschreibung als Tooltip am Label, Extension-/System-Kennzeichnung
  als dezenter Text-Suffix. Custom-CSS/-JS und das Suchfeld sind entfernt.
- **Installationsanleitung neu geschrieben (DE/EN):** Schritt-für-Schritt,
  getrennt nach **Online-Instanz** (HTTPS, native Custom Connectors, kein
  Node) und **lokaler Instanz** (Laragon, mcp-remote + `--allow-http`,
  App-komplett-beenden-Regel). Der überholte „Toggle-Workaround" (Select
  existiert seit beta5 nicht mehr) ist durch Pairing-Fenster + IAT ersetzt;
  Verifikation über „Autorisiert von" beschrieben.

## [0.8.0-beta8] – 2026-06-10 — „Gruppen-Icon + vendor-genamespacte BE_MOD-Keys"

### Fixed
- **Gruppen-Icon der Backend-Navigation** nach dem getesteten Hausrezept
  verdrahtet (beta7 hatte keins): 16×16-SVG (`public/icon.svg`, MCP-Hub-
  Glyphe in Core-Grau) + `public/backend.css` mit Background-Shorthand
  exakt nach Core-Muster (`.group-netzhirsch_mcp { background: url(...)
  3px 2px no-repeat }` — kein background-size, sonst kachelt es). Die CSS
  lädt backend-only über einen `getUserNavigation`-Hook
  (`BackendCssListener`), nie im Frontend. Level-2-Einträge bekommen
  konventionsgemäß kein Icon (icon-Keys entfernt). Nach dem Update einmal
  `assets:install` ausführen.

### Changed (Breaking für beta7-Bookmarks/Modulrechte)
- **BE_MOD-Keys vendor-genamespaced** (Pflicht-Konvention, Kollisions-
  schutz): Gruppe `mcp` → `netzhirsch_mcp`, Module `mcp_*` →
  `netzhirsch_mcp_*` (inkl. `MOD.*`-Sprachschlüssel). Wer beta7 bereits
  installiert und Modulrechte vergeben hatte: einmal neu vergeben;
  `do=mcp_*`-Bookmarks → `do=netzhirsch_mcp_*`.

## [0.8.0-beta7] – 2026-06-10 — „Eigene Backend-Menügruppe statt Monolith-Modul"

### Changed (Breaking für Bookmarks/Modulrechte)
- **Neue Backend-Menügruppe „MCP-Server"** ersetzt das eine Modul unter
  System → MCP-Server. Jede frühere Aufklapp-Kategorie ist jetzt ein eigener
  Menüpunkt: **Status**, **Konfiguration**, **OAuth** (Pairing + IATs +
  Clients), **Aktivität**, **Tools**, **Dokumentation** (Installations-
  anleitung + Referenz zusammengelegt). Implementiert als
  `AbstractMcpModule` + sechs schlanke Subklassen mit je eigenem Template.
- **Contao-Standard-Layout pro Seite:** globale Aktionen (Pairing öffnen/
  schließen, IAT erzeugen) sitzen jetzt oben in der `#tl_buttons`-Kopfleiste;
  IATs/Clients/Aktivität sind `tl_listing`-Tabellen, Client-Widerruf ist eine
  Zeilen-Operation mit Confirm; Konfiguration und Tools sind Edit-Formulare
  mit Sticky-Submit.
- Modulrechte: die sechs Menüpunkte sind einzeln über die normalen
  tl_user/tl_user_group-Modul-Checkboxen vergebbar (z. B. nur Doku +
  Aktivität für Redakteure). Bestehende `mcp_server`-Berechtigungen
  verfallen — Nicht-Admins einmal neu berechtigen. Bookmarks auf
  `do=mcp_server` laufen ins Leere (`do=mcp_status` … `do=mcp_docs`).

### Fixed
- **CSRF-Härtung:** Aktionen per GET (die neuen Kopfleisten-Buttons)
  werden nur noch mit gültigem `rt`-Token ausgeführt. Vorher akzeptierte
  der Action-Dispatch GET-Aufrufe ohne jede Token-Prüfung (z. B.
  `?action=generate_iat`) — mit dem Pairing-Fenster wäre das ein echtes
  CSRF-Ziel geworden.

## [0.8.0-beta6] – 2026-06-10 — „Client-Tabelle zeigt den autorisierenden Backend-Benutzer"

### Added
- **Spalte „Autorisiert von" in der Client-Tabelle** (OAuth-Verwaltung):
  zeigt, welcher Backend-Benutzer den Consent für den Client erteilt hat.
  Aufgezeichnet beim Authorize (nur bei Zustimmung, nie bei Ablehnung) in
  neuen Spalten `authorized_user_id`/`authorized_username`/`authorized_at`
  auf `tl_mcp_oauth_client` — Username denormalisiert, damit die Anzeige
  einen tl_user-Löschvorgang überlebt. Letzter Consent gewinnt. Tooltip
  zeigt den Consent-Zeitpunkt.
- **Fallback für Bestands-Clients** (vor dieser Version registriert): der
  Benutzer wird aus dem neuesten Access-Token abgeleitet (Join auf
  `tl_mcp_oauth_access_token.user_id` → `tl_user.username`; gelöschter User
  → Label `tl_user #<id>`). Noch nie autorisierte Clients zeigen „—" mit
  erklärendem Tooltip.
- **Deploy-Hinweis:** `contao:migrate` erforderlich (drei neue Spalten,
  rein additiv aus der DCA-Definition).

## [0.8.0-beta5] – 2026-06-10 — „Registrierungsmodus-Select aus der UI entfernt"

### Changed
- **Das Select „Client-Registrierung" (offen/eingeschränkt) ist aus dem
  Konfigurations-Formular entfernt.** Sein einziger praktischer Zweck war der
  open/restricted-Toggle-Workaround, den das Pairing-Fenster (beta4) ersetzt —
  und als Dauerschalter war er ein Footgun (Instanzen blieben unbemerkt auf
  „offen" stehen). Neue Clients verbindet man über das Pairing-Fenster,
  Skripte über IATs.
- Der Config-Key `oauth_registration_mode` bleibt voll funktionsfähig und
  handeditierbar in `var/mcp/config.json` (Dev-Sonderfälle); steht er auf
  `open`, warnt die Status-Box weiterhin orange. `handleSaveConfig` reicht den
  gespeicherten Wert durch (kein stilles Zurücksetzen).
- Doku DE/EN entsprechend angepasst; tote XLF-Keys (`config_reg_mode_label`,
  `config_reg_mode_help`) entfernt.

## [0.8.0-beta4] – 2026-06-10 — „Client-Pairing-Fenster: Schluss mit dem open/restricted-Toggle"

### Added
- **Pairing-Fenster für die Client-Registrierung.** Standard-MCP-Clients
  (mcp-remote, Claude Desktop) können bei der RFC-7591-Registrierung keinen
  Initial-Access-Token-Header senden — bisher musste man dafür den
  Registrierungsmodus kurz auf „offen" stellen (und durfte das Zurückstellen
  nicht vergessen). Neu: Button **„Registrierung für 10 Minuten öffnen"**
  (OAuth-Verwaltung → „Neuen Client verbinden"). Das Fenster gilt für
  **maximal 10 Minuten oder genau EINE erfolgreiche Registrierung** — was
  zuerst eintritt — und verriegelt sich danach selbst (`registration_open_until`
  in config.json, Auto-Close im RegisterController + Log-Eintrag).
- Status-Box zeigt den Registrierungszustand (offen / Fenster aktiv mit
  Restzeit / eingeschränkt); aktives Fenster lässt sich per Button sofort
  schließen.
- Sicherheitsmodell unverändert: Auch ein im Fenster registrierter Client
  erhält erst nach Backend-Login + Consent ein Token; der IAT-Pfad bleibt für
  Skripte/Automationen bestehen (Hilfetexte entsprechend geschärft).
- Doku: Backend-Docs DE/EN (§ Client-Registrierung) +
  docs/mcp-client-lokal-einrichten.md auf den Pairing-Flow umgestellt.
- Verifiziert end-to-end gegen den echten Kernel: geschlossen → 401 (Meldung
  nennt das Fenster), offen → 201 + Auto-Close, Retry → 401, IAT-Pfad → 201,
  abgelaufenes Fenster → 401.

## [0.8.0-beta3] – 2026-06-10 — „Backend-Panel: jedes Tool einzeln abschaltbar"

### Added
- **Tool-Panel im Backend (System → MCP-Server → Tools):** jedes der ~170
  Tools lässt sich einzeln aktivieren/deaktivieren — auch die Tools der
  unterstützten Fremd-Plugins (terminal42/url_rewrite als eigene Gruppe,
  changelanguage als neue Gruppe „Mehrsprachigkeit"). UI: live aus der
  Registry gruppierte Karten mit Gruppen-Master-Checkboxen (indeterminate),
  Live-Suchfilter, Zähler pro Gruppe + Gesamtsumme, Beschreibung als Tooltip,
  Badges `EXT` (Extension-Tool, Opt-in) und `SYSTEM` (geschützt). Ersetzt die
  bisherige statische Tool-Tabelle.
- **Neuer Config-Key `disabled_tools`** (Opt-out, Core-Tools): ein
  deaktiviertes Tool verschwindet vollständig aus dem MCP-Katalog —
  `tools/list`, `contao_search_tools`, `contao_describe_tool`, `contao_call`
  UND `tools/call` („tool not found") — über EINEN Mechanismus: die Registry
  wird auf dem Serving-Pfad gepruned (`ToolCatalog::prune()` in
  `HttpDispatcherFactory::getDispatcher()`). Das Backend liest die ungeprunte
  Registry (`getServer()`), damit deaktivierte Tools im Panel sichtbar
  bleiben. Geschützt und nie deaktivierbar: `contao_search_tools`,
  `contao_describe_tool`, `contao_call`, `ping`.
- **Extension-Tools im selben Panel:** angebotene, aber noch nicht
  aktivierte Fremd-Tools (`ExtensionToolRegistrar::candidates()`) erscheinen
  als unangehakte `EXT`-Zeilen; Anhaken schreibt `extension_tools_enabled`.
  Die Opt-in-Sicherheitssemantik bleibt unverändert — extending Bundles
  bekommen die Abschalt-UI automatisch, ohne eigenen Code.
- **Merge-Semantik mit Bestandsschutz:** Tool-Namen, die das Formular gerade
  nicht rendert (z. B. Bundle vorübergehend deinstalliert), behalten ihren
  gespeicherten Zustand statt still zurückgesetzt zu werden.

### Changed
- Gruppen-Taxonomie fürs Panel: Plural-Listen-Gruppen (news_archives,
  calendar_events, …) in ihre Singular-CRUD-Gruppe gefaltet, Helfer-Gruppen
  (content_palette, dbafs, …) in die Eltern-Entität; `groupOf()` aus dem
  Discovery-Tool nach `Server\ToolGroups` extrahiert (Suche unverändert).
- EXTENDING.md: Panel als Standard-Aktivierungsweg dokumentiert; Author-
  Contract #8 (Beschreibung = Operator-UI, Tool-Name ist frozen API).
- Docs DE/EN: `disabled_tools` + Panel-Verweis in der Config-Referenz.

### Fixed
- `handleSaveConfig` hätte den neuen Key bei jedem Speichern geleert —
  beide Tool-Listen werden jetzt durchgereicht (Regression-Test dokumentiert
  das Verhalten).

## [0.8.0-beta2] – 2026-06-10 — „Schema: alle Nullable-Unions geflattet (CWA-26 Restfix)"

### Fixed
- **Flache optionale Params funktionieren jetzt über fragile Bridges
  (mcp-remote, Claude-Code-Deferred-Tool-Loader).** Der beta8-Fix deckte nur
  die Objekt-Params mit `#[Schema]`-Attribut ab; die per Reflection aus
  `?bool`/`?string`/`?int $x = null` generierten Unions `["null", T]`
  (z. B. `page_create.fallback`, `.language`, `.layout`, `contao_call.args`)
  wurden vom Client weiterhin verworfen → Param typlos → Wert kam als
  `unknown` an → `-32602` bei jedem Wert. Eine Root-Page war damit nicht
  funktionsfähig anlegbar.
- **Fix (zentral, statt 500 Attribute):** neuer `NullableUnionFlattener` läuft
  beim Server-Boot über die **gesamte Registry** (Core- + gecachte +
  Extension-Tools) und schreibt jede `["null", T]`-Union auf den nackten
  Einzeltyp um (`?bool` → `"boolean"`, `?array` → `"array"`, …, rekursiv auch
  in `items`/nested `properties`). Die Optionalität bleibt über `default: null`
  + Nicht-`required` doppelt ausgedrückt — der `"null"`-Zweig war redundant.
  Wirkt konsistent auf `tools/list`, `contao_describe_tool` UND die
  Server-Validierung. Echte Mehr-Typ-Unions (gibt es derzeit nicht) bleiben
  unangetastet; idempotent.
- **Kompatibilität für explizite `null`-Werte:** `ObjectAwareSchemaValidator`
  behandelt `param: null` für nicht-required Properties jetzt als „nicht
  übergeben" (nur für die Validierungs-Kopie; der Handler bekommt die
  Original-Args — PHP-seitig sind die Params nullable). Clients, die statt
  Weglassen explizit null senden, brechen also nicht.
- Verifiziert end-to-end gegen den echten Container: **0 verbleibende Unions
  über alle 169 Tools** (frischer UND gecachter Discovery-Pfad), inkl.
  Real-Schema-Regression-Test gegen php-mcps SchemaGenerator auf
  `page_create` (schlägt an, falls Upstream das Emissionsverhalten ändert).

> **Nach Deploy:** Bundle aktualisieren → `cache:clear` → **Connector
> reconnecten** (Client muss die neuen Schemas laden). Test:
> `page_create(pid:0, …, language:"de", fallback:true, published:true)` und
> `contao_call(name:"ping", args:{})` müssen ohne `-32602` durchlaufen;
> `contao_describe_tool("page_create")` zeigt `fallback` als
> `"type":"boolean"`.

## [0.8.0-beta1] – 2026-06-10 — „Parität: changelanguage, url-rewrite & Extension-Opt-in"

### Added
- **Deklaratives Permission-Opt-in für Extension-Tools.** Drittanbieter-Tools
  können jetzt ihre Backend-Rechte deklarieren
  (`McpToolPermissionProviderInterface::getMcpToolPermissions()`: tool → `{kind:
  dc|module|admin|none, …}`). Der Core-Enforcer wendet dann **dieselbe Parität**
  an wie auf Core-Tools (inkl. Nicht-Admin-Zugriff + Sichtbarkeit in
  `tools/list`). **Ohne** Deklaration bleibt ein Extension-Tool wie bisher
  **admin-only** (sicherer Default). Core gewinnt bei Namenskollision immer.
  Neuer `ExtensionPermissionMap`-Service sammelt die Deklarationen aus den
  `netzhirsch_mcp.tool`-getaggten Services; `ToolPermissionMap` konsultiert ihn
  nach der Core-Konvention.
- **`AbstractMcpTool::permissionGuard()`** exponiert den `McpPermissionGuard`
  an Extension-Tools (`filterReadable`/`mayRead`/`accessiblePageIds`/
  `mayAccessRecord`/`ensureCan`), damit Autoren ihre Listen-Ergebnisse
  paritätisch filtern können. EXTENDING.md §4 „Backend-permission parity"
  dokumentiert beide Hälften (Deklaration gated den Call, Filterung verhindert
  das Leaken von Zeilen).

### Fixed / Changed
- **changelanguage: `page_translations_tree` respektiert jetzt die Pagemounts.**
  Der Übersetzungsbaum filterte bisher **gar nicht** — ein Nicht-Admin sah alle
  Seiten. Jetzt identisch zu `pages_list`/`pages_tree` über
  `accessiblePageIds()`.
- **changelanguage-Schreibtools paritätisch abgesichert.**
  `language_link_pages` (tl_page) und `entity_language_link`
  (tl_page/tl_news/tl_article/tl_calendar_events/tl_faq) lehnen jetzt
  Referenz-Datensätze außerhalb des Zugriffsbereichs des Aufrufers mit
  `permission_denied` ab (neuer `McpPermissionGuard::mayAccessRecord()` —
  Pagemounts für Seiten/Artikel, ReadAction-Voter für die voter-gestützten
  Tabellen). Der tl_page-Voter prüft nur den Seiten-TYP, daher diese explizite
  Scope-Prüfung.

### Notes
- **url-rewrite: bereits paritätisch, kein Zeilen-Filter nötig.** `tl_url_rewrite`
  ist eine flache, global verwaltete Tabelle **ohne Record-Level-ACL** in Contao
  — der einzige Backend-Gate ist das Modul `url_rewrites`
  (`BE_MOD['system']`), das der Enforcer für `url_rewrites_list` bereits beim
  Call prüft. Wer das Modul öffnen darf, sieht im Backend alle Rewrites — es
  gibt nichts pro Zeile zu filtern. Klargestellt im Code + im
  `VOTER_FILTERED_TABLES`-Kommentar; der frühere „Phase-2-TODO"-Eintrag für
  url_rewrite ist damit erledigt.
- **Intern (BC):** `AbstractMcpTool::setMcpToolServices()` hat ein viertes
  `#[Required]`-Argument (`McpPermissionGuard`). Betrifft nur Code, der den
  Setter **manuell** aufruft — bei autowire (Default) passiert das automatisch.

### Verbleibend (Phase 2 — Todo)
- `members_list` / `member_groups_list` (amg), `comments_list`,
  `user_groups_list` — weiterhin nur modul-gated.

## [0.7.0-beta8] – 2026-06-09 — „Objekt-Parameter: Single-Type-Schema (mcp-remote-kompatibel)"

### Fixed
- **Objekt-Parameter (`filters`, `fields`) funktionieren jetzt über die
  `mcp-remote`-Bridge.** Verifizierte Ursache: `mcp-remote` (npx-Bridge, von
  lokalen/Desktop-Connectoren genutzt) **verwirft array-/Union-`type`s**
  (`["null","object"]`) aus den proxied Tool-Schemata; Single-String-`type`s
  (`"integer"`) überleben. Dadurch wurde der Param client-seitig typlos, der
  Objektwert kam beim Server als `unknown` an → `-32602 received unknown`.
  Beleg: nativer HTTP-Connector (lau.netzhirsch.de) behielt `["null","object"]`
  und funktionierte; nur der mcp-remote-Pfad (cwa) verlor den Typ.
- **Fix:** `#[Schema(type: 'object')]` an **allen 44** Objekt-Params (`filters`
  in `*_list`, `fields` in `*_create`/`*_update`, 20 Tool-Dateien). php-mcp
  emittiert damit `"type": "object"` (Single-String) statt der Nullable-Union —
  via SchemaGenerator verifiziert. Single-String übersteht mcp-remote → der
  Client behält den Typ → Objektwerte werden korrekt übertragen.
- Die frühere „`mixed`/PHP-Version"-These ist damit endgültig widerlegt: der
  Server emittierte korrekt, der Verlust passierte in der Bridge.

> **Nach Deploy:** Bundle aktualisieren → `cache:clear` → **Connector
> reconnecten** (Client muss das neue Schema laden). Test:
> `themes_list(filters: {"name":"x"})` und
> `image_size_create(theme_id: 1, name: "x", fields: {"width": 1200})` müssen
> ohne `-32602` durchlaufen.

## [0.7.0-beta7] – 2026-06-09 — „Listen-Parität: Pagemounts (Seiten/Artikel)"

### Added
- **`pages_list`, `pages_tree`, `articles_list` respektieren jetzt die
  Pagemounts** des Users (rekursiv). Neuer `McpPermissionGuard::accessiblePageIds()`
  ermittelt die (gruppen-gemergten) Pagemounts + alle Unterseiten (via Contaos
  `Database::getChildRecords`); die Tools filtern Ergebnis-Zeilen bzw. Baum-Knoten
  auf diese Menge. Admins/Trusted-Mode = unbeschränkt. (tl_page/tl_article haben
  keinen pagemount-basierten Read-Voter — daher dieser gezielte Scope-Filter
  statt `filterReadable()`.)
- Hinweis `pages_tree`: Pagemounts **mitten** im Baum (deren Root-Vorfahre nicht
  zugänglich ist) erscheinen bei `root_id=0` nicht — dann `root_id=<Mount-id>`
  übergeben. Inzugängliche Seiten werden **nie** geleakt.

### Verbleibend (Phase 2 — Todo)
- `members_list` / `member_groups_list` (amg), `comments_list`,
  `url_rewrites_list`, `user_groups_list` — weiterhin nur modul-gated.

## [0.7.0-beta6] – 2026-06-09 — „Listen-Parität: alle voter-gestützten Listen"

### Added
- **Ergebnis-Filterung (s. beta5) jetzt auf ALLEN voter-gestützten List-Tools.**
  Ergänzt um `newsletters_list`, `newsletter_channels_list`,
  `newsletter_recipients_list`, `image_sizes_list`, `image_size_items_list`,
  `layouts_list`, `modules_list`, `users_list`. Damit sind **alle Tabellen mit
  Contao-`ReadAction`-Voter** abgedeckt (17 Tabellen / 17 List-Tools).

### Verbleibend (Phase 2 — kein Contao-Read-Voter → gezielter Query-Filter)
- `members_list` / `member_groups_list` (amg), `articles_list`, `pages_list`
  (rekursive Pagemounts), `comments_list`, `url_rewrites_list`,
  `user_groups_list` — weiterhin nur modul-gated (Listen-*Ergebnis* auf
  Multi-Scope-Sites evtl. zu breit; Einzel-Record bleibt korrekt gegated).

## [0.7.0-beta5] – 2026-06-09 — „Listen-Parität: Ergebnis-Filter (Phase 1)"

### Added
- **List-Ergebnisse werden auf die zugänglichen Datensätze gefiltert** (echte
  Listen-Parität) — über Contaos eigene `ReadAction`-Voter, gekapselt in
  `McpPermissionGuard::filterReadable()` / `mayRead()`. Ein Redakteur sieht via
  `*_list` nur noch Records, die er auch im Backend sehen darf (z. B. nur seine
  erlaubten News-Archive), statt aller. **No-op** für Admins/Trusted-Mode und
  für Tabellen ohne Read-Voter (siehe Phase 2). Integriert in: `news_list`,
  `news_archives_list`, `calendar_events_list`, `calendars_list`, `faqs_list`,
  `faq_categories_list`, `content_list`, `forms_list`, `form_fields_list`.

### Bekannte Grenzen / Folge-Increments
- **Mechanik vorhanden, Integration folgt:** `newsletters_list` /
  `newsletter_channels_list` / `newsletter_recipients_list`, `image_sizes_list` /
  `image_size_items_list`, `layouts_list`, `modules_list`, `users_list`
  (überwiegend Themes-/User-Modul → ohnehin modul-gated, per-Record-Scope selten
  relevant).
- **Phase 2 (kein Read-Voter in Contao → gezielter Query-Filter nötig):**
  `members_list`/`member_groups_list` (amg), `articles_list` + `pages_list`
  (rekursive Pagemounts), `comments_list`, `url_rewrites_list` — bleiben vorerst
  nur modul-gated (auf Multi-Scope-Sites Über-Anzeige im Listen-*Ergebnis*
  möglich; der Zugriff auf den *einzelnen* Datensatz bleibt korrekt gegated).
- **Pagination:** gefiltert wird die geholte Seite → das Ergebnis kann < `limit`
  enthalten, auch wenn weiter hinten zugängliche Records lägen.

## [0.7.0-beta4] – 2026-06-09 — „List-Reads über Modul-Gate"

### Fixed
- **List-/Tabellen-Reads (`*_list`, `*_tree`) wurden für Nicht-Admins fälschlich
  verweigert.** Ein Read ohne konkrete Record-`id` baute eine `ReadAction` ohne
  Datensatz → parent-bezogene Contao-Voter (z. B. `NewsAccessVoter`) prüften ein
  **Phantom-Eltern-Archiv (pid 0)** und verweigerten. Folge: ein News-Redakteur
  konnte ein einzelnes Item lesen (`news_get`), aber `news_list` /
  `news_archives_list` **nicht**. Fix: record-lose Reads werden über
  **Modul-Zugriff** gestellt (wie im Backend — wer das Modul öffnen darf, sieht
  die Liste); der Record-Voter greift nur noch bei konkreten Datensätzen
  (`news_get`/`_update`/`_delete` mit `id`).
- **`contao:mcp:permission-debug` probt List-/Tree-Tools jetzt record-los**
  (table-level) statt mit Sample-`id`. Die beta3-Sample-`id` hatte genau diesen
  Bug maskiert (zeigte `news_list … allowed (probed row #1)`, obwohl der echte
  record-lose Call `denied` lieferte).

## [0.7.0-beta3] – 2026-06-09 — „Permission-Map: Plural-Listen-Fix"

### Fixed
- **Plural `_list`-Tools wurden in der Permission-Map falsch aufgelöst** —
  untergrub die Rechte-Parität. Namen wie `articles_list`, `members_list`,
  `comments_list`, `themes_list`, `layouts_list`, `modules_list`, `forms_list`,
  `newsletters_list`, `url_rewrites_list`, `image_sizes_list`, `calendars_list`,
  `faqs_list` matchten die singulären Entity-Prefixe nicht und fielen auf
  **admin-only** → Nicht-Admins konnten sie trotz Backend-Rechten nicht nutzen.
  Andere lösten auf die **falsche Eltern-Tabelle** auf (z. B. `member_groups_list`
  → `tl_member` statt `tl_member_group`, `news_archives_list` → `tl_news` statt
  `tl_news_archive`, `calendar_events_list` → `tl_calendar`, `faq_categories_list`
  → `tl_faq`, `newsletter_channels_list`/`newsletter_recipients_list` →
  `tl_newsletter`, `image_size_items_list` → `tl_image_size`) → der falsche Voter
  entschied. Neuer expliziter `LIST_TABLES`-Mapping korrigiert alle 21
  Listen-Tools; Regressionstest (`ToolPermissionMapTest`) ergänzt.

## [0.7.0-beta2] – 2026-06-09 — „Disabled-Sichtbarkeit (alle Entitäten) + klares not_found"

> **Verhaltensänderung:** `*_list`/`*_get` zeigen jetzt **standardmäßig auch
> deaktivierte/unveröffentlichte Elemente** (News, Articles, Calendar-Events,
> FAQ, Pages, Content). Wer nur Veröffentlichtes will, übergibt
> `include_unpublished=false` (bzw. `include_invisible=false` bei `content_list`).
> Außerdem liefern Record-Operationen auf einen **nicht existierenden Datensatz**
> jetzt `{"error":"not_found"}` statt eines irreführenden `permission_denied`.

### Changed
- **MCP zeigt deaktivierte Elemente jetzt grundsätzlich — für ALLE
  vergleichbaren Entitäten.** Die Default-Filter „nur öffentlich/aktiv" sind
  abgeschaltet; eine Einschränkung bleibt pro Call per Parameter möglich:
    - `include_unpublished` → Default `true`: News, Article, CalendarEvent, FAQ
      (jeweils list **und** get), Page (list) — filtert sonst `published` +
      Start/Stop-Fenster.
    - `include_invisible` → Default `true`: `content_list` (`tl_content.invisible`).
    - `include_inactive` → Default `true`: `members_list`, `member_groups_list`
      (`tl_member(_group).disable`).
    - `include_disabled` → Default `true`: `users_list`, `user_groups_list`
      (`tl_user(_group).disable`).
  Bereits vorher „alles sichtbar" (kein Default-Filter): `form_fields_list`
  (`include_inactive`), `newsletter_recipients_list` (`active_only=false`),
  `comments_list` (`unpublished_only=false`). Tool-Beschreibungen angepasst.
  Begründung: über MCP soll der gesamte (auch deaktivierte) Stand sichtbar sein.

### Fixed
- **`not_found` statt `permission_denied` bei fehlenden Datensätzen.**
  `McpPermissionGuard::ensureCan` prüft bei read/update/delete mit konkreter
  `id` zuerst die Existenz (nach dem Modul-Gate, damit kein Existenz-Leak an
  Nutzer ohne Modulzugriff) und gibt `{"error":"not_found"}` zurück. Vorher
  verweigerte Contaos Voter den fehlenden Record mit „insufficient backend
  permissions" — was wie ein Rechteproblem aussah. Gilt zentral für **alle**
  dc-gemappten Entitäten.
- **`contao:mcp:permission-debug` prüft jetzt record-genau.** Read-/Delete-Proben
  laufen mit einer echten Row-ID (`SELECT id … LIMIT 1`), sodass der
  Row-/Parent-Voter (z. B. News-Archiv-Zugriff) tatsächlich greift. Vorher
  prüfte der Befehl **ohne** `id` → der Archiv-Check wurde übersprungen und das
  Tool zeigte fälschlich „allowed", obwohl ein konkreter Record-Read scheiterte.

### Hinweis zur News-/Archiv-Parität
Contaos `NewsAccessVoter` verlangt für **jede** Aktion auf `tl_news` — auch
**read** — Edit-Zugriff auf das Eltern-Archiv (`USER_CAN_EDIT_ARCHIVE`). Ein
News-Redakteur kann ein Item also nur lesen/öffnen, wenn sein Account/Gruppe das
betreffende Archiv unter „erlaubte News-Archive" gesetzt hat. Das ist korrekte
Parität (im Backend gilt dasselbe) — `permission-debug` macht das jetzt sichtbar.

## [0.6.1-beta1] – 2026-06-09

### Fixed — Leere Objekt-Parameter (`fields: {}`)

- **`ObjectAwareSchemaValidator`** — neuer DI-Override für php-mcp/servers
  `SchemaValidator`, eingesetzt vom `HttpDispatcherFactory` (per Reflection,
  **kein Vendor-Patch**). Behebt eine Falsch-Ablehnung **leerer** Objekt-Params
  (`fields: {}`, `args: {}`, `filters: {}`): Da der JSON-RPC-Body assoziativ
  dekodiert wird (`json_decode(..., true)`), kommt ein leeres `{}` als leeres
  Array `[]` an, das opis/json-schema nicht als `object` klassifizieren kann →
  `Invalid type. Expected null|object, but received unknown`. Der Validator
  stellt anhand des Schemas die Objekt-Absicht wieder her (leeres `[]` → `{}`),
  wenn der Param `object` erlaubt und **nicht** `array` — Listen-Params und
  nicht-leere Objekte bleiben unangetastet, falsche Typen weiter abgelehnt.
- Hinweis: **Nicht-leere** Objekt-Params (`fields:{"width":1200}`) waren von
  diesem Bug nie betroffen — die in Skill-Reports beobachteten Fehlschläge
  gegen einen Host stammten von einem **veralteten clientseitigen
  `tools/list`-Cache** (typloser `mixed`-Stand vor dem `#[Schema]`-Override aus
  0.1) und werden durch einen **Reconnect des MCP-Connectors** behoben, nicht
  durch Bundle-Code.

## [0.6.0-beta1] – 2026-06-09 — „Permission Parity"

> **Upgrade-Hinweis (Verhaltensänderung):** Unter `auth_mode=oauth` gelten ab
> dieser Version pro MCP-Nutzer die echten Contao-Backend-Rechte, und ein
> Nicht-Admin braucht zusätzlich das neue Häkchen **„MCP-Server-Zugriff
> erlauben"** (`netzhirschMcpAccess`, Default AUS) an seinem Account oder einer
> Gruppe. Nach dem Update sind Nicht-Admins also zunächst vom MCP-Server
> ausgesperrt, bis du das Häkchen setzt — Admins sind unverändert erlaubt.
> `auth_mode=none` (Loopback/Dev) bleibt unberührt. `contao:migrate` ausführen
> (legt die `netzhirschMcpAccess`-Spalte an), danach `cache:clear`.
> Diagnose pro User: `contao:mcp:permission-debug <username>`.

### Added — Backend-Rechte-Parität

OAuth-authentifizierte MCP-Nutzer dürfen über MCP jetzt nur noch das, was ihr
Contao-Backend-Account auch darf — durchgesetzt über Contaos **eigene
Security-Voter**, nicht über eine Nachbau-Logik.

- **`netzhirschMcpAccess`** — neue Checkbox in `tl_user` **und**
  `tl_user_group` (DCA + Migration, TINYINT default 0). Coarse Gate: ein
  Nicht-Admin darf den MCP-Server nur nutzen, wenn das Häkchen an seinem
  Account ODER einer seiner Gruppen gesetzt ist. **Secure-by-default**: nach
  der Migration sind Nicht-Admins gesperrt, bis explizit freigeschaltet.
  Admins sind immer erlaubt.
- **`BackendUserContext`** lädt den BackendUser für die OAuth-`tl_user.id`
  über den Contao-Backend-User-Provider (inkl. Gruppen-Merge via
  `setUserFromDb`) und baut einen Security-Token (`ROLE_ADMIN` ⇒ Admin-Bypass).
- **`McpPermissionGuard`** fragt pro Operation Contaos Voter:
  Modul-Zugriff (`contao_user.modules`) + `contao_dc.<table>` mit
  Create/Read/Update/DeleteAction (kapselt Row-Level wie Pagemounts/
  Filemounts) + `contao_user.alexf` für Feld-Level-Edits. Denials kommen als
  strukturierter `permission_denied`/`mcp_access_denied`-Fehler zurück.
- **`ToolPermissionMap` + `McpPermissionEnforcer`** mappen alle ~144 Tools auf
  ihre Permission (Konvention `entity_verb` → Tabelle/Operation + explizite
  Spezialfälle; unbekannte Tools = admin-only). Enforcement an ZWEI Stellen,
  damit es keinen Bypass gibt: `McpController` (direkte `tools/call`) **und**
  `contao_call` (Proxy auf versteckte Tools).
- **Sichtbarkeit gefiltert**: Tools, die ein User nicht nutzen darf, erscheinen
  gar nicht erst im Katalog — `tools/list` (über `ToolFilter::isExposed`),
  `contao_search_tools` und `contao_describe_tool` rufen
  `McpPermissionEnforcer::isToolVisible()` (coarse, Modul-/Admin-Ebene). Ein
  versteckter Tool antwortet bei `describe` wie ein nicht existierender (kein
  Capability-Disclosure). Row-/Field-Level bleibt zur Aufruf-Zeit gated (zur
  List-Zeit gibt es keine Datensatz-ID).
- **Trusted Mode**: bei `auth_mode=none` (kein User, Loopback/Dev) sind alle
  Checks No-ops — das Per-User-Modell gilt nur unter `auth_mode=oauth`.
- **`netzhirschMcpAccess` folgt Contaos `inherit`-Modus**: das eigene Häkchen
  erscheint nur in den Paletten „Gruppenrechte erweitern"/„Eigene Rechte",
  nicht unter „Nur Gruppenrechte verwenden" — und wird dort auch zur Laufzeit
  ignoriert (bei `inherit=group` zählt nur das Gruppen-Flag; bei `extend`
  beides; bei `custom` nur das eigene). Konsistent zu jedem anderen
  eigenen Rechte-Feld in Contao.

Verifikation: +26 PHPUnit (ToolPermissionMap, 66 gesamt) + 7 Live-Asserts im
Smoke-Test gegen einen temporären Nicht-Admin-User (Admin-Bypass, Coarse-Gate
an/aus, Modul-/DC-Deny, Trusted-Mode) — 210/210 grün, PHPStan 0.

> **API-Freeze-Hinweis**: `netzhirschMcpAccess` (DB-Spalte) gehört ab v1.0 zur
> eingefrorenen Oberfläche.

## [0.5.0-beta1] – 2026-06-03 — „Tool Extension Point"

### Added — Tool-Extension-Point

Andere Contao-Bundles können dem MCP-Server jetzt eigene Tools beisteuern.
Vollständige Anleitung: [EXTENDING.md](EXTENDING.md).

- **`McpToolProviderInterface`** (Marker) + **`AbstractMcpTool`** (Basis-
  Klasse). Dritt-Tools implementieren `#[McpTool]`-Methoden exakt wie die
  Core-Tools. Die Basis liefert per `#[Required]`-Injection: `callContext()`,
  `authorResolver()` / `resolveAuthorId()` (Attribution → tl_log + tl_version),
  `withDeadlockRetry()` und den `requireConfirmation()`-Gate.
- **Autoconfiguration**: Services, die das Interface implementieren, werden
  automatisch mit `netzhirsch_mcp.tool` getaggt. Manuelles Taggen ebenfalls
  möglich.
- **`McpToolProviderPass`** (Compiler-Pass) sammelt getaggte Services, macht
  sie public (php-mcp resolved Handler via Container-FQCN) und reicht die
  Klassen an die Factory.
- **`ExtensionToolRegistrar`** registriert die Tools nach der Core-Discovery
  in die Registry — wiederverwendet php-mcps eigene Schema-Generierung.

#### Sicherheitsmechanismen

- **Default aus**: Ein Extension-Tool ist NIE aufrufbar, bis sein Name in der
  Allowlist `extension_tools_enabled` (var/mcp/config.json) steht. Ein
  `composer require` erweitert die LLM-Angriffsfläche nicht von selbst.
  Verfügbare-aber-deaktivierte Tools werden auf info-Level geloggt.
- **Core gewinnt Kollisionen**: Ein Extension-Tool mit Core-Namen (oder dem
  Namen eines früher registrierten Extension-Tools) wird verworfen. Core-Tools
  können nicht überschrieben/gekapert werden.
- **Gleiche Auth + Rate-Limit** wie Core-Tools (OAuth-Bearer, 600/min/Client).
- 7. Config-Key `extension_tools_enabled` (list<string>, default `[]`); vom
  Backend-Config-Save durchgereicht, damit ein Speichern ihn nicht wischt.

#### Tests

12 neue Unit-Tests (ExtensionToolGate-Matrix, AbstractMcpTool-Helpers,
ExtensionToolRegistrar gegen echte php-mcp-Registry, McpToolProviderPass).
PHPUnit 40/40, PHPStan 0 errors, Smoke 203/203.

> **API-Freeze-Hinweis**: Interface, Tag-Name, Base-Class und der
> `extension_tools_enabled`-Key gehören ab v1.0.0 zur eingefrorenen
> öffentlichen Oberfläche.

## [0.4.0-beta2] – 2026-06-01 — „Pre-1.0 Polish"

Fünf Tickets aus dem Path-to-v1.0-Audit abgearbeitet. Keine Breaking-
Changes außer dem dokumentierten `external_id_list` → `external_ids_list`
Rename. Diese Beta ist der Burn-in-Kandidat für marli; bei zwei sauberen
Wochen folgt v0.4.0 final, dann v1.0.0.

### Changed

- **Naming-Konsistenz**: `external_id_list` → `external_ids_list`. Alle
  anderen Listen-Tools (`pages_list`, `members_list`, …) nutzen Plural;
  dieses Tool stach raus. Last-Chance-Fix vor v1.0-API-Freeze.

### Added

- **`/mcp/healthz` Liveness-Probe** (Ticket #93). Auth-freier
  HTTP-Endpoint für externes Monitoring (Plesk, UptimeRobot, Prometheus,
  k8s). 4 Checks: DB-Ping, var/mcp-Writable, OAuth-Keys, Disk-Free.
  Antwort < 100ms, 200 wenn ok, 503 mit JSON-Details wenn degraded.
- **PHPUnit-Test-Suite** (Tickets #90/#91). 21 isolierte Tests für
  OAuth-Crypto (KeyManager-Lifecycle + Dual-Key-Roundtrip via
  lcobucci/jwt) und IAT-Manager (sqlite-in-memory). Eigener CI-Job
  parallel zu PHPStan.
- **HMAC-gepfefferter IAT-Hash** (Ticket #91). `tl_mcp_oauth_iat`
  speichert jetzt `hash_hmac('sha256', $plain, %kernel.secret%)` statt
  Plain-SHA256. Schutz gegen Rainbow-Table-Attacks bei DB-Leak.

### API Surface v1.0 (frozen)

Hier wird festgehalten, was nach v1.0.0 nicht mehr brechen darf.
Änderungen erfordern entweder einen Major-Bump (v2.0) ODER eine
Übergangsperiode mit Deprecation-Warnings + Alias.

**144 Tools** in 8 logischen Gruppen — vollständige Liste:

| Gruppe | Tools |
|---|---|
| **Discovery** | `contao_search_tools`, `contao_describe_tool`, `contao_call` |
| **System** | `ping`, `contao_version`, `server_info`, `installed_bundles`, `system_settings`, `system_settings_update`, `insert_tags_list`, `system_health_check`, `entity_query_options` |
| **Pages** | `pages_list`, `pages_tree`, `page_get`, `page_create`, `page_update`, `page_delete`, `page_url`, `page_preview`, `language_link_pages`, `page_translations_tree` |
| **Content (Articles/Modules/Layouts/Themes)** | `articles_list`, `article_get`, `article_create`, `article_update`, `article_delete`, `content_list`, `content_get`, `content_create`, `content_update`, `content_delete`, `content_types_list`, `content_palette_get`, `modules_list`, `module_get`, `module_create`, `module_update`, `module_delete`, `module_types_list`, `module_palette_get`, `layouts_list`, `layout_get`, `layout_create`, `layout_update`, `layout_delete`, `themes_list`, `theme_get`, `theme_create`, `theme_update`, `theme_delete`, `image_sizes_list`, `image_size_get`, `image_size_create`, `image_size_update`, `image_size_delete`, `image_size_items_list`, `image_size_item_get`, `image_size_item_create`, `image_size_item_update`, `image_size_item_delete`, `image_size_options_list` |
| **Templates** | `templates_list`, `template_get`, `template_create`, `template_update`, `template_delete`, `template_rename`, `template_overrides_list`, `template_lookup`, `template_dependencies` |
| **Files** | `files_list`, `files_search`, `file_get`, `file_upload`, `file_update_meta`, `file_delete`, `file_rename`, `file_move`, `folder_create`, `folder_delete` |
| **News / Calendar / FAQ / Forms** | `news_list`, `news_get`, `news_create`, `news_update`, `news_delete`, `news_archives_list`, `news_archive_get`, `news_archive_create`, `news_archive_update`, `news_archive_delete`, `calendars_list`, `calendar_get`, `calendar_create`, `calendar_update`, `calendar_delete`, `calendar_events_list`, `calendar_event_get`, `calendar_event_create`, `calendar_event_update`, `calendar_event_delete`, `faqs_list`, `faq_get`, `faq_create`, `faq_update`, `faq_delete`, `faq_categories_list`, `faq_category_get`, `faq_category_create`, `faq_category_update`, `faq_category_delete`, `forms_list`, `form_get`, `form_create`, `form_update`, `form_delete`, `form_fields_list`, `form_field_get`, `form_field_create`, `form_field_update`, `form_field_delete`, `form_field_types_list`, `form_field_palette_get` |
| **Members / Users** | `members_list`, `member_get`, `member_create`, `member_update`, `member_delete`, `member_groups_list`, `member_group_get`, `member_group_create`, `member_group_update`, `member_group_delete`, `users_list`, `user_groups_list` |
| **Extensions (Comments / Newsletter / URL-Rewrite)** | `comments_list`, `comment_get`, `comment_create`, `comment_update`, `comment_delete`, `newsletters_list`, `newsletter_get`, `newsletter_create`, `newsletter_update`, `newsletter_delete`, `newsletter_channels_list`, `newsletter_channel_get`, `newsletter_channel_create`, `newsletter_channel_update`, `newsletter_channel_delete`, `newsletter_recipients_list`, `newsletter_recipient_create`, `newsletter_recipient_update`, `newsletter_recipient_delete`, `url_rewrites_list`, `url_rewrite_get`, `url_rewrite_create`, `url_rewrite_update`, `url_rewrite_delete` |
| **Cross-cutting** | `external_id_set`, `external_id_lookup`, `external_id_unset`, `external_ids_list`, `entity_move`, `entity_language_link`, `dbafs_sync`, `maintenance_jobs_list`, `maintenance_run`, `page_cache_invalidate` |

**Naming-Konventionen** (gefroren):
- `<entity>_list` (singular) für uncountable nouns: `news`, `content`
- `<entity>s_list` (plural) für countable: `pages`, `members`, `files`, …
- `<entity>_get`/`_create`/`_update`/`_delete` für CRUD
- `<entity>_<noun>_list` für Subset-Listings: `content_types_list`,
  `image_size_options_list`
- `<entity>_palette_get` für DCA-Palette-Introspection
- `contao_<verb>` für Discovery-Meta-Tools
- `entity_<verb>` für tabellen-generische Operations

**Tool-Response-Shapes** (gefroren):
- Update-Tools: `{updated: true, applied: int, changed_fields: list<string>}`.
  `applied` und `count(changed_fields)` sind redundant — `applied` bleibt
  als bequemerer "wie viele Felder hat sich geändert?" Quick-Indicator;
  `changed_fields` ist die kanonische Quelle.
- Create-Tools: `{created: int, <entity_alias>?: array}` mit dem
  Entity-Snapshot.
- Delete-Tools: `{deleted: true, id: int}` oder Fehler mit
  `confirm_destructive`-Gate.
- List-Tools: `{items: list, total: int, limit: int, offset: int}` oder
  Subset-Metadaten-Struktur.

**Config-Schlüssel** in `var/mcp/config.json` (gefroren, 6 Keys):
`path`, `pagination_limit`, `auth_mode`, `backend_url`,
`oauth_registration_mode`, `lazy_mode`.

**Datenbank-Schema** (gefroren):
- `tl_mcp_oauth_client`, `tl_mcp_oauth_access_token`,
  `tl_mcp_oauth_refresh_token`, `tl_mcp_oauth_auth_code`,
  `tl_mcp_oauth_iat`
- `external_id_namespace`, `external_id_key`-Spalten auf 23 Contao-
  Entity-Tabellen (siehe `ExternalIdDcaInjector::SUPPORTED_TABLES`)

**HTTP-Routen** (gefroren):
- `POST /mcp` — JSON-RPC Tool-Endpoint (OAuth-gated wenn `auth_mode=oauth`)
- `GET /mcp/healthz` — Liveness-Probe (auth-frei)
- `GET /mcp/.well-known/oauth-authorization-server` — RFC 8414
- `GET /.well-known/oauth-authorization-server` — RFC 8414 root-path
- `POST /_mcp_oauth/register` — DCR
- `GET|POST /_mcp_oauth/authorize` — Auth-Code-Issue
- `POST /_mcp_oauth/token` — Token-Exchange + Refresh

**Service-IDs** (gefroren — Konsumenten dürfen sich darauf verlassen):
- `netzhirsch.mcp.tool_filter` (Lazy-Mode-Hook)
- `netzhirsch.mcp.post_call_hook` (Model-Registry-Reset)
- Public Services: `McpServerConfigStorage`, `McpActivityLog`,
  `McpCallContext`, `OAuthClientAdministration`,
  `InitialAccessTokenManager`, alle Tool-Klassen.

**Was NICHT eingefroren wird** (v1.x kann sich ändern):
- Interne Helper-Klassen (`UpdateDiff`, `QueryFilterResolver`,
  `AuthorResolver`, `DbalRetry`) — explicit private API.
- Backend-Template (`be_mcp_server.html5`) — kein API-Vertrag.
- Vendor-Patches an `php-mcp/server` — abhängig von Upstream-Roadmap.

## [0.4.0-beta1] – 2026-06-01 — „Production Resilience Bundle"

Vier zusammengehörige Härtungen, die das Bundle bereit für Produktiv-
Betrieb mit Plesk + Cron + nicht-trivialen User-Last machen. Keine
breaking changes ggü. 0.3.0-beta5 — alle neuen Defaults sind
rückwärtskompatibel.

### Added

- **OAuth: RSA-Key-Rotation mit Grace-Window** (Ticket #86). Neuer
  CLI-Befehl `contao:mcp:oauth:rotate-keys` (`--max-age`, `--prune-old`,
  `--force`, `--dry-run`). Während eines Rotation-Fensters validiert
  der Resource-Server gegen den aktuellen UND den vorherigen Public-Key
  — bestehende Sessions bleiben gültig bis zum natürlichen
  Access-Token-Expiry. Cron-Empfehlung: monatlich.
- **OAuth: Per-Client Rate-Limiting** (Ticket #87). 600 Aufrufe/Minute
  pro `client_id` am `/mcp`-Endpunkt (Sliding-Window). Pro-IP-Limits an
  den OAuth-Endpoints bestehen weiter. Bei Überlauf: HTTP 429 mit
  `Retry-After`-Header und strukturierter JSON-Antwort.
- **Backend: MCP-Activity-Panel** (Ticket #88). Neuer einklappbarer
  Bereich im Backend-Modul „System → MCP-Server" zeigt die letzten 100
  Einträge aus `tl_log` mit `source LIKE 'mcp%'` — getrennte Anzeige
  für anonyme (`mcp`) und OAuth-attribuierte (`mcp_oauth`) Aktionen.
- **CI-Hardening**: PHPStan Level 4 (eigene Konfig, contao-erweiterungs-
  freundliche Ignore-Regeln) + `composer audit` als CI-Schritt + Composer-
  Cache (Ticket #84). GitHub Actions Matrix PHP 8.3/8.4 grün.
- **DB-Deadlock-Retry-Wrapper** (Ticket #85). Neues `DbalRetry`-Service
  mit `transactional()` + `withRetry()` (Backoff 50ms→200ms→500ms +
  Jitter). 13 Tools nutzen das Wrapper-Pattern — Cascade-Deletes und
  Sortier-/Move-Operations sind jetzt sauber gegen MariaDB-Deadlocks
  abgesichert.

### Changed

- Smoke-Test wuchs auf **197 Asserts** (von 181) — neue Coverage für
  Key-Rotation, Rate-Limit-Wiring, Activity-Log-Filter.
- `KeyManager`: `rotate()` setzt nach `rename()` die mtime auf
  `previous_*.pem` zurück, damit Prune-Schwellen die ROTATION-Zeit
  messen, nicht die Content-Creation-Zeit (Windows-beobachteter Bug,
  hätte beim zweiten Rotate ohne Prune übergeleckt).
- README-Badge: `0.3.0-beta5` → `0.4.0-beta1`.

### Operations

- Cron-Empfehlung: zusätzlich zu `contao:mcp:oauth:cleanup` (täglich)
  monatlich `contao:mcp:oauth:rotate-keys --quiet`.
- Rate-Limit-Default 600/min/`client_id` ist ein Backstop gegen
  Runaway-Loops, nicht ein Workflow-Throttle. Gut funktionierende
  AI-Agents bleiben weit darunter. Anpassbar in `config/services.yaml`.

## [0.3.0-beta5] – 2026-05-26

Pre-1.0-Ticket #5 erledigt: alle 26 `*_delete`-Tools haben jetzt ein
einheitliches `confirm_destructive`-Gate. Hard breaking change — `force`
gibt es nicht mehr.

### Breaking

Vorher mischten sich zwei Patterns:
- Cascadable Tools: `delete(id, force=false)` — `force` doppelt überladen
  als „kaskadiere durch Children" UND „bestätige destruktive Aktion"
- Leaf Tools: `delete(id)` — kein Schutz gegen LLM-Halluzinations-Deletes

Jetzt sauber getrennt:

**Cascadable Tools** — `delete(id, confirm_destructive=false, cascade=false)`:
- `theme_delete`, `page_delete`, `layout_delete`, `calendar_delete`,
  `news_archive_delete`, `faq_category_delete`, `form_delete`,
  `newsletter_channel_delete`, `image_size_delete`, `folder_delete`

**Leaf Tools** — `delete(id, confirm_destructive=false)`:
- `news_delete`, `article_delete`, `content_delete`, `calendar_event_delete`,
  `faq_delete`, `member_delete`, `member_group_delete`, `module_delete`,
  `form_field_delete`, `comment_delete`, `url_rewrite_delete`,
  `newsletter_delete`, `newsletter_recipient_delete`,
  `image_size_item_delete`, `template_delete`, `file_delete`

**Default ist immer `false`** → ohne explizites `confirm_destructive=true`
kommt Error `destructive_confirmation_required` zurück. Schutz gegen
LLM-Halluzinations-Deletes.

**`cascade`** ist semantisch entkoppelt: bestätigt „auch Children löschen"
und ist NICHT alias für confirm_destructive.

**`force` ist weg.** Aufrufer-Migration: `force=true` →
`confirm_destructive=true, cascade=true` (für Cascadable Tools) bzw.
`confirm_destructive=true` (für Leaf Tools).

### Smoke-Test

181/181 grün (vorher 163, plus 18 neue Asserts):
- Pro Tool ein „rejects missing confirm_destructive"-Test
- Für UrlRewrite/Comment/Newsletter ein Extension-Available-Guard
- Bestehende Cleanup-Loops umgestellt auf neue Signaturen

### README

Letzte Beta-Einschränkung gestrichen. Liste ist jetzt leer.

## [0.3.0-beta4] – 2026-05-26

Pre-1.0-Ticket #4 erledigt: alle Multi-Step-Cascade-Deletes laufen jetzt
unter DBAL-Transaktion. Beim ersten Audit hatte das Ticket nur 3 Tools
gelistet (Theme/Form/Newsletter-Channel); beim Implementieren tauchten
weitere 9 mit identischem Atomicity-Problem auf — alle gefixt.

### Fixed

Cascade-Deletes hatten ohne Transaction-Wrapper folgendes Risiko: bei
DB-Fehler mitten in der Sequenz (Deadlock, Connection-Drop, Constraint-
Konflikt) bleibt die DB in inkonsistentem Zustand zurück — Parent gelöscht
aber Children verwaist, oder Children gelöscht aber Parent noch da. Der
LLM kann sich davon nicht erholen.

Folgende 12 Delete-Tools sind jetzt atomar:

- **`theme_delete(cascade=true)`** — tl_image_size_item → tl_image_size →
  tl_module → tl_layout → tl_content (Legacy) → tl_theme
- **`layout_delete(cascade=true)`** — `tl_page.layout/.subpageLayout`
  resets + `$layout->delete()`
- **`form_delete(cascade=true)`** — tl_form_field → tl_form
- **`newsletter_channel_delete(cascade=true)`** — tl_newsletter +
  tl_newsletter_recipients → tl_newsletter_channel
- **`calendar_delete(cascade=true)`** — tl_content (per Event) → tl_calendar_events → tl_calendar
- **`calendar_event_delete()`** — tl_content → tl_calendar_events
- **`article_delete()`** — tl_content → tl_article
- **`faq_category_delete(cascade=true)`** — tl_content (per FAQ) → tl_faq → tl_faq_category
- **`faq_delete()`** — tl_content → tl_faq
- **`page_delete(cascade=true)`** — tl_content → tl_article → jumpTo-Resets → tl_page
- **`news_archive_delete(cascade=true)`** — tl_content (per News) → tl_news → tl_news_archive
- **`news_delete()`** — tl_content → tl_news
- **`image_size_delete(cascade=true)`** — tl_image_size_item → tl_image_size

Pattern überall identisch: `$this->connection->transactional(function ()
use (...) { ... cascade ... $parent->delete(); });`. Logging,
Message-Output und `Versions`-Snapshots bleiben außerhalb der Transaktion.

### Added

- **`Connection`-Service-Dependency** in 7 Tools die ihn noch nicht
  injected hatten: Calendar, CalendarEvent, Article, FaqCategory, Faq,
  NewsArchive, News. Symfony-Autowire pickt das ohne services.yaml-Change
  auf.

### Smoke-Test

163/163 grün. Die existierenden Cascade-Tests (Theme mit Children, dann
`force=true` Delete) exercisen den Success-Path durch die Transaktion
implicit. Ein Mid-Cascade-Fail-Mock-Test wurde nicht hinzugefügt — DBAL's
`Connection::transactional` ist gut getestet, ein eigener Mock-Test wäre
brittle.

## [0.3.0-beta3] – 2026-05-26

Pre-1.0-Ticket #3 erledigt: alle 15 Update-Tools liefern jetzt einheitlich
`updated: bool` + `changed_fields: list<string>` + `applied: int`.
No-op-Calls sind kein Fehler mehr.

### Changed (Response-Shape, leicht Breaking)

Alle Update-Tools haben eine harmonisierte Erfolgs-Response:
```
{updated: true, id, changed_fields: [...], applied: N, ...serializer}
```

Bei No-op (alle übergebenen Werte matchen den DB-Stand):
```
{updated: false, id, changed_fields: [], applied: 0, ...serializer}
```
Vorher hatten viele Tools `{error: "no_changes"}` zurückgegeben — das war
für idempotente Pipeline-Re-runs unfreundlich. Jetzt: `updated: false`
heißt „Aufruf war idempotent, alles ok, nichts zu tun".

Bei rein unbekannten Input-Keys (Caller-Fehler, kein No-op):
```
{error: "no_mappable_fields", submitted_keys: [...]}
```
Vorher hieß dieser Fehler bei Option-A-Tools `"no_changes"` — irreführend
weil es nicht um „Werte gleich" geht. Jetzt ein eigener Error-Code.

### Tools betroffen (15 Update-Tools)

- **Option-B (echte Change-Detection im FieldMapper, hatten schon `changed_fields`):**
  Article, Calendar, CalendarEvent, Content, Faq, FaqCategory, FormField,
  Module, News, NewsArchive, Page, UrlRewrite — diese erhalten zusätzlich
  `applied: count($changed_fields)` und ihr `no_changes`-Error wurde zum
  No-op-Success umgebaut.
- **Option-A (Counter-only-FieldMapper, brauchten echte Diff-Detection):**
  Theme, Layout, Member, ImageSize, ImageSize-Item, Form — diese bekommen
  einen Snapshot-vor-applyFields + Diff-danach via neuem
  `Netzhirsch\ContaoMcpBundle\Service\UpdateDiff`-Helper. Public→Column-
  Mapping pro Tool (jump_to → jumpTo, active → disable etc.) ist in
  jedem Tool als Konstante definiert.
- **MemberGroup** war bereits in beta2 auf das neue Pattern umgestellt.

### Added

- **`Service\UpdateDiff`-Helper:** Snapshot eines Contao-Models (`row()`),
  diff gegen den Post-applyFields-Zustand, string-cast beider Seiten
  (Contao speichert tinyint als `'1'`/`''`-Strings, FieldMapper schreibt
  int — strict !== würde Pseudo-Changes produzieren).
- **`no_mappable_fields`-Error-Code:** ersetzt das missverständliche
  `no_changes` für Option-A-Tools wenn der Caller keine bekannten Keys
  geschickt hat.

### Smoke-Test

163/163 grün — bestehende Asserts adaptieren sich automatisch an die neue
Response-Shape (sie checken `updated: true` + `changed_fields` ✓).

## [0.3.0-beta2] – 2026-05-26

Pre-1.0-Ticket #2 erledigt: MemberGroup-Update erkennt jetzt No-ops und
spart sich Save + Versions-Snapshot bei identischen Werten.

### Fixed

- **`member_group_update` no-op detection.** Bisher: jeder Call mit
  `fields={...}` erzeugte einen neuen `tl_version`-Snapshot, selbst wenn
  jedes übergebene Feld bereits den passenden Wert hatte. Audit-Trail-
  Rauschen ohne realen Change. Jetzt: vor `applyFields()` Snapshot der
  betroffenen Spalten; danach Diff. Bei leerem Diff → Response mit
  `updated: false, changed_fields: [], applied: 0`, kein `save()`, kein
  `Versions::create()`, kein `tstamp`-Bump. Bei echtem Change → unverändert
  durch.
- **Bonus: Response-Shape erweitert.** `member_group_update` liefert jetzt
  `changed_fields: list<string>` + `applied: int` zusätzlich zu den
  Serializer-Feldern — Vorbereitung für Ticket #3 (Response-Key-
  Unification quer durch alle Update-Tools).

### Smoke-Test

- Plus 2 Asserts: no-op-Detection (`updated=false`) + tl_version-Count
  vor/nach (Differenz muss 0 sein). 163/163 grün.

## [0.3.0-beta1] – 2026-05-26

**Breaking Change.** Daemon-Modus komplett entfernt. Das Bundle hat ab jetzt
genau einen Transport: den Symfony-Controller auf `/mcp`. Niemand hat
Daemon-Modus produktiv gefahren seit Controller-Modus default ist (beta7);
die Tatsache, dass die letzten drei Releases (0.2.0-beta1 bis -beta3) lauter
Daemon-Relikt-Fixes waren, hat den Maintenance-Kost-zu-Nutzen-Quotienten
endgültig klargemacht.

### Breaking

- **Entfernt:** `McpServeCommand` (CLI `contao:mcp:serve`),
  `McpServerProcessManager`, `McpServerFactory`. Wer das Bundle bisher als
  Daemon gefahren hat (niemand bekannt), kann den Service abschalten und
  auf den Controller-Pfad <code>https://&lt;backend_url&gt;/mcp</code>
  umstellen.
- **Konfig-Schema reduziert:** `config.json`-Felder `mode`, `host`, `port`
  weg. Vorhandene Werte werden beim Load **silent gedroppt** — kein
  Migrations-Skript nötig. Beim ersten Save wird das File ohne diese
  Felder neu geschrieben.
- **Backend-UI vereinfacht:** Mode-Selector, „Daemon starten/stoppen"-
  Buttons, Status-Zeilen (Host/Port/Probe/PID-File/Log-File), Log-Tail-
  Fieldset und die zugehörigen XLF-Keys sind raus.
- **Tool umbenannt:** `daemon_info` → `server_info`. Response-Shape um
  Daemon-spezifische Felder reduziert (`uptime`, `started_at`,
  `code_changed_after_boot`, `bundle_src_newest_mtime` — alle weg).
  Stale-Container-Erkennung erfolgt jetzt durch Vergleich von
  `container.compiled_at` gegen Disk; `cache:clear` ist die Standard-
  Behebung.
- **`system_health_check`-Response vereinfacht:** kein `mode`, kein
  `daemon`-Block mehr. Daemon-spezifische Warnungen (opcache.enable_cli,
  posix-Extension fehlt, lsof/ss fehlt, nohup/setsid fehlt, popen
  disabled, PHP-CLI-Binary nicht gefunden) entfernt — gelten unter
  PHP-FPM nicht.
- **Vendor-Patch entfernt:**
  `patches/protocol-drop-stale-notifications.patch`. Der war reine
  Long-Running-Daemon-Symptombehandlung; Controller-Requests bauen pro
  Call eine frische Session, Stale-Session-Notifications können nicht
  auftreten.
- **Docs überarbeitet:** §1 „Transport-Modus wählen" raus, §9
  „Debian-Linux-Daemon-Setup" raus. §1 ist jetzt eine kurze „Wie der
  Server läuft"-Erklärung.

### Fixed
- **`page_preview` scheiterte an HTTP-Basic-Auth** (Briefing aus dem
  Bootstrap-Projekt, gegen den echten Code verifiziert). Das Tool holt die Seite
  über ihre **öffentliche** URL — `ContentUrlGenerator` mit `ABSOLUTE_URL`, also
  `dns`/Domain der Root-Seite. Liegt davor ein Basic-Auth-Schutz (typisch auf
  Staging), antwortet der Webserver mit 401, bevor Contao überhaupt läuft, und
  die KI kann nach einem Edit nichts verifizieren.

  Neu: `MCP_PREVIEW_BASIC_AUTH="user:pass"` in der `.env.local` der Instanz
  (Bundle-Parameter `netzhirsch_contao_mcp.preview.basic_auth`). Ist nichts
  gesetzt, geht der Request unverändert raus wie bisher — keine Regression für
  Instanzen ohne Basic Auth. Zugangsdaten landen weder in der Antwort noch im
  Log.

  Dazu ein Hinweis bei 401/403, der den Unterschied benennt: keine Credentials
  konfiguriert vs. konfiguriert und abgelehnt. Ohne den sieht der Aufrufer nur
  einen Statuscode.

  Verifiziert am echten Container: ohne Credentials kein `Authorization`-Header,
  mit Credentials `Authorization: Basic …`, kein Leak in der Tool-Antwort.

- Die Tool-Beschreibung behauptete, `page_preview` rufe „the daemon's OWN site
  (loopback)" auf. Genau deshalb greift der Public-vhost-Schutz überhaupt — der
  Request geht über die öffentliche DNS. Text korrigiert.

- Die Konfig-Tabelle im README nannte `restricted` „IAT-Pflicht" — die sechste
  Stelle derselben Falschaussage. Beide READMEs hängen jetzt mit im
  `PairingWordingTest`.
### Removed

- `McpServeCommand` + `McpServerProcessManager` + `McpServerFactory`
- `patches/protocol-drop-stale-notifications.patch`
- Backend-UI: Mode-Selector, Start/Stop-Buttons, Daemon-Status-Rows,
  Log-Tail-Fieldset
- XLF: 18 Daemon-spezifische Keys (`button_start`, `button_stop`,
  `status_running`, `status_stopped`, `status_stale`, `status_pid`,
  `status_host`, `status_port`, `status_port_open`, `status_port_closed`,
  `status_pid_file`, `status_log_file`, `status_started_at`,
  `status_transport_mode`, `status_controller_mode`, `config_host_*`,
  `config_port_*`, `config_mode_*`, `headline_log`, `headline_controls`,
  `started_ok`, `start_failed`, `stopped_ok`, `stop_failed`,
  `restart_hint`, `error_host_required`, `error_port_invalid`,
  `not_running_hint`)
- Daemon-Doku-Sections in beiden Sprachen

### Migration

```bash
# Nach Composer-Update:
vendor/bin/contao-console cache:clear --env=prod
```

Keine Schema-Migration nötig. Bestehende `config.json` wird beim ersten
Backend-Aufruf des MCP-Server-Moduls automatisch um die Legacy-Felder
bereinigt. Wer das Bundle als Daemon-Systemd-Unit fährt: Service stoppen +
deaktivieren, dann auf den Controller-Endpoint
`https://<backend_url>/mcp` umstellen.

### Effekt

- ~700 Zeilen Code weg
- Ein Vendor-Patch weniger zu pflegen
- Eine Klasse Bug-Quelle eliminiert (Mode-Drift zwischen den beiden
  Transports)

## [0.2.0-beta3] – 2026-05-26

Controller-Modus-Cleanup: zwei Tools warnten oder reporteten irreführend
für Controller-only-Setups.

### Fixed

- **`system_health_check`** emittierte Daemon-Warnungen (opcache.enable_cli,
  lsof/ss/nohup/setsid fehlt, popen disabled, PHP-CLI-Binary nicht gefunden,
  posix-Extension fehlt) auch im Controller-Modus. Im Controller-Modus läuft
  keine CLI, keine Long-Running-PHP-Instanz, kein Start-Daemon-Button — die
  Warnungen produzieren nur Rauschen. Sind jetzt mode-gated. Response trägt
  ein neues `mode`-Top-Level-Feld.
- **`daemon_info`** las `var/mcp/server.pid` und `server.json` auch im
  Controller-Modus → `pid` fiel auf `getmypid()` zurück (= aktueller
  PHP-FPM-Worker, sinnlos für „läuft der Server noch?"). `uptime` und
  `code_changed_after_boot` waren immer null/false. Tool detected jetzt den
  Modus und liefert pro Modus die richtigen Felder mit erklärendem `note`.
- **Tool-Description-Cleanup:** Discovery-Tool (`contao_search_tools` etc.)
  sagte „When the daemon is started in lazy-mode" — gilt für beide
  Transports, jetzt entsprechend formuliert.

### Changed

- `system_health_check`-Response hat im Controller-Modus jetzt
  `daemon.pid_file`, `daemon.log_file`, `daemon.capabilities` jeweils `null`
  statt Daemon-Pfade die nicht existieren.
- Smoke-Test angepasst (162/162 grün): die PHP-CLI-Binary-Assertion ist
  jetzt mode-aware.

## [0.2.0-beta2] – 2026-05-26

Zwei Backend-Modul-Bugfixes aus dem Controller-Modus-Audit.

### Fixed

- **Mode-Selector wirkte nicht.** `ModuleMcpServer::handleSaveConfig` las
  `Input::post('mode')` nicht aus, also fiel jede Mode-Änderung im Dropdown
  stumm auf den Default zurück. Die UI zeigte korrekt das gewählte Mode an,
  aber `config.json` blieb unverändert. Fix: `mode` wird jetzt mit allen
  anderen Feldern eingelesen.
- **„Daemon neu starten"-Hint erschien in Controller-Modus.** Nach jedem
  Speichern kam unkonditional die Info-Message — im Controller-Modus
  irreführend, weil dort jeder Request frisch Symfony bootet und es keinen
  Prozess zum Neustarten gibt. Fix: Hint wird nur noch im Daemon-Modus
  angezeigt.

## [0.2.0-beta1] – 2026-05-26

**Breaking Change.** External-ID-Mapping wandert vom zentralen Lookup-
Table `tl_mcp_external_ref` in zwei dedizierte Spalten (`external_id_namespace`,
`external_id_key`) auf jeder unterstützten Entity-Tabelle. Cascade-Delete
für umsonst, kein „dangling pointer"-Problem mehr, im Backend pro Row
inspizierbar und editierbar.

### Breaking

- **`tl_mcp_external_ref` ist weg.** Migration `MigrateExternalIdToDecentralColumns`
  zieht alle existierenden Mappings in die neuen Spalten und droppt die
  Tabelle. Verwaiste Rows (target_id existiert nicht mehr) werden mit
  Begründung im Migrations-Log übersprungen. Bei `contao:migrate` ausführen.
- **`external_id_set` macht KEINEN Re-bind mehr.** Wenn dieselbe (namespace,
  external_key) bereits auf einer anderen Row hängt, kommt jetzt
  `mapping_conflict` zurück statt stillschweigend zu überschreiben. Wenn die
  Row bereits eine andere Mapping hat, kommt `row_already_mapped`. Aufrufer
  muss explizit `external_id_unset` vorher rufen wenn er das Binding ändern
  will. Idempotenz für `(ns, key, row_id)`-Tripel-Wiederholung bleibt erhalten.
- **Tool-Parameter renamed:** `target_table` → `table`, `target_id` → `row_id`,
  `external_id` → `external_key`. Alle vier Tools (`external_id_set/lookup/
  unset/list`).
- **Tool-Response umgestellt:** `set` liefert `{ok, created, updated, table,
  row_id, namespace, external_key}` statt `{created, target_id, updated,
  previous_target_id}`. `unset` liefert `{ok, was_set, row_id?}` statt
  `{deleted}`. `lookup` liefert `{found, row_id, table}` statt `{found,
  target_id, mapping_id}`.
- **`external_id_unset` ohne `row_id`-Parameter** — der `(namespace,
  external_key, table)`-Tripel ist die Lookup-Quelle. Vorher war row_id
  irrelevant, aber wir hatten den Parameter nicht.

### Added

- **DCA-Spalten in 23 Entity-Tabellen** über
  `Backend\ExternalIdDcaInjector` mit `#[AsHook('loadDataContainer')]`:
  `external_id_namespace` + `external_id_key`, UNIQUE-Index pro Tabelle,
  Felder in collapsibler Palette-Section `external_id_legend:hide`.
  Backend-Operator sieht die Spalten in jeder Edit-Maske unter
  „Externe ID (Automation/Pipelines)" und kann sie manuell editieren.
- **Unterstützte Tabellen** (gegenüber alter zentral-Variante erweitert):
  `tl_theme`, `tl_image_size`, `tl_image_size_item`, `tl_layout`, `tl_page`,
  `tl_article`, `tl_content`, `tl_module`, `tl_files`, `tl_form`,
  `tl_form_field`, `tl_member`, `tl_member_group`, `tl_news_archive`,
  `tl_news`, `tl_calendar`, `tl_calendar_events`, `tl_faq_category`,
  `tl_faq`, `tl_url_rewrite`, `tl_newsletter_channel`, `tl_newsletter`,
  `tl_newsletter_recipients`, `tl_comments`. Diese Liste matched die
  MCP-write-Tools des Bundles 1:1 — backend-user-Tabellen
  (`tl_user`, `tl_user_group`) sind read-only und bewusst ausgeschlossen.
- **Smoke-Test erweitert** um Konflikt-Verhalten (`mapping_conflict`,
  `row_already_mapped`), Cascade-Delete-Test (`theme_delete` löscht
  Mapping mit), DBAFS-Survival-Test (`dbafs_sync` auf `tl_files` lässt
  external_id-Spalten unverändert), Discovery-Mode (list ohne Args).
- **Backend-Doku-Subabschnitt** zum External-ID-Mapping in den Site-Building-
  Helfern, mit Tool-Signatur, Konflikt-Semantik, Cascade-Verhalten und
  vollständiger Tabellen-Liste (DE + EN).
- **XLF `default.xlf`** (DE + EN) mit `MSC.external_id_legend`,
  `MSC.external_id_namespace.{0,1}`, `MSC.external_id_key.{0,1}` —
  ein zentraler Übersetzungs-Topf statt 23 dupli­zierter Per-Tabellen-Strings.

### Migration

```bash
# Nach Composer-Update:
vendor/bin/contao-console contao:migrate --env=prod
```

Erwartet werden ALTER-TABLE-Statements für die 23 Tabellen (jeweils ADD
COLUMN external_id_namespace, ADD COLUMN external_id_key, ADD UNIQUE INDEX)
+ ein `MigrateExternalIdToDecentralColumns`-Run der bestehende Mappings
übernimmt + DROP `tl_mcp_external_ref`. Re-runs sind idempotent (Migration
prüft `tablesExist`).

## [0.1.0-beta11] – 2026-05-26

Backend-UI-Hierarchie: „Installationsanleitung" steht jetzt als eigene
Aufklappbox neben „Dokumentation" — gleicher Hierarchie-Level, nicht
verschachtelt. Außerdem Node.js-Klarstellung: native Custom Connectors
brauchen keinen Node-Bridge mehr.

### Added

- **Eigene Aufklappbox „Installationsanleitung"** im Backend-Modul, parallel
  zu „Verfügbare Tools" und „Dokumentation". Operator klappt nur auf, was
  er gerade braucht — Client-Setup ist nicht mehr in der Tool-Referenz
  versteckt.
- Neues Backend-Template `mcp_server_install_<lang>.html5` (DE + EN). Wird
  von `ModuleMcpServer::compile()` über `$this->Template->installTemplate`
  bereitgestellt und im `be_mcp_server.html5` als zusätzliches Fieldset
  vor „Dokumentation" gerendert.
- Neuer XLF-Key `mcp_server.headline_install` (DE „Installationsanleitung",
  EN „Installation guide").

### Changed

- **Node.js-Voraussetzung präzisiert**: Claude Desktop und Claude Web haben
  inzwischen native Custom-Connector-Unterstützung — kein Node.js mehr nötig.
  Das Install-Template zeigt jetzt eine Vergleichstabelle pro Client-Variante
  (Claude Web, Claude Desktop nativ, Claude Desktop via `mcp-remote`,
  Inspector, andere) mit expliziter „Node.js?"-Spalte. Node ist nur noch
  für die `mcp-remote`-Fallback-Bridge und den MCP Inspector erforderlich.
- Eigene Sections für Claude Web (native), Claude Desktop nativ (empfohlen)
  und Claude Desktop via `mcp-remote` (Fallback) — die alte Doku setzte
  `mcp-remote` als Default voraus, was nicht mehr stimmt.
- **Doku-Sections renumbered**: ehemalige §2 „Installationsanleitung" zieht
  ins eigene Template um. Die verbliebene Tool-/Architektur-Referenz heißt
  jetzt §2 (Templates) bis §9 (Linux-Setup). Cross-References (§5 OAuth,
  §6 Lazy, §9 Linux) entsprechend angepasst.

## [0.1.0-beta10] – 2026-05-26

Restrukturierung der Backend-Doku: Client-Installation hat jetzt einen
eigenen Abschnitt, statt fragmentiert über drei Stellen verteilt.

### Changed

- **Neuer §2 „Installationsanleitung (Client-Seite)"** mit sechs Unter-
  abschnitten (Voraussetzungen, Claude Desktop, OAuth-Flow, Restricted-Mode-
  Toggle, MCP Inspector, andere Clients) — fasst die alten §2 + §3 + den
  Client-Connect-Teil aus §7 zusammen.
- **§6 (Authentifizierung) ist jetzt rein Server-/Operator-seitig**: Setup-
  Schritte, Sicherheits-Defaults, Registrierungs-Modi, OAuth-Verwaltung,
  HTTPS-Warnung, Cleanup. Der Client-Connect-Subabschnitt wurde zu einem
  Cross-Reference auf §2.
- **§3–§10 entsprechen den alten §4–§11** (Renumbering ohne Inhaltsänderung).
- **§10 Linux-Setup** Titel präzisiert: „nur Daemon-Modus" — der Abschnitt
  betrifft nur das langlaufende ReactPHP-Setup, nicht den Controller-Modus.

### Fixed

- Duplizierte `claude_desktop_config.json`-Code-Blöcke aus §2 (alt) und §7
  (alt) jetzt nur noch an einer Stelle.

## [0.1.0-beta9] – 2026-05-22

Schließt die letzten Daemon-spezifischen Backend-UI-Reste, die im
Controller-Modus keinen Sinn ergeben.

### Changed

- **Status-Tabelle**: Host, Port (+ port-reachable-Probe), PID-file,
  Log-file werden im Controller-Modus jetzt ausgeblendet. Neue
  Top-Zeile „Transport-Modus" zeigt explizit Controller vs. Daemon.
  Path / Endpoint / Auth / Bundle-version bleiben in beiden Modi.
- **Log-Tail-Fieldset**: nur noch im Daemon-Modus sichtbar
  (`var/mcp/server.log` existiert im Controller-Modus nicht — Symfony
  loggt über den Standard-Logger).
- **XLF**: neuer Key `status_transport_mode` DE+EN.

## [0.1.0-beta8] – 2026-05-22

Backend-UI + Doku-Update zum Controller-Modus aus Beta-7. Der Code war live
seit Beta-7, aber Backend-Konfigurationsseite und Hilfe-Tab zeigten weiter
Host/Port + „Daemon starten"-Buttons. Damit war der Modus zwar nutzbar,
aber nicht entdeckbar.

### Added

- **Transport-Modus-Selektor** als erstes Feld in der Backend-Konfiguration:
  Radio-/Select-Toggle zwischen `controller` und `daemon`. Host/Port-Felder
  werden im Controller-Modus ausgeblendet (Werte bleiben als hidden inputs
  erhalten für späteren Switch). Daemon-Start/Stop-Buttons in der Toolbar
  sind nur im Daemon-Modus sichtbar.
- **Status-Anzeige** zeigt im Controller-Modus jetzt
  `Controller-Modus aktiv · Endpoint: https://.../mcp` statt der
  Daemon-Status-Indikatoren (PID, gestartet-am, port-open).
- **Doku-Tab** bekommt einen neuen Abschnitt §1 „Transport-Modus wählen"
  mit Vergleichstabelle Controller vs. Daemon, gefolgt von §1a Controller
  bzw. §1a Daemon je nach gewähltem Modus. DE + EN parallel.
- **XLF DE+EN**: 6 neue Translation-Keys (`config_mode_label`,
  `config_mode_controller`, `config_mode_daemon`, `config_mode_help`,
  `status_controller_mode`, `status_endpoint`).

### Fixed

- **`mcp_server_docs_*.html5`**: latenter Bug — der Endpunkt-URL-String wurde
  aus `$this->endpointUrl` gelesen, das aber nirgendwo gesetzt wird. Jetzt
  wird der Endpoint im Template selbst aus `$config` berechnet (controller-
  oder daemon-spezifisch).

## [0.1.0-beta7] – 2026-05-22

Größtes Architektur-Update der Beta-Phase: Controller-Modus als Alternative
zum ReactPHP-Daemon. Macht das Bundle auf Shared-Hosting deployable, wo
weder Custom-Ports noch Reverse-Proxy-Config zugänglich sind.

### Added

- **Controller-Modus** (`mode: "controller"`, neuer Default) — POST /mcp
  wird von einem Symfony-Controller direkt behandelt, statt eines
  long-running ReactPHP-Daemons. Kein Port, kein Reverse-Proxy, läuft
  überall wo Contao läuft.

  Neue Komponenten:
  - `Controller\McpController` — `POST /mcp` (JSON-RPC) +
    `GET /.well-known/oauth-authorization-server` (RFC 8414 Metadata).
    Discovers über `#[Route]`-Attribute.
  - `Server\HttpDispatcherFactory` — baut Server+Dispatcher einmal pro
    PHP-FPM-Worker. Tool-Discovery wird via `cache.app` (Symfony PSR-6)
    auf Disk gecacht, damit Cold-Start-Requests nicht den Reflection-
    Scan zahlen.
  - `Service\McpOAuthValidator` — Bearer-Validierung extrahiert aus dem
    Daemon-Closure, jetzt von beiden Transports geteilt. Identische
    Auth-Semantik egal welcher Pfad.

  Beide Transports koexistieren — der Operator wählt im Backend, was er
  Claude/mcp-remote anbietet. Die Daemon-Variante bleibt für High-Perf-
  Setups (kein Per-Request-Symfony-Boot, ~5ms statt ~200ms; SSE-Support).

  Trade-offs Controller vs. Daemon:
  - ✅ Kein Long-Running-Prozess, kein „Daemon-stale"-Footgun
  - ✅ HTTPS automatisch via Apache/nginx
  - ✅ Shared-Hosting tauglich (Plesk, etc.)
  - ❌ ~200ms Per-Request-Latenz statt 5ms (Symfony-Boot)
  - ❌ Kein SSE — Tool-Outputs immer als Block. Aktuell kein Tool nutzt
       Streaming, also kein Funktionsverlust.

- **`McpServerConfigStorage::mode`** Setting (`'daemon' | 'controller'`,
  Default `'controller'`). Beide Modi sind immer registriert; der Switch
  ist informativ für Backend-UI-Hints.

## [0.1.0-beta6] – 2026-05-22

Hotfix für einen verbuggten Patch-Hunk-Header der erst bei der Installation
auf einem Strict-`patch`-Server (Production-Linux mit Plesk + PHP 8.4)
auffiel.

### Fixed

- **`patches/dispatcher-tool-filter.patch`**: Hunk-2-Header war
  `@@ -200,17 +217,30 @@` → korrigiert zu `@@ -197,6 +214,20 @@`. Die
  Datei war beim Hinzufügen des Post-Call-Hooks in Beta-2 hand-editiert
  und die Zeilen-Counts wurden falsch berechnet. Patch-Resultat war
  bit-identisch, aber lenient `patch`-Versionen (z.B. unsere Laragon-
  Dev-Umgebung) akzeptierten den falschen Header durch Fuzzy-Matching;
  strict-`patch` (auf dem Production-Linux-Server) schlug fehl mit
  „Cannot apply patch". Patch jetzt sauber via `diff -u` gegen die
  upstream-Pristine-Version generiert.

## [0.1.0-beta5] – 2026-05-22

Reagiert auf eine spezifische Anschluss-Beobachtung nach Audit #2: das
Beta-3-Multilingual-Tool deckte nur `tl_page` ab, obwohl
`terminal42/contao-changelanguage` `languageMain` auf fünf Tabellen
exponiert.

### Added

- **`entity_language_link`-Tool** (neue Group `multilingual`) — generische
  Variante von `language_link_pages`, deckt alle fünf Entitäten ab, die via
  `terminal42/contao-changelanguage` ein `languageMain`-Feld bekommen:
  `tl_page`, `tl_news`, `tl_article`, `tl_calendar_events`, `tl_faq`.

  API symmetrisch zu `language_link_pages`:
  ```
  entity_language_link(
      table: "tl_news", default_id: 10,
      translations: {"de": 12, "fr": 14, "en": 15},
      reset_first: false
  )
  ```

  Validation up-front (alle referenzierten Rows existieren), per-row
  Versions-Snapshot, einzelner `tl_log`-Eintrag der die Aktion zusammenfasst.
  Sprach-Code-Mismatch wird nur für `tl_page` geprüft (einzige Tabelle mit
  nativem `language`-Feld) — bei anderen ist `lang` rein informativ.

  `language_link_pages` bleibt als Convenience-Wrapper für den
  häufigsten Case bestehen.

## [0.1.0-beta4] – 2026-05-22

Reagiert auf den zweiten externen Audit (2026-05-22 nachmittags). Drei
beobachtete Issues + eine inhaltliche Anregung adressiert.

### Fixed

- **`page_translations_tree({})` schmiß "Tool execution failed"** — bei
  komplett ausgelassenem `root_id` schlug der Dispatcher-Path fehl,
  während `{root_id: null}` funktionierte. Signatur jetzt
  `mixed $root_id = null` mit defensiver int-Casting-Logik im Body. Akzeptiert
  jetzt: omitted, null, 0, "0", oder positive int. Sidestep des php-mcp
  edge case bei `?int = null`-Parametern.

- **`contao_search_tools` ranking** — name-matches wurden von description-
  matches verdrängt, sobald limit erreicht war. Beispiel: Suche nach
  `entity_query_options` lieferte 18 `*_list`-Tools, das eigentliche
  Tool aber nicht (alphabetisch hinter „page_*list"). Neuer
  Score-basierter Sort: exact-name (3) > prefix-name (2) > substring-name
  (1) > description-only (0). Stabil alphabetisch innerhalb gleicher
  Range. `total_matched` jetzt im Response (vor Limit-Truncation).

### Added

- **`daemon_info`-Tool** — runtime info über den laufenden Daemon: pid,
  uptime, started_at, container compile-time, container path,
  transport-config, **`code_changed_after_boot`-Flag**. Letzteres
  vergleicht den `var/mcp/server.json::started_at` gegen die newest mtime
  unter `src/`. True → Bundle wurde nach Daemon-Start geändert,
  Container ist stale, Stop+Start fällig. Genau die Self-Diagnose, die im
  ersten Audit gefehlt hat.

- **`entity_query_options` returns `examples`** — pro Tabelle eine kuratierte
  Liste konkreter Filter-Beispiele (`{description, filters}`). Für 8
  wichtige Tabellen handgepflegt (News/Page/Article/CalendarEvent/Member/
  FormField/Module/Comments), Fallback ist eine synthetische Beispielzeile
  aus dem ersten boolean/enum DCA-Filterfeld. LLM + Mensch-Operatoren
  bekommen damit sofort verwertbare Filter-Patterns.

## [0.1.0-beta3] – 2026-05-22

Adressiert zwei der vier Architektur-Lücken aus dem externen Audit
(2026-05-22). Beide ohne Touch an Bestands-DCAs — eine neue Mapping-
Tabelle und zwei Convenience-Tools auf `tl_page`.

### Added

- **External-ID-Mapping** (Skill-2-Idempotenz für re-runbare Builder):
  neue Tabelle `tl_mcp_external_ref` plus vier Tools:
  - `external_id_set(namespace, external_id, table, id)` — bindet
    eine caller-chosen ID an eine Contao-Row. UNIQUE auf
    (namespace, external_id, table) → idempotent, re-bindet auf
    Re-Imports.
  - `external_id_lookup(namespace, external_id, table)` — Hash-Lookup,
    cheap. Pattern: lookup → wenn gefunden update, sonst create+set.
  - `external_id_unset(namespace, external_id, table)` — entfernt
    Mapping (idempotent — kein Error bei nichts da).
  - `external_id_list(namespace?, target_table?)` — Audit-Tool für
    "was hat namespace X angelegt?".

  Damit braucht ein Manifest-Builder keine externe `build-state.json`
  mehr und Idempotenz funktioniert auch nach Repo-Restore.

- **Multilingual Page-Helper** (zwei Tools auf `Page\Tool.php`):
  - `language_link_pages(default_id, translations: {de: id, fr: id, ...})`
    — setzt `languageMain = default_id` auf alle Translation-Pages in
    einem Call. Validation up-front (alle Pages existieren) bevor
    geschrieben wird. Warnt bei `language`-Mismatch, skipt
    Self-References.
  - `page_translations_tree(root_id?)` — gruppiert Pages nach ihrem
    `languageMain`-Target. Zeigt Defaults + Translations + Orphans.
    Sinnvoll vor einem `language_link_pages`-Call um den Stand zu
    sehen.

## [0.1.0-beta2] – 2026-05-22

Sammelt sieben Feature-Commits seit `v0.1.0-beta1`. Smoke-Test wuchs von
75 → 132 Asserts (alle grün).

### Added

- **`files_search`-Tool**: rekursive Glob-Suche im Upload-Tree mit POSIX-Glob-
  Syntax inkl. `**`-Erweiterung **und Brace-Expansion** `{a,b,c}`. Brace-
  Alternativen dürfen selbst Glob-Chars enthalten (`*.{jpg,png}`,
  `{banner-*,article}.{jpg,png}`); Nesting wie `{a,{b,c}}` ist explizit
  gerejected. Path-traversal-safe via `PathResolver`, hardcap 100k visited
  entries gegen Runaway-Scans, optional auf Subdir beschränkbar, Filter nach
  `type` (`files`/`folders`/`all`), case-sensitivity toggle. Patterns ohne
  `/` matchen automatisch im ganzen Baum (LLM-friendly, wie `git ls-files`
  / `fd`).
- **`dbafs_sync`-Tool**: stößt die DBAFS-Reconciliation an (entspricht dem
  „Synchronisieren"-Button im Backend-Dateimanager bzw. `contao:dbafs:sync`).
  Erkennt Dateien die ausserhalb von Contao zur `files/`-Ablage gekommen oder
  daraus verschwunden sind und gleicht `tl_files` ab. Returnt strukturiertes
  ChangeSet (created/updated/deleted Counts + erste 50 Samples pro Bucket),
  `duration_ms`, optional auf DBAFS-prefixed Subpaths (`files/content`) zu
  begrenzen. Destruktiv → braucht `confirm_destructive=true`. Logged nach
  `tl_log` (GENERAL).
- **Phase C: Entity-Search + Filter** (Theme, Layout, Module, ImageSize, User,
  NewsArchive, Calendar, FaqCategory): selbes `q` / `filters` / `updated_*`
  Pattern wie Phase A+B. Damit sind jetzt **alle 18 entscheidenden Listen-Tools**
  einheitlich DCA-getrieben durchsuch- und filterbar.

- **Phase B: Entity-Search + Filter** (Article, CalendarEvent, FAQ, Member,
  MemberGroup, Form, FormField, Comments): selbes `q` / `filters` / `updated_*`
  Pattern wie Phase A, jeweils via `QueryFilterResolver` an die existierenden
  `*_list`-Tools angeschlossen. Legacy `search`-Param in Member/Form bleibt
  zusätzlich erhalten (backwards-compat) — neue Calls sollten `q` nutzen.

- **Phase A: Entity-Search + Filter** (News, Page):
  - **`entity_query_options(table)`**: Discovery-Tool — returnt pro Entity-
    Tabelle `searchable_fields` (Spalten die `q` durchsucht), `filterable_fields`
    (erlaubte Keys in `filters`, mit Typ-Info: boolean/enum/foreign_key/date/…),
    `has_tstamp`. Reflektiert exakt die DCA-Settings — also genau das was
    Contao's Backend-Quicksearch und Filter-Panel anbieten.
  - **`news_list` / `pages_list` extended**: neue Params `q`, `filters`,
    `updated_after`, `updated_before`. Brechen bestehende Calls nicht (alle
    optional). Validation strikt gegen DCA — unbekannte Filter-Spalten werden
    mit `invalid_filter` + Liste der erlaubten Felder abgelehnt.
  - **Shared `Service\QueryFilterResolver`**: DCA-Introspektion + SQL-Clause-
    Builder mit Parameter-Binding und LIKE-Escape. Wird in Phase B/C an die
    restlichen Entities (Article, Calendar-Event, FAQ, Member, Form, …)
    angeschlossen.

### Fixed

- **PathResolver `isInside()`**: prüft jetzt mit angehängtem `DIRECTORY_SEPARATOR`,
  damit ein hypothetischer Sibling-Folder wie `files-secret/` nicht mehr als
  „inside" der `files/`-Base interpretiert würde. Adressiert das latente
  Audit-Finding M1 (Sec) vom 2026-05-21.

## [0.1.0-beta1] – 2026-05-21

Erste Beta. Feature-complete für Read+Write auf alle Contao-Kernentitäten
plus populäre Extensions, OAuth 2.1 mit PKCE + DCR, Lazy-Mode-Discovery,
Backend-Modul, Linux/Windows-Robustheit, 75/75 Smoke-Test grün nach
Multi-Dimensions-Audit.

### Tools (~156 insgesamt)

- **Health/System**: `ping`, `installed_bundles`, `contao_version`,
  `system_settings`, `system_settings_update`, `system_health_check`,
  `insert_tags_list`
- **News + Archive**: full CRUD für `tl_news`, `tl_news_archive`
- **Page**: full CRUD inkl. Tree-View + Type-Validation aus DCA-Palette
- **Article + Content**: full CRUD plus dynamic palette-Discovery
- **Calendar + CalendarEvent**: full CRUD
- **FAQ + Category**: full CRUD
- **Member + MemberGroup**: full CRUD; Password-Hash never in response
- **Form + FormField**: full CRUD mit per-type palette
- **Theme + Layout + Module + ImageSize + ImageSizeItem**: full CRUD
- **Newsletter (Extension)**: Channel + Newsletter + Recipient CRUD
- **Comments (Extension)**: full CRUD inkl. `comment_create` (Spam-Risk-Warning
  in Tool-Description bei `auth_mode=none`)
- **URL-Rewrites (Extension)**: full CRUD für `tl_url_rewrite`
- **Templates**: 9 Tools inkl. Twig-Lint, Theme-Folder, Component-Templates,
  `template_lookup` (Hierarchie über `ContaoFilesystemLoader`),
  `template_dependencies` (Twig-AST-Walking)
- **Files**: 9 Tools für `tl_files` + Filesystem mit Path-Traversal-Schutz
  und Upload-Validierung
- **Site-Building-Helfer**: `entity_move` (9 sortable Tables),
  `page_url`, `page_preview`, `page_cache_invalidate`
- **Maintenance**: `maintenance_jobs_list`, `maintenance_run` mit
  Destructive-Confirm
- **Discovery / Lazy-Mode**: `contao_search_tools`, `contao_describe_tool`,
  `contao_call`

### OAuth 2.1 Hardening

- PKCE Pflicht (`code_challenge_method=S256`)
- Initial-Access-Token-Gate für `oauth_registration_mode=restricted` (RFC 7591)
- Redirect-URI scheme deny-list für `javascript:`/`data:`/`vbscript:`/`file:`/…
- CSRF-Token auf Consent-Page
- Rate-Limit: `/register` 10/h, `/token` 60/min, `/authorize` 30/min
- Code-Reuse-Detection (OAuth 2.1 §4.1.2) mit Cascade-Revoke
- Cleanup-Command `contao:mcp:oauth:cleanup` für expired/revoked Records
- Backend-UI: IATs erzeugen + Clients revoken

### Backend-Modul

- Live-Status + Daemon-Start/Stop (PowerShell+VBScript auf Windows,
  `nohup setsid` auf Linux)
- Log-Tail
- Konfig-Form für `var/mcp/config.json`
- Bilinguale Doku (DE/EN) inkl. §11 Debian/Linux-Setup-Checkliste

### Linux/Production-Readiness

- Cross-Platform `McpServerProcessManager` mit `binaryExists()`-Caching für
  `lsof`/`ss`/`nohup`/`setsid`
- Pre-Flight-Diagnose in `McpServeCommand` mit aktiven Warnings
- `system_health_check` als Runtime-Tool — Output enthält `warnings: []`
  mit konkreten Fix-Befehlen (apt install, chown, chmod, opcache)

### Audit-Fixes (Multi-Dimensions-Audit 2026-05-21)

- **Security**: OAuth redirect_uri scheme deny-list
- **Performance**: `Contao\Model\Registry::reset()` nach jedem `tools/call`
  via neuem `PostCallHook` — verhindert Daemon-OOM nach Wochen Laufzeit;
  `RegistryAccessor::getToolsCached()` memoisiert die ~156-Tool-Schemas
- **Data integrity**: `entity_move` läuft in `transactional()` +
  `SELECT … FOR UPDATE`; `layout_create` seedet BLOB-Defaults
  (`modules`/`sections`/`external`/`externalJs`) mit `a:0:{}`;
  `page_delete` checkt jumpTo-Referrer in 6 Tabellen;
  `Content::validateParent` rejected unknown ptables
- **API**: `Content`/`Page` bekommen `Doctrine\DBAL\Connection`-Dep

### Bekannte Einschränkungen (siehe README)

- `*_delete` ohne uniform `confirm`-Gate
- `page_preview` sync HTTP blockiert Event-Loop (max 10 s)
- Theme/Form Cascade-Delete ohne DBAL-Transaktion
- Update-Response inkonsistent: `applied` vs `changed_fields`
- MemberGroup-update no-op-detection fehlt

### Vendor-Patches (auto-applied via `cweagans/composer-patches`)

- `protocol-drop-stale-notifications.patch` — Stale-Session-Notifications
  silently droppen statt ReactPHP-Loop crashen
- `transport-auth-and-oauth-metadata.patch` — Pluggable Bearer-Auth +
  `/.well-known/oauth-authorization-server`-Handler
- `dispatcher-tool-filter.patch` — Lazy-Mode-Filter + Post-Call-Hook

---

[0.1.0-beta10]: https://github.com/Netzhirsch/contao-mcp-bundle/releases/tag/v0.1.0-beta10
[0.1.0-beta9]: https://github.com/Netzhirsch/contao-mcp-bundle/releases/tag/v0.1.0-beta9
[0.1.0-beta8]: https://github.com/Netzhirsch/contao-mcp-bundle/releases/tag/v0.1.0-beta8
[0.1.0-beta7]: https://github.com/Netzhirsch/contao-mcp-bundle/releases/tag/v0.1.0-beta7
[0.1.0-beta6]: https://github.com/Netzhirsch/contao-mcp-bundle/releases/tag/v0.1.0-beta6
[0.1.0-beta5]: https://github.com/Netzhirsch/contao-mcp-bundle/releases/tag/v0.1.0-beta5
[0.1.0-beta4]: https://github.com/Netzhirsch/contao-mcp-bundle/releases/tag/v0.1.0-beta4
[0.1.0-beta3]: https://github.com/Netzhirsch/contao-mcp-bundle/releases/tag/v0.1.0-beta3
[0.1.0-beta2]: https://github.com/Netzhirsch/contao-mcp-bundle/releases/tag/v0.1.0-beta2
[0.1.0-beta1]: https://github.com/Netzhirsch/contao-mcp-bundle/releases/tag/v0.1.0-beta1
