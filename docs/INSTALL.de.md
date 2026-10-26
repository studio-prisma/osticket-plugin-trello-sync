# Installation: osTicket ↔ Trello Sync für osTicket 1.18.x

Die Reihenfolge unten ist bindend. Jeder Schritt hat einen Abbruchpunkt — schlägt eine Prüfung fehl, dort aufhören statt weiterzumachen und später auf einem laufenden Helpdesk zu debuggen.

[§0 Aussperr-Risiken](#0-aussperr-risiken-zuerst-lesen) vor dem ersten Handgriff lesen.

---

## 0. Aussperr-Risiken, zuerst lesen

Dieses Plugin kann dich nicht aus osTicket aussperren. Es baut kein Login-Gate und keine Feldvalidierung auf Agentenseite ein. Der realistische Schaden liegt woanders:

| Risiko | Was passiert | Vermeidung |
|---|---|---|
| **Kundendaten landen bei Trello** | Ticketbetreff und öffentliche Antworten werden auf ein US-gehostetes Board kopiert. Genau dafür ist das Plugin da, aber es ist eine Datenübermittlung, die du machen dürfen musst. | Vor Go-Live klären: AVV mit Atlassian, Eintrag im Verarbeitungsverzeichnis. Siehe [§9](#9-vor-dem-go-live). |
| **Falsches Board zugeordnet** | Ein Kunde sieht das Ticket eines anderen auf einem geteilten Board. | Ein Board je Kunde, geprüft in [§5](#5-board-registry). Nie ein gemeinsames Board. |
| **Interne Notizen wandern raus** | Tun sie nicht. Typ `N` wird gefiltert, bevor überhaupt ein Request entsteht — prüf es trotzdem selbst in [§8](#8-security-gate). |

Der Kill-Switch ist ein Klick: **Admin-Panel → Verwalten → Plugins → Trello Sync → deaktivieren**. Tickets und Karten bleiben wie sie sind, nichts wird gelöscht. Der Sync hört einfach auf.

---

## 1. Voraussetzungen prüfen

Auf dem Server:

```bash
php -v                                    # 8.1 oder neuer
php -m | grep -E '^(curl|mbstring|hash)$' # alle drei müssen erscheinen
```

In osTicket: Admin-Panel → Einstellungen → System → die **Helpdesk-URL** muss mit `https://` beginnen.

**Abbruch, wenn** PHP älter als 8.1 ist, eine Extension fehlt oder die Helpdesk-URL auf http steht. Das Plugin verweigert in allen drei Fällen das Speichern der Konfiguration — du merkst es sonst erst später und suchst an der falschen Stelle.

---

## 2. Trello-Zugangsdaten anlegen

1. https://trello.com/power-ups/admin öffnen und einen Power-Up anlegen. Name egal.
2. **API-Key** notieren.
3. Auf derselben Seite, direkt neben dem Key, das **API-Secret** notieren. Man übersieht es leicht, und es ist Pflicht — ohne Secret keine Signaturprüfung, und das Plugin lässt sich nicht aktivieren.
4. Über den Token-Link auf derselben Seite einen **Token** mit Scope `read,write` erzeugen.
5. Die Member-ID zu diesem Token holen:
   ```bash
   curl -s "https://api.trello.com/1/members/me?key=KEY&token=TOKEN" | grep -o '"id":"[^"]*"' | head -1
   ```

**Abbruch, wenn** du nur Key und Token hast. Das Secret ist nicht optional.

**Wem gehört der Token?** Gehört er deinem persönlichen Konto, erscheint jede Karte und jeder Kommentar des Plugins unter deinem Namen. Ein eigener Bot-Account ist sauberer. `tools/probe-auth.php` sagt dir hinterher, zu welchem Konto der Token tatsächlich gehört.

---

## 3. Dateien hochladen

Paket aus einem Checkout bauen oder das ZIP von der Release-Seite nehmen:

```bash
./tools/build-zip.sh
# → dist/trello-sync-<version>.zip
```

So entpacken, dass der Ordner hier liegt:

```
/pfad/zu/osticket/include/plugins/trello-sync/
```

Owner und Rechte an die Nachbarordner angleichen:

```bash
cd /pfad/zu/osticket/include/plugins
ls -la                                    # Owner und Modus eines Nachbarn ansehen
chown -R <web-user>:<web-group> trello-sync
find trello-sync -type d -exec chmod 755 {} \;
find trello-sync -type f -exec chmod 644 {} \;
```

Dann der Syntaxcheck, auf dem Server, nicht auf dem Laptop:

```bash
cd trello-sync
find . -name "*.php" -exec php -l {} \;
```

**Abbruch, wenn** eine Datei etwas anderes meldet als `No syntax errors detected`. Häufigste Ursache ist ein abgebrochener Upload — neu hochladen statt suchen.

---

## 4. Plugin installieren

Admin-Panel → Verwalten → Plugins → **Add New Plugin** → „Trello Sync" → **Install**.

Noch **nicht** aktivieren. Erst konfigurieren, aktivieren zuletzt. Ein aktiviertes Plugin ohne Board-Registry schreibt zwar nichts, loggt aber bei jedem Ticket-Ereignis, und dieses Rauschen macht die nächsten Schritte unlesbar.

Konfiguration ausfüllen:

| Feld | Wert |
|---|---|
| API Key | aus §2 |
| API Token | aus §2 |
| API Secret | aus §2 |
| Member-ID | aus §2 |
| Board-Liste | die Custom List aus §5 — erst dort anlegen, dann zurückkommen |
| Feldname im Ticket-Formular | `trello_board`, wenn du keinen Grund für etwas anderes hast |

**Abbruch, wenn** sich die Konfiguration nicht speichern lässt. Die Meldung nennt den Grund: fehlende Extension, fehlendes Secret oder eine Helpdesk-URL ohne HTTPS. Alle drei sind §1- und §2-Probleme, keine Konfigurationsprobleme.

---

## 5. Board-Registry

Boards liegen in einer osTicket-Custom-List, nicht in der Plugin-Config. Dieselbe Liste ist gleichzeitig das Dropdown im Ticket-Formular.

**5.1** Admin-Panel → Verwalten → Listen → **Add New Custom List**, z. B. „Trello Boards".

**5.2** Auf **Properties** gehen und vier Felder anlegen. Die Variablennamen müssen exakt stimmen — ein Tippfehler hier erzeugt Einträge, die stillschweigend übersprungen werden:

| Variablenname | Typ |
|---|---|
| `board_id` | Short Answer |
| `inbox_list_id` | Short Answer |
| `default_dept_id` | Short Answer |
| `default_user_email` | Short Answer |

**5.3** IDs ermitteln. Board-URL mit angehängtem `.json` im eingeloggten Browser aufrufen:

```
https://trello.com/b/<shortlink>/<name>.json
```

`id` auf oberster Ebene ist die Board-ID. Die Ziel-Liste für neue Karten steht unter `lists`, jede mit eigener `id`. Beide sind 24 Hex-Zeichen lang.

**5.4** Je Kunde einen Eintrag anlegen, alle vier Properties gefüllt. Der Eintragsname erscheint im Dropdown der Agenten, also den Kundennamen nehmen.

**5.5** Admin-Panel → Verwalten → Formulare → Ticket Details → Feld vom Typ dieser Liste hinzufügen, Variablenname `trello_board`.

**Abbruch, wenn** sich ein Eintrag nicht vollständig füllen lässt. Unvollständige Einträge werden übersprungen, und das sieht man nur im Log.

---

## 6. Aktivieren und Verdrahtung prüfen

Plugin aktivieren. Dann:

```bash
cd /pfad/zu/osticket/include/plugins/trello-sync
php tools/diagnose.php
```

Das gibt den vollständigen Zustand aus: welche Zugangsdaten vorhanden sind (maskiert, nie vollständig), ob der Token gültig ist, zu welchem Konto er gehört, welche Rechte er hat und welche Board-Einträge geparst wurden.

**Abbruch, wenn** diagnose einen fehlenden Key, einen ungültigen Token oder null geparste Board-Einträge meldet. Alles danach hängt an diesen drei Punkten.

---

## 7. Smoke-Test, in dieser Reihenfolge

**7.1 osTicket → Trello.** Testticket anlegen, Board-Feld setzen, speichern. Innerhalb von Sekunden muss eine Karte in der konfigurierten Inbox-Liste erscheinen. Wenn nicht: nochmal `php tools/diagnose.php` und ins osTicket-Log schauen.

**7.2 Öffentliche Antwort.** Ticket öffentlich beantworten. Der Text muss als Kommentar an der Karte erscheinen.

**7.3 Interne Notiz.** Eine interne Notiz hinzufügen. **In Trello darf nichts erscheinen.** Wenn doch: Plugin sofort deaktivieren und einen Security-Report einreichen — das ist der Fehlerfall, der am meisten zählt.

**7.4 Trello → osTicket.** Als *anderer* Trello-Nutzer als der Token-Besitzer einen Kommentar an der Karte schreiben. Er muss im Ticket als **interne Notiz** landen, nicht als öffentliche Antwort.

**7.5 Loop-Prüfung.** Eigene Schreibvorgänge dürfen nicht zurückwandern. Öffentlich in osTicket antworten, zusehen wie die Karte den Kommentar bekommt, und prüfen dass kein zweiter Kommentar im Ticket auftaucht.

**7.6 Board-Wechsel.** Board-Feld auf ein anderes Kundenboard ändern. Die Karte muss **verschoben** werden, nicht dupliziert.

Hinweis zu 7.4: Kommt der Kommentar nicht an, ist womöglich die eingehende Richtung inaktiv. Trello registriert den Webhook gegen deine Callback-URL und verweigert, wenn diese URL seine Validierungsanfrage nicht mit `200` beantwortet. Eine WAF oder Bot-Protection vor dem Helpdesk ist die übliche Ursache — `VALIDATOR_URL_RETURNED_ERROR` im Log heißt, Trellos Validator bekam ein `403`. Die ausgehende Richtung funktioniert davon unabhängig.

---

## 8. Security-Gate

Vor dem Abhaken der Installation ausführen. Beide Anfragen müssen `401` mit identischem Body liefern.

```bash
# Ganz ohne Signatur
curl -i -X POST https://<helpdesk>/api/trello \
     -H 'Content-Type: application/json' \
     -d '{"action":{"type":"commentCard"}}'
# erwartet: HTTP/1.1 401, Body {"error":"unauthorized"}

# Gefälschter X-Real-IP darf nichts ändern
curl -i -X POST https://<helpdesk>/api/trello \
     -H 'X-Real-IP: 203.0.113.10' \
     -H 'Content-Type: application/json' \
     -d '{"action":{"type":"commentCard"}}'
# erwartet: identisches 401
```

| Fall | Erwartet | Wenn abweichend |
|---|---|---|
| Ohne Signatur | `401`, generischer Body | **Stopp.** Plugin deaktivieren, Report einreichen. |
| Gefälschter `X-Real-IP` | identisches `401` | Ebenso. |
| Interne Notiz (§7.3) | nichts in Trello | Ebenso. |
| `tools/*.php` über HTTP | nicht erreichbar | Webserver-Konfiguration, sperren. |

---

## 9. Vor dem Go-Live

Trello ist ein US-Anbieter. Alles Synchronisierte verlässt die EU. Das ist eine Betriebsentscheidung, keine Plugin-Einstellung:

- AVV mit Atlassian abgeschlossen
- Eintrag im Verarbeitungsverzeichnis
- Entschieden, welche Inhalte überhaupt übertragen werden dürfen — das Plugin arbeitet opt-in je Ticket, „nur Metadaten" erreichst du also, indem das Board-Feld bei sensiblen Tickets leer bleibt
- Kunden informiert, wo erforderlich

---

## 10. Bekannte Grenzen

- Anhänge, Fälligkeitsdaten, Labels, Mitglieder-Zuweisungen und Checklisten werden nicht synchronisiert.
- Ein Board je Ticket. Kein Fan-out.
- Die eingehende Richtung setzt voraus, dass Trello deine Callback-URL erreicht. Hinter einer WAF, die unbekannte User-Agents blockt, registriert sie sich nicht.
- Die osTicket-gekoppelten Klassen sind nicht unit-getestet. Signaturprüfung, Board-Validierung und Sync-Regeln schon, mit 80 Tests.
- Über die Instanz des Autors hinaus noch nicht gegen eine echte osTicket-Installation verifiziert.

---

## Rollback

Plugin im Admin-Panel deaktivieren. Es wird nichts gelöscht, auf keiner Seite. Für die vollständige Entfernung in der Plugin-Liste deinstallieren und `include/plugins/trello-sync/` löschen. **Die Deinstallation ist nicht umkehrbar:** sie entfernt die Webhooks bei Trello und löscht beide Tabellen, `ost_trello_map` (Ticket ↔ Karte) und `ost_trello_board`. Vorher sichern, falls die Zuordnungen noch gebraucht werden.

**Update** heißt: Ordnerinhalt **unter demselben Ordnernamen** ersetzen. osTicket findet ein installiertes Plugin über seinen Installationspfad und behält die Konfiguration dieser Installation; zum Update nicht deinstallieren.
