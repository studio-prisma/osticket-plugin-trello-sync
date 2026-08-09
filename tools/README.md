# CLI-Werkzeuge — nicht Teil des Plugins

Diese Skripte gehören **nicht** dauerhaft auf das Live-System. Sie liegen bewusst außerhalb von `trello-sync/`, damit sie nicht versehentlich mit hochgeladen werden.

## Warum getrennt

`include/` ist zwar per `.htaccess` mit `Deny from all` geschützt — unter Plesk liefert nginx statische Dateien aber häufig direkt aus und umgeht Apache dabei. Ein Diagnoseskript im Plugin-Ordner kann damit über das Web erreichbar sein.

Alle drei Skripte verweigern zwar den Dienst außerhalb der Kommandozeile (`PHP_SAPI !== 'cli'` → 404). Das ist eine zweite Verteidigungslinie, kein Grund, sie liegen zu lassen.

## Verwendung

Bei Bedarf einzeln nach `include/plugins/trello-sync/` kopieren, ausführen, **danach wieder löschen**.

Die Skripte erwarten diesen Ort: sie ermitteln die osTicket-Wurzel über `../../..`.

| Skript | Zweck | Wann |
|---|---|---|
| `diagnose.php` | Zustandsbericht: PHP-Erweiterungen, OPcache, Plugin-Instanz, Konfiguration, Board-Registry, Trello-Erreichbarkeit, Token-Rechte, Webhooks, Mapping, Ticket-Feld | wenn etwas nicht funktioniert |
| `probe-auth.php` | Sendet denselben Schreib-Request in drei Auth-Varianten, protokolliert den tatsächlich abgesetzten Request und die Antwort | bei API-Fehlern, die nach Rechteproblemen aussehen |
| `reconcile.php` | Einmaliger Bestandsabgleich. Dry-Run ist Default. | einmalig bei Inbetriebnahme |

```bash
cd /pfad/zu/osticket/include/plugins/trello-sync
/opt/plesk/php/8.3/bin/php diagnose.php --out=/pfad/ausserhalb/httpdocs/report.txt
```

`--out=` schreibt zusätzlich in eine Datei (Rechte `0600`). Der Pfad sollte **außerhalb** des Dokumentenstamms liegen — der Report enthält Board-IDs, Member-ID und Callback-URL im Klartext. Zugangsdaten werden maskiert.

Nach dem Lesen: Report und Skript löschen.

## Hinweis zu OPcache

Wenn ein hochgeladener Fix wirkungslos bleibt, ist OPcache der erste Verdacht. `diagnose.php` prüft `validate_timestamps` und `revalidate_freq` — allerdings in der **CLI**-Konfiguration, die von der FPM-Konfiguration abweichen kann.

Verlässlicher: die Fehlermeldungen des Plugins enthalten seit 0.5.5 die `TrelloSyncService::VERSION`. Steht dort eine ältere Version als die hochgeladene, wird alter Bytecode ausgeführt — dann PHP-FPM neu starten.
