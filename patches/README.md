# Vendor patches — Altlast, nur noch für Bestandsinstallationen

**Das Bundle braucht diese Patches seit 1.4.1 nicht mehr.** Es wendet sie nicht
an, verlangt kein `cweagans/composer-patches` und funktioniert mit einem
komplett ungepatchten `php-mcp/server`. Was der Dispatcher-Patch konnte
(Lazy-Mode-Filter für `tools/list`, Post-Call-Cleanup), liegt jetzt in
`src/Server/ContaoDispatcher.php` als Subklasse. Der Transport-Patch hing an
`StreamableHttpServerTransport` — dem ReactPHP-Daemon, den das Bundle seit dem
Wechsel auf HTTP-only nicht mehr benutzt.

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

Dieser Block zeigt auf genau diese Dateipfade. Wären sie weg, würde
`cweagans/composer-patches` bei der nächsten (Neu-)Installation von
`php-mcp/server` ins Leere greifen — und zwar hart: der Fehlerpfad für „Datei
nicht gefunden" endet in einem `TypeError` aus `RemoteFilesystem::copy()`, und
ein `TypeError` ist ein `\Error`, kein `\Exception`. Der `catch (\Exception)`,
der sonst „Could not apply patch! Skipping." ausgibt und weiterläuft, greift
also **nicht**. Ergebnis: `composer install` bricht mit Exit 1 ab und schreibt
nicht einmal mehr `vendor/autoload.php`.

Deshalb bleiben die Dateien einen Major-Zyklus liegen. Bestandsinstallationen
updaten dadurch ohne jede Vorarbeit; die Patches landen weiterhin im Vendor,
laufen aber ins Leere, weil `ContaoDispatcher` beide gepatchten Methoden
ohnehin überschreibt.

## Aufräumen (optional, jederzeit)

In der Root-`composer.json` entfernen — die Reihenfolge ist egal:

1. den `extra.patches`-Eintrag für `php-mcp/server`
2. `"cweagans/composer-patches"` aus `require` (sofern nichts anderes es braucht)
3. `"cweagans/composer-patches": true` aus `config.allow-plugins`

Danach `composer update`. Der Vendor bleibt dabei **gepatcht**: entgegen der
naheliegenden Annahme erzwingt `cweagans/composer-patches` 1.7.3 keine
Neuinstallation, wenn die Patch-Liste schrumpft — nachgeprüft, der Vendor kam
auch mit noch installiertem Plugin unverändert aus dem Update. Das ist
funktional folgenlos, weil `ContaoDispatcher` beide gepatchten Methoden
überschreibt und der gepatchte Transport gar nicht erst instanziiert wird.

Wer den Vendor wirklich pristine haben will, holt ihn sich explizit:

```bash
composer reinstall php-mcp/server
```

## Entfernung

Diese Dateien verschwinden mit **2.0.0**. Bis dahin sind sie durch
`tests/Unit/Compat/ShippedPatchFilesTest.php` gegen versehentliches Löschen
gesichert.
