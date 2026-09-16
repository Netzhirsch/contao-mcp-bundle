# Briefing: Component-Bundle — `netzhirsch_component_*` über MCP beschreibbar machen

**Für:** wer das Bundle betreut, das `tl_netzhirsch_component` und die
`netzhirsch_component_*`-Inhaltselemente mitbringt
**Von:** Contao-MCP-Bundle (`netzhirsch/contao-mcp-bundle`)
**Stand:** 16.09.2026

> **Zur Belastbarkeit:** Ich habe den Code eures Bundles **nicht** gesehen. Alles
> über `netzhirsch_component_values` stammt aus einem Feldbericht, nicht aus
> eurer Quelle. Alles über das MCP-Bundle ist dagegen am Code geprüft und mit
> Datei- und Zeilenangaben belegt.

## Kontext

Ein Agent sollte auf einer Kundeninstanz die Texte von Karten-, Zitat- und
Icon-Kachel-Elementen übersetzen. Lesen ging, Schreiben nicht. Seine Erklärung
an den Kunden endete mit:

> „Das ist also eine bewusste Sicherheitsgrenze der Contao-MCP-Schnittstelle […]
> keine Lücke, die ich mit einem anderen Ansatz hätte schließen können. […] Der
> einzige denkbare Umweg wären eigene Tools der Extension."

Der erste Satz stimmt nicht, der zweite auch nicht — und beides geht auf eine
unvollständige Fehlermeldung von uns zurück. Die ist repariert (unten unter
„Was von unserer Seite schon passiert ist"). Bleibt der eigentliche Punkt: es
gibt einen Weg, und er ist kleiner als „eigene Tools bauen".

## Was tatsächlich passiert

`content_update` prüft jedes übergebene Feld gegen die Palette des Typs, bevor
es irgendetwas schreibt. Eine Palette, die erst beim Öffnen des
Bearbeitungsformulars zusammengebaut wird (Onload-Callback, virtuelle Felder
über einer serialisierten Spalte), liefert dieser Prüfung **nichts** —
`DcaPalette::resolve()` findet keine Feldliste. Übrig bleiben die Basisfelder,
alles andere wird abgewiesen.

Das ist eine **Validierungsgrenze, keine Rechtegrenze.** Es ist keine
Permission-Prüfung, kein Schutzmechanismus und nichts, was mit mehr Rechten
anders ausginge. Der Grund ist schlicht: Das MCP-Bundle kann nicht wissen, wie
ein gültiger Wert für `netzhirsch_component_values` aussieht, und rät nicht.
Ein blind geschriebener serialisierter Blob ist genau die Art Schreibvorgang,
die ein Element still zerlegt.

Richtig am Bericht des Agenten ist: In der beobachteten Installation **war**
nichts vorhanden, was das Feld beschreibbar macht. Falsch ist die Begründung —
und die Schlussfolgerung, es gebe nur den Weg über eigene Tools.

## Der kleine Weg: ein `FieldProvider`

Das MCP-Bundle hat seit Langem einen Plugin-Punkt genau dafür — bisher benutzt
von changelanguage und dem Bootstrap-Bundle:
[`src/Tool/Contract/FieldProvider.php`](../../src/Tool/Contract/FieldProvider.php).

Ein Provider ist ein normaler Symfony-Service in **eurem** Bundle, getaggt mit
`netzhirsch.field_provider`. Das MCP-Bundle sammelt ihn über einen
Tagged-Iterator ein; euer Bundle bekommt dadurch **keine** Abhängigkeit zur
Laufzeit, die es vorher nicht hatte (das Interface ist eine Compile-Time-Klasse,
`class_exists`-Prüfung genügt, falls ihr ohne MCP-Bundle lauffähig bleiben
wollt).

Entscheidend, und der Grund, warum das für euch der richtige Weg ist:

> **Von einem Provider deklarierte Felder sind von der Paletten-Prüfung
> ausgenommen.** Sie landen in der Erlaubt-Liste, bevor die Prüfung läuft — die
> Stelle ist `FieldMapper::allowedFieldsFor()`, letzter `array_merge`-Eintrag.
> Die Validierung des Werts macht danach **euer** Provider, nicht wir. Ihr
> besitzt das Format, also gehört die Prüfung zu euch.

### Gerüst

```php
namespace Netzhirsch\ContaoComponentBundle\Mcp;

use Contao\Model;
use Netzhirsch\ContaoMcpBundle\Tool\Contract\FieldProvider;

final class ComponentFieldProvider implements FieldProvider
{
    public function getTable(): string
    {
        return 'tl_content';
    }

    public function getRequiredExtension(): string
    {
        return 'netzhirsch/contao-component-bundle';
    }

    public function isAvailable(): bool
    {
        return true; // ihr seid ja installiert, wenn dieser Service existiert
    }

    /** @return list<string> */
    public function getDeclaredFields(): array
    {
        // Bitte NICHT die Rohspalte anbieten — siehe unten.
        return ['component_headline', 'component_text', 'component_link', 'component_image'];
    }

    /** @return list<string> */
    public function getAllowedFields(?string $type): array
    {
        return str_starts_with((string) $type, 'netzhirsch_component_')
            ? $this->getDeclaredFields()
            : [];
    }

    /** @return array<string, mixed> */
    public function serialize(Model $model): array
    {
        // Aus netzhirsch_component_values die Einzelwerte herausgeben.
    }

    /** @return list<string> */
    public function apply(Model $model, array $input, bool $detectChanges): array
    {
        // Werte prüfen, in die serialisierte Struktur einsetzen,
        // netzhirsch_component_values neu setzen, geänderte Feldnamen
        // zurückgeben. Bei ungültigem Wert: \InvalidArgumentException —
        // die Meldung landet beim Aufrufer, gespeichert wird nichts.
    }
}
```

```yaml
# config/services.yaml in eurem Bundle
services:
    Netzhirsch\ContaoComponentBundle\Mcp\ComponentFieldProvider:
        tags: ['netzhirsch.field_provider']
```

### Drei Empfehlungen zur Ausgestaltung

1. **Einzelfelder statt Rohspalte.** Deklariert `component_headline`,
   `component_text` … und nicht `netzhirsch_component_values`. Ein Agent, der
   eine Überschrift übersetzen soll, kann dann genau das tun. Bekommt er die
   Rohspalte, muss er serialisieren — und dann ist der Blob wieder ungeprüft,
   nur diesmal mit unserem Segen.
2. **Teilweise Updates müssen gehen.** `apply()` bekommt nur die Felder, die der
   Aufrufer geschickt hat. Wer `component_headline` schreibt, darf `component_text`
   nicht verlieren: erst die bestehende Struktur laden, dann den einen Wert
   ersetzen. Genau dieser Fall — Übersetzen eines einzelnen Textes — ist der
   Auslöser dieses Briefings.
3. **Pro Komponententyp unterschiedliche Felder** löst ihr über
   `getAllowedFields($type)`; `getDeclaredFields()` bleibt die Vereinigungsmenge
   (die braucht das MCP-Bundle, um „Feld gehört zu einer nicht installierten
   Erweiterung" von „Feld gibt es nicht" zu unterscheiden).

## Der große Weg: eigene MCP-Werkzeuge

Wenn eure Komponenten mehr brauchen als Feld-für-Feld-Schreiben — etwa „Karte
anlegen mit Bild, Titel, Link in einem Rutsch" oder Operationen über mehrere
Elemente — ist der andere Plugin-Punkt der passende:
`McpToolProviderInterface` plus `McpToolPermissionProviderInterface`
(`src/Extension/`). Das ist der Weg, den der Agent gemeint hat; er ist nicht
falsch, nur größer. Beides schließt sich nicht aus.

**Wichtig, falls ihr diesen Weg geht:** Erweiterungswerkzeuge sind nach der
Installation **nicht automatisch sichtbar** — sie müssen im Backend unter
MCP-Server → Tools freigeschaltet werden. Das war in dem berichteten Fall
mutmaßlich mit der Grund, warum `installed_bundles` „keine zusätzlichen Tools"
meldete.

## Was von unserer Seite schon passiert ist

Ab **v1.28.0** nennen beide betroffenen Meldungen den Provider-Weg, nicht mehr
nur „die Erweiterung bringt üblicherweise eigene Werkzeuge mit", und sagen
ausdrücklich, dass es sich **nicht** um eine Rechteprüfung handelt:

- die Schreib-Abweisung in `FieldMapper::apply()`
- die Antwort von `content_palette_get` für einen Typ ohne statische Palette

Beide Formulierungen sind im Smoke-Test festgenagelt, damit sie nicht wieder
verloren gehen.

## Was ich nicht weiß

- Wie `netzhirsch_component_values` tatsächlich aufgebaut ist (Array-Struktur,
  Schlüsselnamen, ob pro Typ verschieden). Das entscheidet, wie
  `getDeclaredFields()` bei euch aussieht.
- Ob die Elemente Felder haben, die **nicht** in der serialisierten Spalte
  liegen, aber trotzdem zum Element gehören.
- Ob ihr das Bundle ohne MCP-Bundle lauffähig halten müsst. Falls ja: der
  Service darf nur registriert werden, wenn das Interface existiert — sonst
  scheitert der Container beim Kompilieren.

Bei allen dreien helfe ich gern konkret, wenn ich ins Repo sehen darf.
