<?php
/**
 * Hauptklasse des Trello-Sync-Plugins.
 *
 * Slice 0 (Walking Skeleton):
 *   - Plugin laedt, Config-UI, DB-Tabelle
 *   - /api/trello antwortet auf GET/HEAD mit 200 (Voraussetzung fuer die
 *     Webhook-Registrierung bei Trello)
 *   - /api/trello antwortet auf POST ohne gueltige HMAC-Signatur mit 401
 *   - Fail-Fast: ohne Secret / ohne https keine Aktivierung
 *   - KEINE Sync-Logik
 *
 * Die Registrierung der API-Route laeuft ueber das Core-Signal 'api', das
 * osTicket in api/http.php sendet. Vorhanden seit mindestens v1.14, geprueft
 * gegen v1.18 und v1.18.4. Keine Core-Modifikation noetig.
 *
 * @copyright 2026 studio-prisma
 * @license GPL-2.0-or-later
 */

require_once(INCLUDE_DIR . 'class.plugin.php');
require_once(INCLUDE_DIR . 'class.signal.php');
require_once(INCLUDE_DIR . 'class.app.php');

require_once('config.php');
require_once(TRELLO_SYNC_ROOT . 'class.trello_log.php');
require_once(TRELLO_SYNC_ROOT . 'class.trello_signature.php');
require_once(TRELLO_SYNC_ROOT . 'class.trello_board.php');
require_once(TRELLO_SYNC_ROOT . 'class.trello_boardstore.php');
require_once(TRELLO_SYNC_ROOT . 'class.trello_registry.php');
require_once(TRELLO_SYNC_ROOT . 'class.trello_map.php');
require_once(TRELLO_SYNC_ROOT . 'class.trello_http.php');
require_once(TRELLO_SYNC_ROOT . 'class.trello_rules.php');
require_once(TRELLO_SYNC_ROOT . 'class.trello_sync_service.php');
require_once(TRELLO_SYNC_ROOT . 'api.trello_sync.php');

class TrelloSyncPlugin extends Plugin {

    var $config_class = 'TrelloSyncConfig';

    /** @var TrelloSyncConfig|null Referenz fuer den API-Controller */
    private static $activeConfig = null;

    function bootstrap() {

        self::$activeConfig = $this->getConfig();

        if (!TrelloMap::tableExists()) {
            if (!TrelloMap::install()) {
                $this->logError('Mapping-Tabelle konnte nicht angelegt werden.');
            }
        }
        if (!TrelloBoardStore::tableExists()) {
            if (!TrelloBoardStore::install()) {
                $this->logError('Board-Tabelle konnte nicht angelegt werden.');
            }
        }

        // API-Routen registrieren
        Signal::connect('api', array('TrelloSyncPlugin', 'registerRoutes'));

        // Webhooks fuer alle registrierten Boards sicherstellen
        $this->ensureWebhooks();

        // Ticket-Signale (verifiziert gegen 1.18.4)
        Signal::connect('ticket.created', array($this, 'onTicketCreated'), 'Ticket');
        Signal::connect('model.updated',  array($this, 'onModelUpdated'));
        Signal::connect('model.created',  array($this, 'onModelCreated'));
    }

    /** @var TrelloSyncService|null */
    private $service = null;

    private function service() {
        if ($this->service === null) {
            // WICHTIG: die in bootstrap() festgehaltene Konfiguration nutzen,
            // NICHT $this->getConfig() erneut aufrufen.
            //
            // PluginInstance::bootstrap() laedt die Instanz-Konfiguration per
            // $plugin->getConfig($instance) seitlich in das Plugin-Objekt und
            // ruft danach bootstrap(). Innerhalb von bootstrap() liefert
            // getConfig() deshalb die richtigen Werte. Die Signal-Handler
            // laufen aber SPAETER, im Kontext eines Ticket-Speicherns — dort
            // ist der Instanzkontext nicht mehr gesetzt und getConfig()
            // liefert eine leere Konfiguration.
            //
            // Symptom war ein Request mit "?key=&token=": Zugangsdaten leer,
            // Trello antwortet mit "401 missing scopes". Die Meldung zeigt
            // auf die Rechte, die Ursache ist eine leere Config.
            $config = self::$activeConfig ?: $this->getConfig();
            $this->service = new TrelloSyncService($config);
        }
        return $this->service;
    }

    // --- Signal-Handler --------------------------------------------------
    // Jeder Handler kapselt seine Fehler. Ein Problem in der Synchronisation
    // darf niemals einen osTicket-Vorgang scheitern lassen.

    public function onTicketCreated($ticket) {
        if (!($ticket instanceof Ticket)) {
            return;
        }
        try {
            $this->service()->onTicketCreated($ticket);
        } catch (Exception $e) {
            $this->logError('ticket.created: ' . $e->getMessage());
        }
    }

    public function onModelUpdated($object, $data = null) {
        if (!($object instanceof Ticket)) {
            return;
        }
        try {
            $this->service()->onTicketUpdated($object);
        } catch (Exception $e) {
            $this->logError('model.updated: ' . $e->getMessage());
        }
    }

    public function onModelCreated($object, $data = null) {
        // Nur Thread-Eintraege sind hier relevant. Der Typfilter (interne
        // Notizen) sitzt in TrelloSyncService::onThreadEntry.
        if (!($object instanceof ThreadEntry)) {
            return;
        }
        try {
            $this->service()->onThreadEntry($object);
        } catch (Exception $e) {
            $this->logError('model.created: ' . $e->getMessage());
        }
    }

    /**
     * Vom API-Controller genutzt, da dieser keine Plugin-Instanz hat.
     */
    public static function getActiveConfig() {
        return self::$activeConfig;
    }

    /**
     * Haengt die Plugin-Routen in den API-Dispatcher.
     */
    public static function registerRoutes($dispatcher, $data = null) {
        $dispatcher->append(
            url_post('^/trello$', array('TrelloSyncApiController', 'inbound'))
        );
        $dispatcher->append(
            url_get('^/trello$', array('TrelloSyncApiController', 'probe'))
        );
    }

    /**
     * Stellt sicher, dass fuer jedes registrierte Board ein Webhook existiert.
     *
     * Laeuft bei jedem Bootstrap, tut aber nur etwas fuer Boards ohne
     * gespeicherte Webhook-ID. Ein Board, dessen Registrierung zuvor
     * fehlgeschlagen ist, wird erneut versucht — der letzte Fehler steht
     * dann im Board-Store und ist im Admin sichtbar.
     */
    private function ensureWebhooks() {
        $config = $this->getConfig();
        if (!$config) {
            return;
        }

        $key    = trim((string) $config->get('trello_api_key'));
        $token  = trim((string) $config->get('trello_api_token'));
        $secret = trim((string) $config->get('trello_api_secret'));

        // Kein stiller Ausstieg. Eine fehlende Konfiguration ist der
        // haeufigste Grund, warum "nichts passiert" — und ohne Logzeile
        // ist das von aussen nicht von einem Bug zu unterscheiden.
        $missing = array();
        if ($key === '')    { $missing[] = 'API-Key'; }
        if ($token === '')  { $missing[] = 'API-Token'; }
        if ($secret === '') { $missing[] = 'API-Secret'; }

        if ($missing) {
            $this->logError('Kein Sync moeglich: in der Plugin-Konfiguration fehlt '
                . implode(', ', $missing)
                . '. Wurde die Konfiguration erfolgreich gespeichert?');
            return;
        }

        $callback = TrelloSyncConfig::buildCallbackUrl();
        if (!TrelloSyncConfig::isSecureCallback($callback)) {
            $this->logError('Webhooks nicht registriert: Helpdesk-URL ist nicht https (aktuell: '
                . ($callback !== '' ? $callback : 'leer') . ').');
            return;
        }

        $boards = TrelloBoardRegistry::all();
        if (!$boards) {
            $problems = TrelloBoardRegistry::getProblems();
            if ($problems) {
                foreach ($problems as $p) {
                    $this->logError('Board-Registry: ' . $p);
                }
            } else {
                $this->logError('Board-Registry ist leer: die gewaehlte Custom-List enthaelt '
                    . 'keine aktiven Eintraege, oder es ist keine Liste gewaehlt.');
            }
            return;
        }

        $http = null;
        foreach ($boards as $board) {
            if (TrelloBoardStore::getWebhookId($board->boardId) !== null) {
                continue; // bereits registriert
            }

            if ($http === null) {
                $http = new TrelloHttp($key, $token);
            }

            $result = $http->createWebhook($board->boardId, $callback,
                'osTicket Sync — ' . $board->label);

            if (!$result || empty($result['id'])) {
                $msg = $http->getLastError();
                TrelloBoardStore::setError($board->boardId, $msg, $board->label);
                $this->logError(sprintf('Webhook fuer "%s" fehlgeschlagen: %s',
                    $board->label, $msg));
                continue;
            }

            TrelloBoardStore::setWebhook($board->boardId, $result['id'], $board->label);
            $this->logInfo(sprintf('Webhook fuer "%s" registriert: %s',
                $board->label, $result['id']));
        }
    }

    /**
     * Aufraeumen beim Deinstallieren: Webhook bei Trello entfernen,
     * damit keine verwaisten Callbacks zurueckbleiben. Tabelle droppen.
     */
    function pre_uninstall(&$errors) {
        $config = $this->getConfig();

        if ($config) {
            $key   = trim((string) $config->get('trello_api_key'));
            $token = trim((string) $config->get('trello_api_token'));

            if ($key !== '' && $token !== '') {
                $http = new TrelloHttp($key, $token);
                foreach (TrelloBoardStore::all() as $row) {
                    if (empty($row['webhook_id'])) {
                        continue;
                    }
                    if ($http->deleteWebhook($row['webhook_id']) === null) {
                        $this->logError(sprintf(
                            'Webhook %s (Board %s) konnte nicht entfernt werden: %s',
                            $row['webhook_id'], $row['board_id'], $http->getLastError()));
                    }
                }
            }
        }

        // Nur eigene Tabellen entfernen. Die Custom-List gehoert dem Nutzer
        // und wird NICHT angefasst.
        TrelloBoardStore::uninstall();
        TrelloMap::uninstall();
        return true;
    }

    // --- Logging -------------------------------------------------------
    // Details bleiben im osTicket-Log. Nichts davon erreicht je einen
    // HTTP-Client (code-security-playbook 2.4).

    // Delegiert an TrelloLog. Waehrend bootstrap() ist global $ost null —
    // ein direkter Zugriff wuerde jede Meldung verschlucken.
    private function logError($msg) { TrelloLog::error($msg); }
    private function logInfo($msg)  { TrelloLog::info($msg); }
}
