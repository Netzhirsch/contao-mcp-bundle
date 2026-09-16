# Antwort auf eure Rückmeldung: alle drei Punkte umgesetzt

**Von:** Contao-MCP-Bundle (`netzhirsch/contao-mcp-bundle`), **v1.29.0**
**Auf:** `component-bundle-field-provider-response.md` vom 16.09.2026
**Stand:** 16.09.2026

Danke — die Rückmeldung war besser als das Briefing, auf das sie antwortet.
Punkt 1 war ein echter Vertragsbruch bei uns, Punkt 3 die eigentliche Ursache
des Falls. Alle drei sind umgesetzt; bei Punkt 1 haben wir eure teurere
Variante genommen.

Und: Entschuldigung für „Component-Bundle". Es heißt Bootstrap-Bundle, die
Dateinamen bei uns sind falsch benannt und bleiben es jetzt, damit die
Korrespondenz zusammenbleibt.

---

## 1. `getAllowedFields()` wird jetzt ausgewertet — **Variante (a)**

Ihr habt beide Wege angeboten und (b) als den billigeren bezeichnet. Wir haben
(a) gebaut: `ProviderFields::apply()` bekommt den aufgelösten Typ und fragt
`getAllowedFields($type)`, bevor `apply()` des Providers überhaupt aufgerufen
wird. Ein Feld, das der Provider für diesen Typ nicht erlaubt, wird mit einer
Meldung abgewiesen, die eure Erweiterung und den Typ nennt — dieselbe Form, die
der Page-Mapper schon hatte.

Der Grund gegen (b): Die Alternative wäre gewesen, eine Falle zu dokumentieren
statt sie zuzumachen. Der Docblock hätte dann gesagt „diese Methode verspricht
eine Typ-Prüfung, die nur an einer Stelle stattfindet" — und der nächste
Provider-Autor liest ihn im besten Fall.

**Was das für euch heißt:**

- Eure `getAllowedFields()`-Implementierung greift ab sofort **wirklich**, auch
  auf dem Content-Pfad. Ein Feld von Komponente A auf einem Element vom Typ B
  erreicht euer `apply()` nicht mehr.
- Eure Nachprüfung in `apply()` wird dadurch nicht überflüssig — sie ist jetzt
  die zweite Schicht statt der einzigen. Bitte lasst sie drin: Wir gaten nur,
  wo die Tabelle ein Typ-Konzept hat.
- **Sichtbare Änderung:** Der Fehlertext für diesen Fall kommt jetzt von uns,
  nicht mehr von euch. Falls einer eurer Tests auf euren eigenen Wortlaut
  prüft, schlägt er fehl — das ist die einzige Stelle, an der euch v1.29.0
  etwas kaputt macht.

Kein Typ-Konzept, kein Gate: `tl_theme` und `tl_layout` übergeben `null` und
überspringen die Prüfung.

**Eine Korrektur an eurer Fundstelle:** Über `ProviderFields` laufen Content,
**Layout** und Theme — nicht Module. Der Modul-Mapper benutzt gar keine
Field-Provider. Am Befund ändert das nichts.

## 2. Präfix-Regel steht im Interface

Wortlaut sinngemäß übernommen, plus die Mechanik dahinter, weil die den
Unterschied zwischen „abgewiesen" und „still überschrieben" erklärt: Der
Kern-Mapper schreibt die Spalte, danach läuft der Provider und schreibt sie
erneut. Es gibt keine Kollisionserkennung — der Provider gewinnt, lautlos.

Ebenfalls im Docblock: Wer Feldnamen aus Redaktionseingaben ableitet, **muss**
präfixen. Das ist euer Fall und wird nicht der letzte sein.

## 3. `installed_bundles` nennt jetzt die deaktivierten Werkzeuge

Der wertvollste der drei Punkte, weil er den Fall erklärt: Die Werkzeuge waren
da, sie waren aus, und „aus" sieht von außen genauso aus wie „gibt es nicht".

Neuer Abschnitt `mcp_extension_tools`:

```json
{
  "tools": [
    {"name": "component_instance_create", "description": "…", "class": "…", "enabled": false},
    {"name": "component_instance_update", "description": "…", "class": "…", "enabled": true}
  ],
  "disabled": ["component_instance_create"],
  "hint": "1 extension tool(s) are installed here but not enabled, so they are absent from tools/list and cannot be called. They are opt-in: a Contao administrator enables them under MCP-Server → Tools (config key extension_tools_enabled). Before concluding that something cannot be done through MCP, check whether one of these would do it."
}
```

`hint` erscheint nur, wenn es etwas zu tun gibt. Die Werkzeugbeschreibung von
`installed_bundles` weist ebenfalls darauf hin, damit ein Agent den Aufruf
überhaupt macht, bevor er „geht nicht" schreibt.

Was der Abschnitt bewusst **nicht** kann: sagen, ob ein freigeschaltetes
Werkzeug auch wirklich serviert. Namenskollision mit einem Kernwerkzeug oder
eine Klasse, die sich nicht reflektieren lässt, verhindern die Registrierung —
beides wird protokolliert, beides ist selten, beides ist eine andere Frage als
„ist das hier vorhanden".

---

## Was wir von euch übernehmen würden

Euer bedingter Service-Import hinter `class_exists()` plus Interface-Stubs für
PHPStan/PHPUnit ist die sauberste Lösung des Problems „Bundle muss ohne
MCP-Bundle lauffähig bleiben", die wir gesehen haben. Dürfen wir das Muster —
mit Verweis auf euch — in die Provider-Doku aufnehmen? Dann muss der nächste
Autor es nicht neu erfinden.

Und danke für die Wertform-Tabelle zu `netzhirsch_component_values`. Die
binären UUIDs bei `image`/`file`/`video` sind genau die Stelle, an der ein Agent
über `content_update` Unsinn geschrieben hätte — ein weiterer Beleg dafür, dass
die Validierung bei dem liegen muss, der das Format besitzt.
