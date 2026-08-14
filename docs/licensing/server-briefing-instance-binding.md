# Server-Briefing: Besitznachweis via `instance_secret`

**Status:** Bundle-Seite ist ab **v1.0.9** fertig und im Feld. Der Lizenzserver
(`Netzhirsch/license-server`) muss nachziehen — bis dahin ignoriert er die neuen
Felder einfach, nichts bricht.

**Zweck:** Schließt eine Lücke in der Lizenz-Integrität (kein Kundendaten-Risiko,
aber ein Umsatzleck).

---

## 1. Das Problem

`POST /renew` prüft heute nur `product` + `domain`
(`src/Controller/RenewController.php`, `if ('' === $productSlug || '' === $domainRaw)`).
Das mitgeschickte `token` wird **nie validiert**, die Lizenz wird über
`findForDomain()` allein anhand der Domain gefunden.

Domains zahlender Kunden sind öffentlich. Damit genügt:

```bash
curl -X POST https://license.netzhirsch.de/renew \
  -H 'Content-Type: application/json' \
  -d '{"product":"netzhirsch/contao-mcp-bundle","domain":"kunde-mit-abo.de"}'
# -> 200 {"token":"<gültiges, signiertes Token>"}
```

Das Token auf einer beliebigen eigenen Instanz aktivieren, `backend_url` auf
`kunde-mit-abo.de` setzen — fertig. Volle Nutzung ohne Bezahlung, unbegrenzt
verlängerbar. Dasselbe gilt für `POST /portal-session`: dort ließe sich das
**Stripe-Kundenportal eines Fremden** öffnen (Zahlungsmittel ändern, kündigen).

Die Lücke ist **älter als 1.0.8** — der Token wurde nie geprüft. Neu ist nur,
dass der tokenlose Abruf seit 1.0.8 der normale Weg ist (selbst-aktivierende
Buttons), sie also praktisch relevant wird.

## 2. Die Regel

> **Tokenlos nur bei der Erstaktivierung.** Sobald für eine Domain eine Lizenz an
> eine Installation gebunden ist, muss jeder weitere Abruf das `instance_secret`
> dieser Installation vorlegen.

## 3. Was das Bundle bereits sendet und speichert

| Feld | `/trial` | `/renew` | `/portal-session` |
|---|:--:|:--:|:--:|
| `product`, `domain` | ✔ | ✔ | ✔ |
| `account_email` | ✔ | – | – |
| `token` (aktuell gespeichert, ggf. `""`) | – | ✔ | ✔ |
| **`instance_secret`** (`""` vor der ersten Bindung) | ✔ | ✔ | ✔ |

Antwortet der Server mit einem Feld **`instance_secret`**, speichert das Bundle
es dauerhaft in `var/mcp/license.json` und legt es ab dann jedem Aufruf bei
(`LicenseStore::setInstanceSecret()`, `RenewalClient::post()`). Es wird nie
geloggt und nie im Backend angezeigt.

## 4. Serverseitig umzusetzen

**Datenmodell:** `License` (bzw. `Subscription`) bekommt
`instance_secret_hash` (nullable) + `bound_at` (nullable).
Das Secret **gehasht** speichern (`password_hash()` oder HMAC) — es ist ein
Zugangsgeheimnis, kein Identifier.

**Ausstellen (Bindung):** Ist `instance_secret_hash` NULL, ist der Aufruf die
Erstaktivierung: neues Secret erzeugen (`bin2hex(random_bytes(32))`), Hash
speichern, `bound_at` setzen und das Secret **im Klartext genau dieses eine Mal**
mit ausliefern:

```json
{ "token": "…", "expires_at": 1234567890, "instance_secret": "…" }
```

**Prüfen:** Ist `instance_secret_hash` gesetzt, muss der Aufruf ein passendes
Secret mitbringen (zeitkonstant vergleichen). Sonst:

```
403 {"error":"instance_mismatch",
     "message":"A license for this domain is already bound to another installation."}
```

Das Bundle behandelt diesen Code bereits: es zeigt eine erklärende Meldung und
startet **weder** eine Testphase **noch** einen Checkout (sonst Doppelbelastung
bzw. verbrannter Trial).

**Gilt für:** `/renew`, `/trial` und `/portal-session`.
`/checkout-session` bleibt frei (dort entsteht die Lizenz ja erst).

**Freie Staging-Lizenzen** (`StagingPolicy::isFree()`) sollten **nicht** gebunden
werden — sie werden ohnehin bei jedem Aufruf frisch geprägt, und eine Bindung
würde Wegwerf-Umgebungen unnötig verkomplizieren.

## 5. Bindung lösen (Support-Fall, wird gebraucht)

Legitime Fälle: Server-Umzug ohne `var/`, Restore aus einem alten Backup,
Neuinstallation. Das Admin-Backend braucht deshalb **„Bindung lösen"** →
`instance_secret_hash = NULL`, Eintrag ins `AuditLog`. Der nächste Aufruf der
Kundeninstanz bindet dann neu.

Ohne diese Funktion erzeugt die Regel Support-Tickets, die niemand auflösen kann.

## 6. Reihenfolge & Rückwärtskompatibilität

1. Server ausrollen. Bestehende Lizenzen haben `instance_secret_hash = NULL` →
   der **nächste** Aufruf jeder Instanz bindet sie automatisch (der stündliche
   Cron erledigt das binnen Stunden, ohne Zutun der Kunden).
2. Alte Bundle-Versionen (< 1.0.9) senden kein `instance_secret`. Sie würden nach
   ihrer Bindung `instance_mismatch` bekommen. Solange nennenswert viele Instanzen
   auf < 1.0.9 laufen: entweder erst binden, wenn ein Secret **mitkommt**
   (empfohlen — dann binden sich nur aktuelle Installationen), oder eine
   Übergangsfrist definieren.

**Empfehlung:** Nur binden, wenn der Aufruf ein nicht-leeres `instance_secret`
mitschickt **oder** die Antwort ein neues Secret ausliefert — also nie
rückwirkend gegen alte Clients erzwingen.

## 7. Testfälle

| Fall | Erwartung |
|---|---|
| Erstaktivierung, `instance_secret: ""`, keine Bindung | `200` + `instance_secret` in der Antwort, Bindung gesetzt |
| Zweiter Aufruf derselben Instanz mit korrektem Secret | `200`, Secret wird **nicht** erneut ausgeliefert |
| Fremder Aufruf, `instance_secret: ""`, Domain bereits gebunden | `403 instance_mismatch` |
| Fremder Aufruf mit falschem Secret | `403 instance_mismatch` |
| `/portal-session` ohne gültiges Secret | `403 instance_mismatch` (kein Stripe-Portal-Link!) |
| Nach „Bindung lösen" im Admin | nächster Aufruf bindet neu, `200` + neues Secret |
| Widerrufene Lizenz | weiterhin `403 revoked` (hat Vorrang) |
| Staging-/Loopback-Domain | unverändert frei, ohne Bindung |

---

*Companion: [http-contract.md](./http-contract.md) (Endpunkte + Token-Format),
[license-server-briefing.md](./license-server-briefing.md) (Gesamtarchitektur).
Client-Seite: `src/License/RenewalClient.php` + `LicenseStore.php` ab v1.0.9.*
