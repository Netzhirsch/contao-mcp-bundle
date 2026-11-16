# Vendor patches — Altlast, nur noch für Bestandsinstallationen

**Das Bundle braucht diese Patches seit 1.4.1 nicht mehr, und seit 1.41 gibt es
das Paket nicht mehr, auf das sie zielen.** Der Server läuft auf dem offiziellen
`mcp/sdk`; `php-mcp/server` wird nicht mehr installiert. Was der
Dispatcher-Patch konnte (Lazy-Mode-Filter für `tools/list`, Post-Call-Cleanup),
liegt in `src/Server/ToolListHandler.php` und `src/Server/ToolCallHandler.php`.
Der Transport-Patch hing an `StreamableHttpServerTransport` — dem
ReactPHP-Daemon, den das Bundle seit dem Wechsel auf HTTP-only nicht mehr
benutzt.

## Warum die Dateien trotzdem noch hier liegen

Bis 1.4.0 musste **jede** Installation diesen Block in ihre Root-`composer.json`
schreiben:

```jsonc
"extra": {
  "patches": {
    "php-mcp/server": {
      "…": "vendor/netzhirsch/contao-mcp-bundle/patches/transport-auth-and-oauth-metadata.patch",
      "…": "vendor/netzhirsch/contao-mcp-bundle/patches/dispatcher-tool-filter.patch"
    }
  }
}
```

Dieser Block zeigt auf genau diese Dateipfade. Solange irgendein Paket im
Projekt `php-mcp/server` doch noch mitbringt, würde `cweagans/composer-patches`
bei dessen (Neu-)Installation ins Leere greifen, wären die Dateien weg — und
zwar hart: der Fehlerpfad für „Datei nicht gefunden" endet in einem
`TypeError` aus `RemoteFilesystem::copy()`, und ein `TypeError` ist ein
`\Error`, kein `\Exception`. Der `catch (\Exception)`, der sonst „Could not
apply patch! Skipping." ausgibt und weiterläuft, greift also **nicht**.
Ergebnis: `composer install` bricht mit Exit 1 ab und schreibt nicht einmal
mehr `vendor/autoload.php`.

Deshalb bleiben die Dateien einen Major-Zyklus liegen. Bestandsinstallationen
updaten dadurch ohne jede Vorarbeit.

## Aufräumen (optional, jederzeit)

In der Root-`composer.json` entfernen — die Reihenfolge ist egal:

1. den `extra.patches`-Eintrag für `php-mcp/server`
2. `"cweagans/composer-patches"` aus `require` (sofern nichts anderes es braucht)
3. `"cweagans/composer-patches": true` aus `config.allow-plugins`

Danach `composer update`.

## Entfernung

Diese Dateien verschwinden mit **2.0.0**. Bis dahin sind sie durch
`tests/Unit/Compat/ShippedPatchFilesTest.php` gegen versehentliches Löschen
gesichert.
