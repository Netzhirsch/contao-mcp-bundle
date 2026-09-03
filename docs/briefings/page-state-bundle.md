# Briefing: Page-State-Bundle — die MCP-Werkzeuggruppe `pagestate_*`

**Für:** wer das Bundle betreut, das auf grass-merkur.de die `pagestate_*`-Werkzeuge
registriert
**Von:** Contao-MCP-Bundle (`netzhirsch/contao-mcp-bundle`)
**Stand:** 02.09.2026 · bezieht sich auf Beobachtungen vom EN-Rollout am 01.09.2026

> **Zur Belastbarkeit:** Ich habe den Code dieses Bundles **nicht** gesehen. Alles
> hier stammt entweder aus dem Verhalten der Werkzeuge auf der Live-Instanz oder
> aus dem, was das MCP-Bundle von seiner Seite aus sagen kann. Punkt 2 und 4 sind
> deshalb als **Fragen** formuliert, nicht als Befunde — mit Repo-Zugang prüfe ich
> sie am Code und mache Befunde daraus.

## Kontext

Beim englischen Rollout von grass-merkur.de wurde die Werkzeuggruppe zunächst
**nicht gefunden**. Der Agent hat daraus geschlossen, das Feld
`tl_page.netzhirschPageState` sei nur über den generischen `extras`-Beutel des
MCP-Bundles erreichbar, und es mit einem absichtlich ungültigen Wert getestet —
in der Annahme, eine Validierung weise ihn ab. Ergebnis: Seite 129 trug einen
17-Zeichen-Freitext statt ihrer Referenz.

Repariert wurde das mit `pagestate_assign(page_id: 129, state_id: 7)`, nachdem
die Gruppe doch noch gefunden wurde. Beide Seiten haben daraus etwas gelernt;
was das MCP-Bundle geändert hat, steht unten unter „Was von unserer Seite schon
passiert ist".

Beobachtete Werkzeuge:

| Werkzeug | Zweck (beobachtet) |
|---|---|
| `pagestate_list` | alle Zustände in Sortierreihenfolge |
| `pagestate_get` | **ein Zustand** per `id_or_alias` |
| `pagestate_assign` | Zustand einer Seite zuweisen oder löschen |
| `pagestate_create` / `_update` / `_delete` | Zustände pflegen |

## 1. Es gibt keinen Leseweg für den Zustand *einer Seite*

Das ist der eigentliche Wunsch.

Die Gruppe kann den Zustand einer Seite **setzen** (`pagestate_assign`), aber
nicht **lesen**. `pagestate_get` klingt danach, nimmt aber `id_or_alias` des
*Zustands* und liefert den Zustand — nicht den Zustand einer Seite. Wer wissen
will, was Seite 129 trägt, muss es über `pages_list(filters: {...})` erraten oder
über einen Dry-Run des Patch-Werkzeugs gehen.

**Vorschlag:** `pagestate_of_page(page_id)` → der zugewiesene Zustand oder `null`.
Optional eine Bulk-Variante (`page_ids: [...]`), weil der typische Aufruf „welchen
Zustand tragen die sieben Kinder von Seite 96?" lautet.

Warum das mehr ist als Bequemlichkeit: Ein Agent, der einen Wert nicht lesen
kann, kann eine Änderung weder vorbereiten noch gegenlesen noch zurückrollen.
Genau diese Asymmetrie — schreibbar, nicht lesbar — hat das MCP-Bundle für
Fremdfelder in 1.13.0 geschlossen; sie ist die teuerste Werkzeug-Eigenschaft, die
uns in drei Berichtsrunden begegnet ist.

## 2. Frage: Deklariert das Bundle seine Berechtigungen?

**Das ist vermutlich der billigste Fund hier, falls es zutrifft.**

Erweiterungs-Werkzeuge, die `McpToolPermissionProviderInterface` **nicht**
implementieren, fallen auf die sichere Vorgabe des MCP-Enforcers: **nur
Administratoren**. Sie erscheinen dann auch nicht in `tools/list` für
Nicht-Admins. Das fällt niemandem auf, solange nur mit einem Admin-Konto
getestet wird — und es sperrt genau die Redakteure aus, für die ein
Workflow-Status gedacht ist.

Zu prüfen:

```php
// src/…/Tool/PageStateTool.php
final class PageStateTool extends AbstractMcpTool implements McpToolPermissionProviderInterface
{
    public function getMcpToolPermissions(): array
    {
        return [
            'pagestate_list'   => ['kind' => 'dc', 'table' => 'tl_netzhirsch_page_state', 'op' => 'read'],
            'pagestate_get'    => ['kind' => 'dc', 'table' => 'tl_netzhirsch_page_state', 'op' => 'read'],
            'pagestate_assign' => ['kind' => 'dc', 'table' => 'tl_page', 'op' => 'update'],
            'pagestate_create' => ['kind' => 'dc', 'table' => 'tl_netzhirsch_page_state', 'op' => 'create'],
            // …
        ];
    }
}
```

Die genaue Form der Requirement-Arrays steht in `EXTENDING.md` des MCP-Bundles
und in `Security\ToolPermissionMap`. Wichtig dabei: Die Deklaration *gated* den
Aufruf, sie filtert **nicht** die Ausgabe — Listen müssen weiterhin selbst über
`permissionGuard()` gefiltert werden (`filterReadable`, `accessiblePageIds`).

Wir hatten denselben Fehler gerade im eigenen Haus: `entity_duplicate` fehlte in
unserer Permission-Map und war dadurch faktisch admin-only, ohne dass es
jemandem aufgefallen wäre (behoben in 1.16.0).

## 3. Auffindbarkeit der Gruppe

Die Gruppe blieb bei mehreren naheliegenden Suchen unsichtbar; gefunden wurde sie
erst über `state` und `page`. Von unserer Seite ist das inzwischen besser (siehe
unten), aber zwei billige Ergänzungen auf eurer Seite würden helfen:

- **Synonyme in die Werkzeug-Beschreibungen**, mit den Wörtern, die ein Mensch
  tippt: Status, Zustand, Workflow, Freigabe, Übersetzung nötig.
- **In der Beschreibung von `pagestate_assign` auf `pagestate_of_page` verweisen**
  (sobald es das gibt), damit der Rückweg im selben Text steht.

## 4. Frage: Namensraum der Werkzeuge

Die Werkzeuge heißen `pagestate_*`, nicht `netzhirsch_pagestate_*`. In einer
geteilten Registry — auf einer Instanz können mehrere Bundles Werkzeuge
registrieren — ist ein ungenamespacter Präfix ein Kollisionsrisiko, und es
weicht von der Hausregel ab, mit der wir Tabellen (`tl_netzhirsch_*`), Felder
(`netzhirsch*`) und BE_MOD-Gruppen benennen. Die Tabelle
`tl_netzhirsch_page_state` und das Feld `netzhirschPageState` halten sie ja ein.

**Das ist ausdrücklich keine Forderung.** Umbenennen bricht jeden bestehenden
Agenten-Aufruf und jede gespeicherte Anleitung. Falls ihr es tut, dann am besten
zusammen mit `pagestate_of_page` in einem Rutsch und mit den alten Namen als
Aliassen für eine Übergangszeit.

## Was von unserer Seite schon passiert ist

Damit klar ist, worauf ihr euch verlassen könnt:

- **1.13.0** — Der generische `extras`-Schreibweg **lehnt Referenzfelder jetzt ab**.
  Ein `page_update(id: 129, extras: {netzhirschPageState: "…"})` schreibt nichts
  mehr, sondern nennt die Zieltabelle und verweist auf die Werkzeugsuche. Der
  Vorfall von oben kann sich so nicht wiederholen.
- **1.15.0** — `contao_search_tools` zerlegt Bezeichner in Wörter (camelCase, `_`,
  `-`). `netzhirschPageState` und `tl_netzhirsch_page_state` finden die Gruppe
  jetzt über die Werkzeug**namen**, ohne dass ihr etwas tun müsst. Die
  Beschreibungen aus Punkt 3 wären zusätzlich, nicht stattdessen.
- **1.15.0** — Ablehnungen zeigen auf das zuständige Werkzeug, wo wir es kennen.
  Für `netzhirschPageState` heißt das derzeit „such mit diesen Wörtern"; wenn ihr
  wollt, tragen wir `pagestate_assign` namentlich ein — dann sagt die Meldung
  direkt, welcher Aufruf der richtige ist. Kurze Rückmeldung genügt.

## Was wir brauchen

1. Rückmeldung zu Punkt 2 — reicht ein Blick in die Klasse.
2. Ob wir `pagestate_assign` namentlich in unsere Fehlermeldung aufnehmen sollen.
3. Ob `pagestate_of_page` kommt, und ungefähr wann — dann können die
   Skill-/Agenten-Anleitungen darauf verweisen, statt den Umweg über
   `pages_list(filters:)` zu dokumentieren.
