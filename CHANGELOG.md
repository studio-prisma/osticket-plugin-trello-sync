# Changelog

Format nach [Keep a Changelog](https://keepachangelog.com/de/1.1.0/), Versionierung nach [SemVer](https://semver.org/lang/de/).

Solange die Hauptversion `0` ist, gilt: Minor-Sprünge können brechende Änderungen enthalten.

---

## [Unreleased]

Erste öffentliche Veröffentlichung. Alles davor lief unveröffentlicht auf einer einzelnen Instanz.

### Geändert — BRECHEND für Vorab-Installationen
- **Plugin-ID ist jetzt `studio-prisma:trello-sync`.** osTicket schlüsselt die gespeicherte Plugin-Konfiguration über diese ID. Wer eine unveröffentlichte Vorabversion einsetzt, verliert beim Update die Einstellungen und muss Board-Zuordnung, API-Key, Token und Secret neu setzen. **Vor dem Update die Konfiguration abschreiben.**
- `author` und `url` im Manifest zeigen auf studio-prisma.

### Hinzugefügt
- `LICENSE` mit dem vollständigen GPL-2.0-Text. Die Quelldateien trugen bereits `@license GPL-2.0-or-later`, es fehlte nur die Lizenzdatei. GPL statt MIT, weil das Plugin osTicket-Core-Klassen erweitert und osTicket unter GPL-2.0 steht.
- `@copyright 2026 studio-prisma` in allen 16 Quelldateien.
- Englische `README.md`, `docs/INSTALL.md` und `docs/INSTALL.de.md` mit Abbruchpunkten, Smoke-Test und Security-Gate.
- `SECURITY.md` mit In Scope / Out of Scope.
- `tools/build-zip.sh` baut das Installationspaket reproduzierbar nach `dist/` und bricht bei hartkodierten Zugangsdaten ab.
- CI: PHP-Lint über 8.0 bis 8.4, Unit-Tests unter `E_ALL`, struktureller Integritätscheck, Secret-Scan, `actionlint`, aggregierender `Gate`-Job als Required Check.

### Sicherheit
- **Ein Trello-Listenname kann keinen Ticketstatus mit Zustand `deleted` oder `archived` mehr auslösen.** Die Statuszuordnung nahm bisher alle osTicket-Status; ein Board-Mitglied konnte eine Liste „Deleted“ anlegen und eine Karte hineinschieben. Übernommen werden nur noch Status mit Zustand `open` oder `closed` (Positivliste, `TrelloRules::isSyncableStatusState()`).
- **Die Webhook-Registrierung läuft nicht mehr bei jedem Request.** Bisher prüfte jeder osTicket-Aufruf, auch anonyme Besuche des Kundenportals, die Registrierung und setzte bei Boards ohne Webhook Trello-Requests mit bis zu 15 s Timeout ab, bei Fehlkonfiguration mit Syslog-Eintrag. Jetzt nur noch im Staff-Bereich und im Cron-Lauf, und ein fehlgeschlagenes Board wird frühestens nach 15 Minuten erneut versucht.

### Behoben
- Die CLI-Werkzeuge im Paket (`trello-sync/tools/`) fanden die osTicket-Wurzel nicht: sie suchten fest `../../..`, was nur eine Ebene höher stimmt. Sie gehen jetzt bis zu fünf Verzeichnisse nach oben, bis `main.inc.php` auftaucht. `tools/README.md` beschreibt den Paketinhalt jetzt so, wie er ist.
- `tools/build-zip.sh` bricht ab, wenn `TrelloSyncService::VERSION` nicht zur Version in `plugin.php` passt. Die Version steht an zwei Stellen; bisher prüfte nur der Release-Workflow das Manifest.

### Entfernt
- Der interne Planungsstand `SPEC.md` und der doppelte Plugin-Ordner sind nicht Teil des öffentlichen Repos. Die CLI-Werkzeuge liegen einmalig unter `tools/`.

---

## [0.5.9] — 2026-08-09

**Die Richtung osTicket → Trello funktioniert.** Erste Karte angelegt: „#005094 Site vulnerabilities found".

### Behoben
- **Rohes HTML in der Kartenbeschreibung.** Der Ticket-Body wurde unverändert übernommen — bei HTML-Mails (Plesk, WordPress, Monitoring) landete das komplette Tabellen-Markup auf der Karte. Trello rendert Markdown, kein HTML. `bodyToText()` wandelt jetzt per `Format::html2text()` um und bereitet das Ergebnis mit `TrelloRules::tidyText()` nach: Einrückungen weg, mehrfache Leerzeichen und Leerzeilen zusammengefasst, geschützte Leerzeichen ersetzt. Gilt für Kartenbeschreibung und Kommentare.
- **Trello-Kommentare wurden als öffentliche Antwort ins Ticket geschrieben** (`addResponse`) — im Kundenportal sichtbar und je nach Konfiguration mit E-Mail an den Melder. Ein Trello-Kommentar ist interne Arbeitskommunikation und wird jetzt als **interne Notiz** angelegt (`addNote`, Titel „Kommentar aus Trello").
  Nebeneffekt: Notizen haben Typ `N` und werden von `onThreadEntry()` gefiltert — der Kommentar kann nicht nach Trello zurückwandern. Loop-Schutz ohne Zusatzlogik.

### Hinzugefügt
- 12 Tests für HTML-Erkennung und Textaufbereitung (56 in `test_rules.php`, 101 insgesamt).

### Offen
Webhook-Registrierung: Trellos Validator erhält von der Callback-URL ein 403 (Cloudflare). Betrifft nur die Richtung Trello → osTicket.

---

## [0.5.8] — 2026-08-09

Der Request-Dump zeigte `POST /1/cards?key=&token=` — **leere Zugangsdaten**. Da `redact()` nur nicht-leere Werte ersetzt, stand im Webhook-Request korrekt `<KEY>`/`<TOKEN>`, im Karten-Request dagegen nichts. Der Kontrast zwischen beiden Zeilen war das Diagnosesignal.

### Behoben
- **Die Konfiguration war im Signal-Handler leer.** `PluginInstance::bootstrap()` lädt die Instanz-Konfiguration per `$plugin->getConfig($instance)` seitlich in das Plugin-Objekt und ruft danach `bootstrap()`. Dort liefert `getConfig()` die richtigen Werte — die Signal-Handler laufen aber später, beim Speichern eines Tickets, ohne Instanzkontext. Ein erneutes `getConfig()` lieferte dort eine leere Konfiguration, der Request ging ohne Key und Token raus, und Trello antwortete mit `401 missing scopes`. `service()` nutzt jetzt die in `bootstrap()` festgehaltene Konfiguration.
- **Fail-Fast bei leeren Zugangsdaten.** `TrelloHttp` bricht ab, bevor ein Request ohne Key oder Token entsteht, und benennt den Grund. Diese Fehlerklasse wird nie wieder als Rechteproblem erscheinen.

### Offen
Die Webhook-Registrierung scheitert mit `VALIDATOR_URL_RETURNED_ERROR`: Trellos Validator bekommt von `https://helpdesk.example.org/api/trello` ein **403**. Das ist ein Infrastrukturthema (die Anfragen laufen über Cloudflare), kein Plugin-Fehler — die eingehende Richtung bleibt bis zur Klärung inaktiv.

---

## [0.5.7] — 2026-08-09

Beide Fehler dieser Version stammen aus **einer** Logzeile — dem ersten vollständigen Request-Dump, den 0.5.6 möglich gemacht hat.

### Behoben
- **`401 missing scopes`: die Ursache ist die Größe des Bodies.** Der Dump zeigte `Content-Length: 14760` — eine lange Ticketbeschreibung. `http_build_query()` hängt `key` und `token` ans Ende, also hinter 14 KB Nutzdaten, wo Trello sie nicht mehr auswertet. `probe-auth.php` hatte 58 Byte und funktionierte deshalb in allen Varianten. Zugangsdaten laufen jetzt standardmäßig über den Query-String, der von der Body-Größe unabhängig ist.
- **`MAX_BODY` von 15000 auf 4000 gesenkt.** Eine Karte ist ein Arbeitsobjekt, kein Archiv — der vollständige Verlauf steht im Ticket.
- **Webhooks wurden nie registriert.** `$cfg->getBaseUrl()` liefert im Plugin-Bootstrap einen Leerstring, weil `$cfg` wie `$ost` erst nach `osTicket::start()` belegt wird. Die Registrierung brach bei jedem Start mit „Helpdesk-URL ist nicht https (leer)" ab. `buildCallbackUrl()` liest die URL jetzt bei Bedarf direkt aus der Konfigurationstabelle. Ohne diesen Fix hätte die Rückrichtung Trello → osTicket auch nach dem Kartenfix nicht funktioniert.

### Zurückgenommen
Die Ursachenbeschreibung in 0.5.6 war falsch. Der `array(null)`-Header war ein echter Fehler und ist zu Recht behoben — er war aber nicht die Ursache von `missing scopes`.

---

## [0.5.6] — 2026-08-09

### Behoben
- **Die tatsächliche Ursache von `401 missing scopes`.** `TrelloHttp::request()` baute die Header-Liste als `array($authHeader)` auf. Ausserhalb des Modus `header` ist dieser Wert `null` — die Liste begann also mit einem leeren Eintrag, und libcurl behandelt einen leeren Header als Listenende. Alles danach wurde verworfen, insbesondere `Content-Type: application/x-www-form-urlencoded`. Ohne Content-Type parst Trello den Body nicht als Formulardaten; die dort transportierten Zugangsdaten kamen nie an, und die Antwort lautete `401 missing scopes`. Die Meldung zeigte auf die Rechte, die Ursache war eine kaputte Header-Liste.
- `POST` nutzt jetzt `CURLOPT_POST` statt `CURLOPT_CUSTOMREQUEST`.

### Hinzugefügt
- `TrelloHttp` protokolliert bei jedem fehlgeschlagenen Request den **tatsächlich gesendeten** Request (`CURLINFO_HEADER_OUT`), den Body und die Antwort — Zugangsdaten maskiert.
- `tests/test_headers.php` — Regressionstest: die Header-Liste darf nie einen leeren oder `null`-Eintrag enthalten.

### Anmerkung
`probe-auth.php` hat den Fehler nicht gefunden, weil es den Request **nachgebaut** statt den echten Client **instrumentiert** hat — und der Nachbau enthielt den Bug nicht. Ein Test, der den fehlerhaften Codepfad nicht durchläuft, beweist nichts über ihn.

---

## [0.5.5] — 2026-08-09

### Erkenntnis
`probe-auth.php` hat **alle drei** Auth-Varianten als funktionierend nachgewiesen — auch die, die 0.5.2 und 0.5.3 als Ursache behandelt hatten. Der Transport war nie das Problem. Die verbleibende Erklärung: der hochgeladene Code wurde nicht ausgeführt (PHP-OPcache mit `validate_timestamps=0`). Das passt zu allen Beobachtungen: `probe-auth.php` war eine neue, nie gecachte Datei und lief; die überschriebenen Plugin-Klassen lieferten über drei Uploads hinweg eine byteweise identische Fehlermeldung.

### Geändert
- **Paketstruktur getrennt.** `trello-sync/` enthält ab sofort ausschließlich Laufzeitdateien. Die CLI-Werkzeuge (`diagnose.php`, `probe-auth.php`, `reconcile.php`) sowie Tests und Hilfsskripte liegen außerhalb und werden bei Bedarf manuell in den Plugin-Ordner kopiert und danach wieder entfernt. Grund: Diagnoseskripte liegen sonst dauerhaft unter dem Dokumentenstamm, und `include/.htaccess` greift unter nginx nicht zuverlässig.

### Hinzugefügt
- `TrelloSyncService::VERSION` erscheint in jeder Fehlermeldung, zusammen mit dem aktiven Auth-Modus. Damit ist am Log ablesbar, welcher Codestand tatsächlich läuft.
- Diagnose meldet OPcache-Status, `validate_timestamps` und `revalidate_freq`.

### Zurückgenommen
Die Ursachenbeschreibungen in 0.5.2 und 0.5.3 waren falsch. Die dortigen Änderungen bleiben erhalten — Form-Encoding und Authorization-Header funktionieren nachweislich — sie haben das Problem aber nicht verursacht und nicht behoben.

---

## [0.5.4] — 2026-08-09

### Hinzugefügt
- `probe-auth.php` — instrumentierter Auth-Test. Sendet denselben Schreib-Request in drei Varianten (Zugangsdaten im Body / im Query-String / im Authorization-Header), protokolliert für jede den **tatsächlich abgesetzten Request** via `CURLINFO_HEADER_OUT` samt vollständiger Antwort und benennt die funktionierende Variante. Zugangsdaten werden in der Ausgabe maskiert, Testkarten sofort wieder entfernt.
- `TrelloHttp` kennt einen umschaltbaren `authMode` (`body` | `query` | `header`), Default `body`.

### Anmerkung
0.5.2 und 0.5.3 waren Vermutungen, die ohne Inspektion des tatsächlichen Requests ausgeliefert wurden — entgegen `code-debug-playbook` §4 (DevTools-First: nach maximal einer geratenen Iteration inspizieren) und dem Anti-Pattern Speculative-Fix-Stacking. `probe-auth.php` ersetzt das Raten durch Messung.

---

## [0.5.3] — 2026-08-09

### Behoben
- **`401 missing scopes` bei Schreibvorgängen — die eigentliche Ursache.** Nicht der Content-Type war das Problem, sondern der Ort der Zugangsdaten: Trello weist Requests ab, die Key und Token im **Query-String** führen und gleichzeitig einen **Body** haben. Da lesende Aufrufe keinen Body haben, funktionierten sie durchgehend — der Fehler sah dadurch wie ein Rechteproblem aus. Die Authentifizierung läuft jetzt einheitlich über den `Authorization: OAuth`-Header.

### Sicherheit
- Key und Token stehen nicht mehr in der URL. Damit tauchen sie nicht länger in Access-Logs, Proxy-Logs oder Referrern auf.

---

## [0.5.2] — 2026-08-09

### Behoben
- **Schreibvorgänge scheiterten mit `401 missing scopes`, obwohl der Token nachweislich `write` besaß.** Der HTTP-Client sendete Schreib-Requests als `application/json` bei gleichzeitiger Authentifizierung über Query-Parameter. Trello quittiert diese Kombination mit einem Rechte-Fehler statt einem Format-Fehler — die Meldung führte deshalb in die falsche Richtung. Lesende Aufrufe haben keinen Body und waren nie betroffen, was das Bild zusätzlich verzerrte. Schreibvorgänge laufen jetzt über `application/x-www-form-urlencoded`, wie in Trellos Dokumentation durchgehend gezeigt.

### Hinzugefügt
- Diagnose prüft die tatsächlichen Token-Rechte (`GET /1/tokens/{token}`) und Ablaufdatum.
- Diagnose führt einen echten Schreibtest je Board aus: legt eine Karte an und entfernt sie sofort wieder.

---

## [0.5.1] — 2026-08-09

### Hinzugefügt
- `diagnose.php --out=<pfad>` schreibt den Report zusätzlich in eine Datei, für Umgebungen ohne sichtbare stdout-Ausgabe (Plesk-Aufgaben). Datei wird auf `0600` gesetzt; der Pfad sollte außerhalb des Dokumentenstamms liegen.

---

## [0.5.0] — 2026-08-09

### Hinzugefügt
- `diagnose.php` — CLI-Zustandsdiagnose. Prüft in einem Durchlauf PHP-Erweiterungen, Plugin-Instanz, Konfiguration, Board-Registry, Trello-Erreichbarkeit, Webhook-Status, Mapping und die Existenz des Ticket-Feldes. Secrets werden maskiert ausgegeben.
- `class.trello_log.php` — zentrales Logging.

### Behoben
- **Logging während des Bootstraps ging vollständig verloren.** `osTicket::start()` ruft `$ost->plugins->bootstrap()` auf einer lokalen Variablen auf und gibt `$ost` erst danach zurück — die globale `$ost` ist während des Plugin-Bootstraps `null`. Die bisherigen Log-Helfer prüften `if ($ost)` und verwarfen damit jede Meldung aus `ensureWebhooks()`, der Tabellenanlage und der Registry. Symptom: „nichts passiert, nichts im Log". `TrelloLog` schreibt jetzt immer zusätzlich ins PHP-Error-Log und setzt beim osTicket-Syslog `force=true`, damit das Log-Level Meldungen nicht schluckt.
- **Stiller Ausstieg bei fehlenden Zugangsdaten.** `ensureWebhooks()` kehrte ohne jede Meldung zurück, wenn Key, Token oder Secret leer waren. Benennt jetzt konkret, welcher Wert fehlt.
- Leere Board-Registry ohne konkreten Fehler wird jetzt als eigene Meldung ausgegeben statt schweigend übergangen.

---

## [0.4.0] — 2026-08-09

### Behoben
- **`getSelections()` falsch interpretiert.** `DynamicList::getSelections()` liefert `['list-5' => ['Name', 'SelectionField', 5]]` — Schlüssel als String, Wert als Array. Die Auswahl zeigte dadurch „Liste list-2" statt des Listennamens, und gespeichert wurde `list-5`. Ein anschließendes `(int)` ergab `0`, `DynamicList::lookup(0)` fand nichts: die Board-Auflösung hätte **nie** funktioniert, ohne Fehlermeldung. Die Auswahl iteriert jetzt direkt über `DynamicList::objects()`. Altformat `list-N` wird beim Lesen weiterhin akzeptiert.
- **Validierungsfehler waren unsichtbar.** Meldungen aus `pre_save()` lagen auf `$errors['err']` — genau dem Schlüssel, den `scp/plugins.php` anschließend mit „Unable to update plugin instance." überschreibt. Fehler hängen jetzt an Feldern.
- **Board-Liste war Pflichtfeld** und damit ein Deadlock: sie ist erst wählbar, wenn die Custom-List existiert — bis dahin ließ sich die Konfiguration überhaupt nicht speichern. Jetzt optional mit Hinweis.

### Geändert
- Fail-Fast prüft zusätzlich `curl`, `mbstring` und `hash`. Ohne `mbstring` hätte `TrelloRules::truncate()` mitten in einem Ticket-Vorgang einen Fatal Error geworfen.

---

## [0.3.0] — 2026-08-09

### Hinzugefügt
- `reconcile.php` — einmaliger Bestandsabgleich. Dry-Run ist Default, `--apply` muss ausdrücklich gesetzt werden. Obergrenze je Lauf, Drosselung auf ~1 Request/Sekunde. Karten ohne Ticket werden bewusst **nicht** automatisch angelegt.
- Bidirektionaler Sync: Ticket-Anlage, Statusabgleich über Listennamen, Kommentare in beide Richtungen.
- `class.trello_rules.php` — reine Entscheidungslogik, ohne osTicket-Abhängigkeiten und damit isoliert testbar.
- **AC-7:** interne Notizen (ThreadEntry-Typ `N`) verlassen osTicket unter keinen Umständen. Positivliste — unbekannte Typen werden ebenfalls nicht übertragen. Der Filter greift, bevor ein Trello-Request entsteht.
- 43 Unit-Tests für die Sync-Regeln.

---

## [0.2.0] — 2026-08-09

### Hinzugefügt
- Multi-Board: ein Board je Kunde, gepflegt als osTicket-Custom-List. Dieselbe Liste dient als Auswahlfeld im Ticket.
- Sync ist opt-in pro Ticket. Leeres Board-Feld = kein Sync.
- Ein Webhook je Board, ein gemeinsamer Endpunkt. Zuordnung über `model.id` im Payload.
- `ost_trello_board` für Webhook-IDs je Board, getrennt von den nutzergepflegten Stammdaten.
- Board-Wechsel **verschiebt** die Karte. Board-Feld geleert legt das Mapping still — die Karte bleibt bestehen. Es wird nie etwas in Trello gelöscht.
- 24 Unit-Tests für die Board-Validierung.

### Behoben
- `Config::update()` ist eine Instanz-Methode. Der statische Aufruf aus dem Referenz-Plugin ist unter PHP 8 ein Fatal Error.
- `Controller::response()` nimmt nur `($code, $response)` — der Content-Type ging verloren. Antworten laufen jetzt über `Http::response()`.

---

## [0.1.0] — 2026-08-08

### Hinzugefügt
- Erstes lauffähiges Grundgerüst: Plugin-Manifest, Konfiguration, DB-Schema, Webhook-Endpunkt.
- **HMAC-SHA1-Verifikation** eingehender Webhooks mit `hash_equals()`. Ausdrücklich **keine** IP-Allowlist und keine Auswertung von `X-Real-IP` — beides ist client-setzbar und war im Referenz-Plugin (`kyleladd/OSTicket-Trello-Plugin`) die eigentliche Lücke.
- Fail-Fast: ohne Secret oder ohne https keine Aktivierung.
- Persistentes Mapping Ticket ↔ Karte statt Titel-Parsing.
- Keine Composer-Abhängigkeiten — reines cURL.
- 13 Unit-Tests, darunter zwei Regressionstests gegen die Lücken des Referenz-Plugins.

---

## Hinweis zur Herkunft

Eigenständige Implementierung. `kyleladd/OSTicket-Trello-Plugin` diente als Referenz für das Verhalten, enthält aber **keine LICENSE-Datei** — es wurde kein Code übernommen.
