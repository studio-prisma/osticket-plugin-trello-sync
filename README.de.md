# osTicket ↔ Trello Sync

Bidirektionale Synchronisation zwischen osTicket-Tickets und einem Trello-Board.

Bidirektionaler Sync für Ticket-Anlage, Status und Kommentare. Ein Board je Kunde, Sync opt-in pro Ticket. Bestandsabgleich als CLI-Werkzeug enthalten.

Aktuelle Version: `plugin.php` und [`CHANGELOG.md`](CHANGELOG.md). English version: [`README.md`](README.md).

Verifiziert gegen **PHP 8.3.33**: `php -l` sauber, **80 Unit-Tests grün**, keine Deprecations unter `E_ALL`.

Was noch aussteht: ein Lauf gegen eine echte osTicket-Instanz. Die osTicket-gekoppelten Klassen sind nicht unit-getestet — nur die reine Logik.

---

## Anforderungen

- osTicket **1.18.x**
- PHP **8.1+** (getestet 8.3.33) mit `curl`, `mbstring` und `hash`
  Fehlt eine davon, verweigert das Plugin das Speichern der Konfiguration.
- Helpdesk-URL über **https** (das Plugin verweigert sonst den Dienst)
- Ein Trello-Konto mit API-Key, Token und **API-Secret**

Keine Composer-Abhängigkeiten. Kein `vendor/`. Kein `composer install`.

---

## Installation

1. Den Ordner `trello-sync/` nach `/include/plugins/` auf dem osTicket-Server kopieren.
2. Vor der Aktivierung auf dem Server prüfen:
   ```bash
   cd /pfad/zu/osticket/include/plugins/trello-sync
   find . -name "*.php" -exec php -l {} \;
   ```
   Alle Dateien müssen `No syntax errors detected` melden.
3. Admin-Panel → Verwalten → Plugins → **Add New Plugin** → „Trello Sync" auswählen.
4. Plugin **installieren**, dann konfigurieren, dann **aktivieren**.

---

## Konfiguration

| Feld | Woher |
|---|---|
| API Key | https://trello.com/power-ups/admin → Power-Up anlegen → API Key |
| API Token | Auf derselben Seite über den Token-Link, Scope `read,write` |
| **API Secret** | Steht neben dem API Key. **Pflichtfeld** — ohne Secret keine Signaturprüfung und damit keine Aktivierung. |
| Member-ID des Tokens | `https://api.trello.com/1/members/me?key=KEY&token=TOKEN` → Feld `id` |
| Board-Liste | Auswahl der Custom List, die die Kunden-Boards enthält (siehe unten) |
| Feldname im Ticket-Formular | Variablenname des Listenfeldes, Default `trello_board` |

### Board-Registry einrichten (ein Board je Kunde)

Die Boards werden **nicht** in der Plugin-Config gepflegt, sondern als osTicket-Custom-List. Dieselbe Liste ist gleichzeitig das Auswahlfeld im Ticket — eine Pflegestelle statt zwei.

**1. Liste anlegen**
Admin-Panel → Verwalten → Listen → **Add New Custom List**, z. B. „Trello Boards".

**2. Properties definieren**
In der Liste auf **Properties** und vier Felder anlegen. Die *Variablennamen* müssen exakt so lauten:

| Variablenname | Typ | Inhalt |
|---|---|---|
| `board_id` | Short Answer | Trello-Board-ID, 24 Hex-Zeichen |
| `inbox_list_id` | Short Answer | Ziel-Liste für neue Karten, 24 Hex |
| `default_dept_id` | Short Answer | Department-ID für Tickets aus diesem Board |
| `default_user_email` | Short Answer | Absender für Tickets aus diesem Board |

Board- und List-ID ermittelst du, indem du die Board-URL mit angehängtem `.json` aufrufst — `id` auf oberster Ebene ist das Board, die Listen stehen unter `lists`.

**3. Je Kunde einen Eintrag** anlegen und die vier Properties füllen. Der Eintragsname ist der Kundenname und erscheint so im Ticket-Dropdown.

**4. Feld ins Ticket-Formular** aufnehmen
Admin-Panel → Verwalten → Formulare → Ticket Details → Feld vom Typ dieser Liste hinzufügen, Variablenname `trello_board`.

Unvollständig gepflegte Einträge werden **übersprungen**, nicht halb verarbeitet. Der Grund steht im osTicket-Log.

### Sync steuern

Das Board-Feld im Ticket entscheidet:

| Feldwert | Verhalten |
|---|---|
| leer | **kein Sync** — das ist der Default |
| Board A | Karte auf Board A |
| A → B geändert | dieselbe Karte wird nach B **verschoben**, nicht dupliziert |
| gesetzt → leer | Sync stoppt, **Karte bleibt bestehen** |

Es wird nie etwas in Trello gelöscht.

Die Callback-URL wird automatisch aus der in osTicket hinterlegten Helpdesk-URL gebildet:
`https://<helpdesk>/api/trello`

Sie wird **nie** aus dem Host-Header abgeleitet. Das ist Absicht: die URL geht in die HMAC-Berechnung ein, ein manipulierbarer Host wäre ein Angriffsvektor.

---

## Sicherheit

Eingehende Webhooks werden per **HMAC-SHA1** verifiziert:

```
base64( HMAC-SHA1( requestBody + callbackURL, apiSecret ) )
```

Der Vergleich läuft über `hash_equals()` und ist damit timing-safe.

**Bewusst nicht implementiert:** IP-Allowlist und Auswertung von `X-Real-IP` / `X-Forwarded-For`. Das Referenz-Plugin (`kyleladd/OSTicket-Trello-Plugin`) prüfte ausschließlich `$_SERVER['HTTP_X_REAL_IP']` gegen vier fest verdrahtete AWS-Adressen von 2016. Dieser Header ist vom Client frei setzbar — der Endpunkt war damit praktisch unauthentifiziert.

Weitere Maßnahmen:

- **Fail-Fast:** ohne Secret oder ohne https lässt sich die Konfiguration nicht speichern. Es gibt keinen Code-Pfad, der ohne Secret verarbeitet.
- **Reihenfolge:** roher Body lesen → Signatur prüfen → *erst dann* JSON dekodieren.
- **Fehler sanitisiert:** Ablehnungen liefern immer `401` mit identischem Body. Gründe stehen ausschließlich im osTicket-Log.
- **Payload-Limit:** 256 KB.
- **Board-Bindung:** Ereignisse fremder Boards werden verworfen.
- **Kartenauflösung:** ausschließlich über die Mapping-Tabelle. Nie wird eine ID aus dem Payload direkt als Ticket-ID interpretiert.

---

## Loop-Schutz

Zwei Ebenen, damit eine Änderung nicht endlos zwischen den Systemen pendelt:

1. **Urheber-Prüfung (primär).** Jeder Trello-Webhook enthält `action.memberCreator.id`. Stimmt sie mit der konfigurierten Member-ID überein, war es die eigene Schreiboperation → verwerfen. Deterministisch, kein Textvergleich.
2. **Payload-Hash (sekundär).** In `ost_trello_map` liegt `last_sync_hash`. Ist der zu schreibende Wert identisch mit dem zuletzt synchronisierten, wird nicht geschrieben.

---

## Öffentlich vs. intern

**Interne Notizen verlassen osTicket unter keinen Umständen.**

Synchronisiert werden ausschließlich ThreadEntry-Typ `M` (Ursprungsnachricht) und `R` (öffentliche Antwort). Typ `N` (interne Notiz) wird nie übertragen. Die Prüfung ist als Positivliste implementiert — unbekannte Typen werden ebenfalls nicht übertragen.

Der Filter greift, **bevor** ein Trello-Request entsteht. Bei einer internen Notiz verlässt kein einziges Byte den Server. `test_rules.php` prüft das mit 17 Fällen, inklusive Groß-/Kleinschreibung, Typverwechslungen und unbekannten Typen.

---

## Bestandsabgleich

Für den einmaligen Abgleich vorhandener Tickets und Karten:

```bash
cd /pfad/zu/osticket/include/plugins/trello-sync
php tools/reconcile.php                      # Dry-Run — schreibt nichts
php tools/reconcile.php --apply --limit=50   # führt aus
php tools/reconcile.php --board=<board_id>   # nur ein Board
```

Dry-Run ist Default. Der Report hat drei Abschnitte:

- **A** — Tickets mit Board, aber ohne Karte → werden angelegt
- **B** — Karten, die per Ticketnummer im Titel zu einem Ticket passen → **Vorschlag, bitte prüfen**
- **C** — Karten ohne Ticket → werden **nicht** automatisch angelegt

Abschnitt C bleibt bewusst manuell: daraus entstünden Tickets mit echten Kundendaten und automatischen Benachrichtigungen.

**Vor dem ersten `--apply`: Datenbank sichern.** Das Skript drosselt auf ~1 Request/Sekunde und hat eine Obergrenze je Lauf (Default 200).

Es ist ausschließlich über die Kommandozeile lauffähig — ein Aufruf über den Webserver wird mit 404 abgewiesen.

---

## Dateien

| Datei | Zweck |
|---|---|
| `plugin.php` | Manifest |
| `config.php` | Konfigurationsfelder + Fail-Fast-Gate |
| `trello_sync.php` | Hauptklasse, Signal-Verdrahtung, Webhook-Registrierung |
| `api.trello_sync.php` | Webhook-Endpunkt, Sicherheitskern |
| `class.trello_signature.php` | HMAC-Verifikation, abhängigkeitsfrei und unit-testbar |
| `class.trello_map.php` | Mapping Ticket ↔ Karte, DB-Schema |
| `class.trello_http.php` | cURL-Client mit Backoff bei HTTP 429 |
| `class.trello_board.php` | Board-Value-Object, reine Validierung, unit-testbar |
| `class.trello_registry.php` | Liest die Boards aus der Custom List |
| `class.trello_boardstore.php` | Webhook-IDs je Board |

---

## Tests

```bash
php tests/test_signature.php   # 13 Tests — HMAC-Verifikation
php tests/test_board.php       # 24 Tests — Board-Validierung
php tests/test_rules.php       # 43 Tests — Sync-Regeln, Schwerpunkt Notizfilter
```

Diagnose bei Problemen — gibt den kompletten Zustand aus:

```bash
php tools/diagnose.php
```

Änderungshistorie: `CHANGELOG.md`.

`test_signature.php` deckt Happy Path, alle Fehlpfade und zwei Regressionstests gegen die Lücken des Referenz-Plugins ab. `test_board.php` prüft die Registry-Validierung inklusive aller Pflichtfelder und Formatregeln. Exit-Code 0 = grün.

Verifiziert gegen **PHP 8.3.33** mit `error_reporting=E_ALL` — keine Deprecations.

Struktureller Integritätscheck (ersetzt kein `php -l`):

```bash
python3 tools/verify_structure.py *.php tests/*.php
```

---

## Abnahme Slice 0

- [ ] `php -l` auf allen Dateien grün
- [ ] `php tests/test_signature.php` → 0 rot
- [ ] Plugin installiert und konfiguriert
- [ ] `GET https://<helpdesk>/api/trello` → `200 {"status":"ok"}`
- [ ] `POST https://<helpdesk>/api/trello` ohne Header → `401 {"error":"unauthorized"}`
- [ ] Speichern ohne Secret → Fehlermeldung, keine Übernahme
- [ ] Tabelle `ost_trello_map` existiert
- [ ] Webhook taucht unter `https://api.trello.com/1/tokens/TOKEN/webhooks?key=KEY&token=TOKEN` auf

Smoke-Test für den 401-Pfad:

```bash
curl -i -X POST https://<helpdesk>/api/trello \
     -H 'Content-Type: application/json' \
     -d '{"action":{"type":"updateCard"}}'
# erwartet: HTTP/1.1 401, Body {"error":"unauthorized"}

# Gegenprobe: gefälschter X-Real-IP darf nichts ändern
curl -i -X POST https://<helpdesk>/api/trello \
     -H 'X-Real-IP: 203.0.113.10' \
     -H 'Content-Type: application/json' \
     -d '{"action":{"type":"updateCard"}}'
# erwartet: ebenfalls 401
```

---

## Nicht enthalten

Anhänge · interne Notizen · Fälligkeitsdaten · Labels · Mitglieder-Zuweisungen · Checklisten · mehrere Boards.

---

## Lizenz

GPL-2.0-or-later, kompatibel zum osTicket-Core.

Eigenständige Implementierung. `kyleladd/OSTicket-Trello-Plugin` diente als Referenz für das Verhalten, enthält aber **keine LICENSE-Datei** — es wurde daraus kein Code übernommen.
