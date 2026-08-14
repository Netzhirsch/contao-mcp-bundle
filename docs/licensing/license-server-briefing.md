# Briefing: Netzhirsch License Server

**Zweck.** Ein zentraler Lizenz- und Auslieferungsdienst für **mehrere** kommerzielle
Netzhirsch-Contao-Bundles (Start: `netzhirsch/contao-mcp-bundle`) mit **Preis-/
Abo-Verwaltung**. Er stellt signierte Laufzeit-Lizenztokens aus, erneuert sie
solange bezahlt wird, widerruft sie bei Bedarf (`403 revoked`), sperrt Trials
gegen Neustart und steuert — nur für privat gehaltene Produkte — den
Composer-Zugang. Die Client-Hälfte existiert bereits im Bundle — siehe
[http-contract.md](./http-contract.md); dieses Dokument spezifiziert die
Server-Hälfte.

Zielleser: wer den Server baut/beauftragt. Format: Briefing mit klaren Annahmen
und offenen Entscheidungen (Abschnitt 10).

---

## 1. Leitprinzipien

- **Token-Verifikation ist offline** (Ed25519 im Bundle). ⇒ Server-Ausfall bricht
  **keine** Kundeninstanz (Token laufen + 3 Tage Grace weiter). Der Server braucht
  daher **keine** Hochverfügbarkeit — er muss nur zu Trial-/Renew-Zeitpunkten
  erreichbar sein.
- **Zwei getrennte Schlüssel/Surfaces** (Veello-Modell):
  1. **Composer-Zugang** = Install/Update (Private Packagist, pro Kunde ein Token) —
     **nur** relevant, solange ein Produkt privat ausgeliefert wird; MCP ist
     öffentlich, hier greift allein Surface 2 (s. §6).
  2. **Laufzeit-Lizenz** = Feature-Freischaltung (unser signiertes Ed25519-Token).
  Der Server orchestriert beide (bei öffentlichen Paketen faktisch nur Surface 2).
- **Kein Geheimnis im Token** (Token ist nur signiert, nicht verschlüsselt —
  enthält nur product/domain/type/exp/license_id).
- **Preisverwaltung möglichst nicht selbst bauen** → an **Stripe Billing**
  delegieren (Products & Prices, MwSt via Stripe Tax, Rechnungen, Dunning). Der
  Server hält nur die Abbildung Stripe ↔ Bundle-Produkt + die Signier-/Trial-Logik.
- **Eine Edition, KEIN Fork, KEIN `-pro`-Build.** Ein einziges öffentliches Paket;
  der echte Public Key ist eingebacken → Enforcement gilt **immer**. Kein Config-/
  Env-/Build-Schalter (wäre kundenseitig umgehbar). Der Unterschied zwischen
  zahlendem Kunden und Netzhirsch-Instanz liegt **allein im ausgestellten Token**
  (Plan `internal` statt `full`), nicht im Code — siehe [editions.md](./editions.md).
- **Auto-Renew + Revoke.** Das Bundle erneuert den Token per Contao-Cron
  (`LicenseRenewalCron`, stündlich, auf einen echten Call/6 h gedrosselt). Derselbe
  `/renew`-Aufruf ist der Revoke-Kanal: `403 revoked` löscht den Token **sofort**
  (auch bei Dauer-/Internal-Lizenzen, ohne Grace); ein reiner Server-Ausfall
  dagegen fällt auf die 3-Tage-Grace zurück. Häufiges Renew heißt: der Token hat
  fast immer seine volle Laufzeit übrig → der Server darf lange ausfallen.
- **Bezahlt wird im eigenen Contao-Backend — aber auf Stripe-gehosteten Seiten.**
  Karten-/SEPA-Daten fassen weder Bundle noch Lizenz-Server je an (PCI bleibt bei
  Stripe). Das Backend hält nur Buttons/Status/Links; Zahlung + Abo-Verwaltung
  laufen über Stripe Checkout bzw. das Stripe Customer Portal.

---

## 2. Domänenmodell

| Entität | Kernfelder | Zweck |
|---|---|---|
| **Product (Bundle)** | slug (= Composer-Name), Anzeigename, aktiv, trial_days, aktive signing_key_id | Ein Eintrag pro verkauftem Bundle (multi-bundle-Kern) |
| **SigningKey** | product_id, public, secret (**verschlüsselt at rest**), created, active | **Pro Produkt ein Ed25519-Keypair** (Leak isoliert; Rotation möglich) |
| **Plan/Price** | product_id, stripe_price_id, name, Betrag, Währung, Intervall (monatl./jährl.), Tier, instances_allowed | Preise/Stufen — Quelle ist Stripe, hier nur gespiegelt |
| **Customer** | email, Firma, USt-IdNr., stripe_customer_id | Abrechnungssubjekt |
| **Subscription / Entitlement** | product_id, **source (stripe\|internal)**, customer_id?, price_id?, status (trial/active/past_due/canceled/**internal**), current_period_end?, stripe_subscription_id?, instances_allowed | Anspruch je (Kunde × Produkt); bei `source=internal` **ohne** Stripe-Bezug |
| **License** | subscription_id?, product_id, domain (normalisiert), type (trial/full), license_id, issued_at, expires_at, **revoked** | Konkret ausgestellte Tokens (Audit/Revocation) |
| **Trial** | product_id, domain, account_email, issued_at | **Unikat-Sperre** (ein Trial pro Domain **oder** Konto **pro Produkt**) |
| **ComposerAccess** | customer_id, product_id, private_packagist_ref/token | Install-/Update-Zugang (**nur für privat gehaltene Produkte**, s. §6) |
| **AuditLog** | wer/was/wann | Nachvollziehbarkeit |

**Multi-Bundle** fällt damit natürlich: alles hängt an `product`. Das Bundle-Token
trägt bereits ein `product`-Feld; jedes Bundle bettet **seinen** Public Key ein.

**Internal-Lizenzen** (Netzhirsch-eigene Instanzen) sind Entitlements mit
`source=internal`: **kein** Stripe-Objekt (customer/price/stripe_* bleiben leer),
`status=internal`, das Token bleibt `type=full`. `/renew` stellt für sie **immer**
ein frisches Token aus, solange die zugehörige `License.revoked=false` ist. Der
Widerruf ist derselbe Hebel wie bei zahlenden Kunden: `revoked=true` setzen.

### 2a. Instanz-Identität & kostenlose Staging/Dev-Instanzen

Die **Lizenzeinheit ist die Contao-Instanz**, identifiziert über ihre
**Backend-Domain** (`backend_url`-Host) — genau der Host, an den das Bundle-Token
**schon heute** bindet. **Kein Client-Umbau, kein Multi-Token, kein Zählen von
Front-End-Domains.** Eine Instanz mit mehreren Front-End-Domains bleibt eine Lizenz.

- **Staging/Dev gratis (reine Server-Regel):** stellt `/trial` bzw. `/renew` fest,
  dass die Domain rein lokal ist (`localhost`, `127.0.0.1`, `*.local`, `*.test`,
  `*.ddev.site`) **oder** eine Subdomain einer bereits **bezahlten** registrable
  Domain (`staging.kunde.de`, `dev.kunde.de` bei bezahltem `kunde.de`), gibt der
  Server ein **kostenloses** Token aus (wie `internal`, aber automatisch per
  Muster) — ohne Abo, ohne Karte. Die eTLD+1-/Subdomain-Prüfung macht der **Server**
  (Public-Suffix-Liste); das **Bundle bleibt unverändert** (Gate = exakter
  Host-Vergleich, `www.` wird ohnehin gestrippt).
- **Missbrauch:** Produktion unter einer `staging.*`-Subdomain zu betreiben, um zu
  sparen, ist unüblich (öffentliche URLs/SEO) und self-limiting; der Hebel bleibt,
  dass ohne gültiges Token nichts läuft.

---

## 3. HTTP-API

Bestehender Vertrag (bereits vom Bundle konsumiert, product-parametrisiert →
multi-bundle-ready):

- `POST /trial`  `{product, domain, account_email}` → `{token, expires_at}` /
  `409 trial_already_used`. **Restart-Sperre:** Insert nur, wenn (product, domain)
  UND (product, account) noch nicht existieren.
- `POST /renew`  `{product, domain, token}` → `{token, expires_at}` /
  `402 subscription_inactive` (unbezahlt → nicht ausstellen; Token läuft in Grace
  aus) / `403 revoked` (**harter Kill**: Bundle löscht Token sofort; gilt für
  jeden Tier inkl. `internal`). Ausstellen nur bei `status ∈ {trial, active}`,
  `revoked = false` und `domain` ∈ erlaubte Domains. Erneuerung läuft im
  Kunden-Bundle automatisch (Cron); manueller Fallback `contao:mcp:license renew`.

Signaturen: Server lädt den aktiven SigningKey **des Produkts**, signiert das
Payload (`LicenseToken::sign()`-kompatibel: `base64url(json).base64url(sig)`,
Ed25519 detached). Trial-Token **30 T** (= „1 Monat kostenlos"), Full-Token etwas
länger als das Renew-Intervall (z. B. 35 T), damit ein verpasster Cron niemanden
aussperrt.

Ergänzend:
- `POST /checkout-session` `{product, domain, account_email, plan?}` → `{url}` —
  erzeugt eine **Stripe-Checkout-Session** (Abo abschließen). Backend öffnet die URL.
- `POST /portal-session` `{product, domain, token}` → `{url}` — **Stripe Customer
  Portal** (Karte/SEPA ändern, kündigen, Rechnungen). Backend öffnet die URL.
- `GET /catalog` (optional, öffentlich) — Produkte + Preise für einen Self-Service-Shop.
- `POST /webhook/stripe` — `checkout.session.completed`/`invoice.paid`/
  `customer.subscription.updated|deleted` → Subscription-Status +
  `current_period_end` fortschreiben, Lizenz-Token ausstellen/verlängern.
- **Admin-API/UI** (auth): Produkte/Preise, Kunden, Abos, Lizenzen
  (**revoke** setzt `revoked=true` → `/renew` liefert `403 revoked`; reissue),
  **interne Lizenzen** ausstellen (Plan `internal`: Entitlement dauerhaft & jederzeit
  revozierbar, Token bleibt kurzlebig + Auto-Renew) für Netzhirsch-eigene Instanzen,
  Composer-Zugänge provisionieren/entziehen, Audit-Log.

---

## 4. Preise & Pläne (via Stripe Billing) — festgelegt

- **Einheit = Contao-Instanz.** Eine Installation = **eine** Lizenz, **49 €/Monat**
  ODER **539 €/Jahr** (Jahres-Plan = **12 für 11**, ein Monat gratis). Netto, zzgl.
  MwSt. **Egal wie viele Front-End-Domains** die Instanz bedient — es bleibt eine
  Lizenz. Gebunden an die **Backend-Domain** (`backend_url`-Host), an die das Token
  **ohnehin schon bindet** → kein Client-Umbau, kein Multi-Token.
- **Staging/Dev frei:** eine Instanz, deren Backend-Host eine **Subdomain einer
  bezahlten Domain** ist (`staging.kunde.de`, `dev.kunde.de` bei bezahltem
  `kunde.de`) oder rein lokal (`localhost`, `127.0.0.1`, `*.local`, `*.test`,
  `*.ddev.site`), bekommt ein **kostenloses** Dauer-Token (Server-Regel, s. §2a).
- **Stripe Products & Prices** = Single Source of Truth; der Server spiegelt nur
  `stripe_price_id ↔ (product, plan)`. Zwei Preise je Produkt: `monthly` (49 €),
  `annual` (539 €).
- **Zahlwege:** Stripe Checkout mit **Karte UND SEPA-Lastschrift** (SEPA settelt
  asynchron; Stripe führt das Abo trotzdem sofort als `active` → Token wird
  ausgestellt, Zahlung läuft im Hintergrund nach).
- **Trial:** **30 Tage** („1 Monat kostenlos"), **ohne Karte** — aktive
  Kaufentscheidung am Ende; Restart-Sperre pro (product, domain|account).
- **Preisänderungen:** **alle migrieren** (mit Ankündigung/Frist). ABER
  **Jahreszahlung sichert den Preis für den laufenden Term** (faktischer
  Bestandsschutz 12 Monate); Monatszahler wandern zum neuen Preis.
- **MwSt/EU:** Stripe Tax (19 % DE, Reverse-Charge EU-B2B via USt-IdNr./VIES).

---

## 5. Abo-Lebenszyklus

`trial` → (Zahlung) `active` → (Fehl-/Nicht-Zahlung) `past_due` → `canceled`.
`/renew` gibt frisches Token nur bei `trial|active|internal`. Nach `canceled`
läuft das letzte Token bis `exp` + 3 T Grace, dann sperren die Tools (Core-Contao
bleibt). Bindung **pro Instanz** (Backend-Host); ein Abo = eine Contao-Instanz,
unabhängig von der Zahl der Front-End-Domains.

**Revoke (orthogonal, hart).** `License.revoked=true` ist **unabhängig** vom
Abo-Status: `/renew` liefert dann sofort `403 revoked`, das Bundle löscht den
Token **ohne** Grace. So kappst du jede Lizenz — auch eine `internal` — sofort,
ohne auf `canceled`/Ablauf zu warten. Kappungszeit ≈ eine Cron-/Renew-Runde
(Default ≤ ~6 h), nicht Token-TTL + Grace.

**Internal.** `source=internal`, `status=internal` — kein Stripe-Lebenszyklus;
`/renew` erneuert dauerhaft, bis ein Admin `revoked=true` setzt. „Unendlich
gültig" lebt also im Entitlement, nicht in der Token-Laufzeit (ein Token, der nie
nachfragt, wäre nicht revozierbar).

---

## 6. Composer-Auslieferung koppeln (nur für privat gehaltene Produkte)

**Für öffentliche Pakete wie MCP entfällt die Composer-Sperre als Paywall** — das
Paket ist im Contao Manager / Katalog frei installierbar, alleiniger Hebel ist das
Laufzeit-Token (ohne Token ist das Paket ein Briefbeschwerer). Der folgende
Kopplungsmechanismus ist nur relevant, falls ein künftiges Produkt bewusst
**privat** ausgeliefert wird (optionaler Zusatz-Hebel):

Bei erfolgreichem Abo/Trial: **Private-Packagist-Kundentoken** provisionieren
(Install/Update-Zugang, ggf. Versions-Range = gekaufte Major). Bei `canceled`:
Composer-Zugang entziehen (installierte Version läuft, aber keine Updates mehr).
So greifen Laufzeit-Lizenz **und** Update-Sperre ineinander (doppelter Hebel).

---

## 7. Sicherheit

- Secret Keys **pro Produkt**, **verschlüsselt at rest** (z. B. libsodium secretbox
  mit Server-Master-Key aus Secrets-Manager/ENV), nie im Repo, nie im Log.
- **Key-Rotation** je Produkt vorsehen (aktiver Key + Nachlaufzeit; Bundle könnte
  künftig mehrere Pubkeys akzeptieren — heute: ein Pubkey/Bundle).
- Rate-Limiting auf `/trial` + `/renew`; Stripe-Webhook-Signaturprüfung.
- Trial-Anti-Abuse: Unikat (product, domain|account) + optional E-Mail-Verifikation.
- Vollständiges Audit-Log (Ausstellungen, Revokes, Statuswechsel).

---

## 8. Tech-Empfehlung

- **Backend:** schlanke **Symfony**-App (API Platform optional) — nur API,
  Signierung, Trial-Sperre, Stripe-Sync, Admin.
- **Billing/Preise:** **Stripe Billing** (spart die halbe „Preisverwaltung").
- **Shop/Checkout:** **backend-getriebenes Stripe Checkout** — der Kunde startet
  Abo/Trial im eigenen Contao-Backend (MCP-Server → „Lizenz"), Zahlung auf
  Stripe-gehosteter Seite, Rückkehr ins Backend. Kein separater Isotope-Shop nötig
  (Entscheidung #1 damit erledigt).
- **Signierung:** `sodium_crypto_sign_detached` (identisch zur Bundle-Verify-Seite;
  `LicenseToken::sign()` als Referenz).
- **Hosting:** einfacher Server genügt (keine HA nötig, s. §1).

---

## 9. Milestones

1. **MVP (1 Produkt = MCP):** Stripe Products/Prices + Checkout, `POST /trial` +
   `POST /renew` (inkl. **`403 revoked`**) + Stripe-Webhook, **interne Lizenzen
   ausstellen + revoken** (Plan `internal`, ohne Stripe), Secret-Key verschlüsselt,
   minimales Admin. Bundle-Client bereits fertig → sofort testbar.
2. **Multi-Bundle:** Produkt-/Key-Registry generisch, weitere Bundles anlegbar,
   Katalog-Endpoint.
3. **Auslieferung:** Private-Packagist-Provisionierung automatisieren.
4. **Komfort:** Self-Service-Portal, Key-Rotation, Gutscheine, Reporting.

---

## 10. Entscheidungen (geklärt)

- ✅ **Billing-Modell:** Stripe Voll-Auto (Karte **+ SEPA**, Auto-Renew, Dunning, Rechnung+MwSt via Stripe Tax).
- ✅ **Shop-Frontend:** backend-getriebenes Stripe Checkout im eigenen Contao-Backend.
- ✅ **Auslieferung:** eine Edition, ein öffentliches Paket, kein Fork / kein `-pro`. Enforcement immer an; Unterschied nur im Token.
- ✅ **Interne Instanzen:** `internal`-Token (dauerhaftes, revozierbares Entitlement + Auto-Renew) — nicht Enforcement ausschalten.
- ✅ **Trial:** 30 Tage, **ohne Karte**, Restart-Sperre pro (product, domain|account).
- ✅ **Lizenzmetrik:** **pro Contao-Instanz** (gebunden an den `backend_url`-Host — genau wie schon implementiert), 49 €/Mon bzw. 539 €/Jahr (12 für 11). Eine Instanz mit mehreren Front-End-Domains = eine Lizenz. Staging/Dev-Instanzen (Subdomain einer bezahlten Domain oder lokal) frei (§2a).
- ✅ **Preisänderungen:** alle migrieren, aber Jahreszahlung sichert den Preis für den laufenden Term.
- ✅ **Ein Keypair pro Produkt:** ja (für MCP bereits umgesetzt).

**Kein Client-Umbau nötig.** „Pro Instanz" = exakt das aktuelle Bundle-Verhalten
(ein Token, an `backend_url` gebunden). Die Staging-/Local-Gratis-Regel lebt
**serverseitig** (kostenloses Token per Muster). Die Bundle-Seite ist damit
lizenzseitig **fertig** — offen ist nur noch der Lizenzserver selbst.

---

*Companion: [http-contract.md](./http-contract.md) (Endpunkt-Details + Token-Format).
Client-Seite: `src/License/` + `src/Cron/LicenseRenewalCron.php` im
`netzhirsch/contao-mcp-bundle` (eine Edition, Auto-Renew + Revoke).*
