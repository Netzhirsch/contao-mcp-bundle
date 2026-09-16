# Antwort: Component-Bundle — FieldProvider ist gebaut, drei Rückmeldungen

**Von:** `netzhirsch/contao-bootstrap-bundle` (das Bundle, das
`tl_netzhirsch_component` und die `netzhirsch_component_*`-Elemente mitbringt —
es heißt Bootstrap-, nicht Component-Bundle)
**Auf:** `component-bundle-field-provider.md` vom 16.09.2026
**Stand:** 16.09.2026, umgesetzt in **v0.22.0**

Danke — der Vorschlag war richtig und ist umgesetzt. Drei Rückmeldungen, nach
Nutzen sortiert: Punkt 1 ist eine Falle in eurem Interface, Punkt 2 eine Lücke
in der Doku, Punkt 3 eine Korrektur am Ausgangsbefund.

---

## 1. `getAllowedFields()` ist dokumentiert als Typ-Gate, wird aber nur vom Page-Mapper benutzt

Das ist der Punkt, der anderen Provider-Autoren wehtun wird.

`FieldProvider.php` beschreibt die Methode als *„Fields that should be accepted
on create/update for the given resolved type. Return [] when the provider's
fields aren't valid for that type"*, und der Klassen-Docblock sagt *„the provider
takes responsibility for any per-type filtering inside getAllowedFields"*.

Tatsächlich ruft sie nur **eine** Stelle auf:

```
src/Tool/Page/FieldMapper.php:257:  $providerAllowed = $claimedBy->getAllowedFields($resolvedType);
```

Content, Module und Theme laufen über `Service/ProviderFields.php`, und das
schneidet gegen `getDeclaredFields()`:

```php
// ProviderFields::apply()
$claims = array_intersect(array_keys($input), $provider->getDeclaredFields());
```

`Content/FieldMapper::allowedFieldsFor()` nimmt ebenfalls `declaredFor($table)`,
also die **Vereinigungsmenge aller** Provider.

**Folge:** Wer `getAllowedFields()` sauber implementiert — für fremde Typen `[]`
zurückgibt — und daraus schließt, die Typ-Zuordnung sei erledigt, liegt auf dem
Content-Pfad falsch. Seine Felder werden auf **jedem** Inhaltselementtyp
akzeptiert, und sein `apply()` wird aufgerufen. Prüft `apply()` den Typ nicht
selbst nach, schreibt er in das falsche Element. Das ist still: kein Fehler, ein
Wert an der falschen Stelle.

Bei uns kommt erschwerend dazu, dass die Union über **alle** Komponenten geht —
ein Feld von Komponente A erreicht `apply()` auf einem Element vom Typ B. Wir
fangen beides in `apply()` ab und haben je einen Test dafür.

**Vorschlag,** eines von beiden:

- `ProviderFields` konsultiert `getAllowedFields($type)` dort, wo ein Typ bekannt
  ist (Content/Module) — dann stimmt die Doku; oder
- der Docblock sagt ausdrücklich, dass die Methode aktuell **nur** der
  Page-Mapper auswertet und die Typ-Prüfung in `apply()` gehört.

Die zweite Variante ist billiger, die erste sicherer.

---

## 2. Feldnamen können nicht frei sein — Präfix gehört in die Doku

Das Gerüst im Briefing nimmt eine feste Liste an (`component_headline`,
`component_text` …). Bei uns vergibt die **Redaktion** die Parameter-Aliase; das
Beispiel in unserem README heißt `headline` und `foto`.

Hätten wir die Aliase roh deklariert, wären es Feldnamen wie `headline`, `text`,
`html`, `url`, `size`, `caption`, `cssID`, `linkTitle`, `titleText` — allesamt
echte `tl_content`-Spalten. Und weil Provider-Felder die Paletten-Prüfung
**überspringen** und danach wir den Schreibvorgang besitzen, hätte das die
Core-Spalte still überschattet, statt abgewiesen zu werden. Genau die Eigenschaft,
die den Provider-Weg erst möglich macht, macht ihn an dieser Stelle gefährlich.

Wir präfixen deshalb: `component_<param-alias>` (geprüft: keine
`tl_content`-Spalte beginnt mit `component`).

**Vorschlag:** einen Satz in `FieldProvider.php` aufnehmen — sinngemäß *„Feldnamen
müssen bundle-eindeutig sein; Provider-Felder umgehen die Paletten-Prüfung, ein
Name, den die Zieltabelle bereits führt, überschreibt die Core-Spalte still. Wer
Feldnamen aus Benutzereingaben ableitet, muss präfixen."* Das trifft jedes Bundle
mit konfigurierbaren Feldern, nicht nur uns.

---

## 3. Der Ausgangsbefund: Schreiben ging, die Werkzeuge waren nur aus

Euer Verdacht am Ende des Abschnitts „Der große Weg" war richtig — und er ist die
eigentliche Erklärung des Falls.

Wir haben den Schreibweg seit Langem: `component_instance_create` / `_update` /
`_get`. `_update` macht **Teil-Updates** (genau der Übersetzungsfall: eine
Überschrift ändern, den Rest behalten). Der Agent fand sie nicht, weil
Erweiterungswerkzeuge **opt-in** sind und im Backend freigeschaltet werden müssen.
Aus seiner Sicht gab es keine Tools — und die Fehlermeldung las sich wie eine
Rechtegrenze.

Der Provider war trotzdem die richtige Empfehlung, aber aus einem anderen Grund
als im Briefing: nicht weil Schreiben unmöglich war, sondern weil ein Provider
**keine Freischaltung braucht**.

**Vorschlag:** `installed_bundles` (oder die Fehlermeldung) könnte vorhandene,
aber **deaktivierte** Erweiterungswerkzeuge nennen. „Diese Erweiterung bringt 3
Werkzeuge mit, alle deaktiviert" führt zu einer Frage an den Betreiber; „keine
zusätzlichen Werkzeuge" führt zu der Schlussfolgerung, die euer Briefing
korrigieren musste.

---

## Was wir gebaut haben

`src/Mcp/ComponentContentFieldProvider.php`, getaggt `netzhirsch.field_provider`,
Tests in `tests/Mcp/ComponentContentFieldProviderTest.php`:

- ein virtuelles Feld je Parameter: **`component_<alias>`**
- `getDeclaredFields()` = Union über alle Komponenten, `getAllowedFields($type)`
  = die Parameter genau dieser Komponente (auch wenn der Content-Pfad sie nicht
  liest — siehe Punkt 1)
- `apply()` weist ab: Felder auf einem Nicht-Komponenten-Element, und Felder, die
  zu einer **anderen** Komponente gehören
- Teil-Updates über die bestehende Merge-Logik; ungültiger Wert →
  `InvalidArgumentException`, es wird nichts gespeichert
- Coercion/Validierung/Merge teilen sich Provider und `component_instance_*` einen
  Service (`ComponentValueNormalizer`) — eine Wahrheit, zwei Zugänge

---

## Eure drei offenen Fragen

**Aufbau von `netzhirsch_component_values`:** eine serialisierte Map
`parameter-alias => Wert`. Die Aliase sind **pro Komponente** verschieden und
werden aus dem Parameter-Titel abgeleitet (sluggifiziert, innerhalb der Komponente
eindeutig). Die Wertform hängt am Parametertyp: `text`/`html`/`link` sind Strings,
`headline` ist `{unit, value}`, `list` eine serialisierte Liste, `checkbox` `'1'`/`''`,
`image`/`file`/`video` eine **binäre** UUID, `link_text` `{url, text}`, `image_size`
`{src, size}`, `module`/`content_element` eine int-ID. Ein Parameter vom Typ
`trenner` ist reine Layout-Gruppierung und trägt keinen Wert — den lassen wir aus
den Feldern heraus.

**Felder außerhalb der serialisierten Spalte:** ja, aber unkritisch. Die Elemente
sind normale `tl_content`-Zeilen, also greifen `cssID`, `invisible`, `sorting` usw.
schon über die Palette. Unser Provider besitzt ausschließlich die serialisierte
Spalte.

**Lauffähig ohne MCP-Bundle:** ja, das müssen wir. Gelöst über einen bedingten
Service-Import in `loadExtension()` hinter `class_exists(...)`; `src/Mcp/` ist aus
der regulären `services.yaml` ausgeschlossen und wird nur über eine separate
`services_mcp.yaml` geladen. Für PHPStan/PHPUnit liegen Stubs des Interfaces im
Repo. Das Muster läuft bei uns seit mehreren Versionen — gern abschauen, falls ihr
es jemandem empfehlen wollt.

Ins Repo sehen dürft ihr jederzeit:
`github.com/Netzhirsch/contao-bootstrap-bundle`, ab Tag `v0.22.0`.
