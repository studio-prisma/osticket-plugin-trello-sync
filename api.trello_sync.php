<?php
/**
 * Webhook-Endpunkt fuer eingehende Trello-Ereignisse.
 *
 * SICHERHEITSKERN. Reihenfolge ist verbindlich:
 *   1. Rohen Body lesen (vor jedem Parsing)
 *   2. HMAC-Signatur pruefen  -> bei Fehlschlag 401 und Ende
 *   3. Erst danach JSON dekodieren und verarbeiten
 *
 * Es gibt bewusst KEINE IP-Allowlist und keine Auswertung von
 * X-Real-IP / X-Forwarded-For. Beide Header sind client-setzbar; das
 * Referenz-Plugin war ueber genau diesen Pfad unauthentifiziert erreichbar.
 *
 * @copyright 2026 studio-prisma
 * @license GPL-2.0-or-later
 */

require_once INCLUDE_DIR . 'class.api.php';
require_once INCLUDE_DIR . 'class.ticket.php';
require_once INCLUDE_DIR . 'class.http.php';

class TrelloSyncApiController extends ApiController {

    /** Groessenlimit fuer eingehende Payloads (DoS-Schutz) */
    const MAX_BODY_BYTES = 262144; // 256 KB

    /**
     * Antwortet auf HEAD/GET mit 200.
     *
     * Trello prueft die callbackURL beim Anlegen des Webhooks mit einem
     * HEAD-Request und legt ihn nur an, wenn 200 zurueckkommt.
     * Diese Route gibt keinerlei Informationen preis.
     */
    function probe() {
        $this->response(200, json_encode(array('status' => 'ok')),
            'application/json');
    }

    /**
     * Verarbeitet eingehende Webhook-POSTs.
     */
    function inbound() {
        global $ost;

        $config = TrelloSyncPlugin::getActiveConfig();
        if (!$config) {
            // Plugin nicht konfiguriert -> nichts preisgeben
            return $this->deny();
        }

        // --- 1. Rohen Body lesen, VOR jedem Parsing ---------------------
        $rawBody = file_get_contents('php://input');
        if ($rawBody === false) {
            $rawBody = '';
        }

        if (strlen($rawBody) > self::MAX_BODY_BYTES) {
            $ost->logWarning('Trello Sync',
                'Webhook-Payload ueber Limit verworfen (' . strlen($rawBody) . ' Bytes)', false);
            return $this->deny();
        }

        // --- 2. Signatur pruefen ----------------------------------------
        $callbackUrl = TrelloSyncConfig::buildCallbackUrl();
        $secret      = (string) $config->get('trello_api_secret');
        $header      = TrelloSignature::extractHeader($_SERVER);

        if (!TrelloSignature::isValid($rawBody, $callbackUrl, $secret, $header)) {
            // Kein Detail nach aussen. Der Grund steht nur im Server-Log.
            $ost->logWarning('Trello Sync',
                'Webhook mit ungueltiger oder fehlender Signatur abgewiesen.', false);
            return $this->deny();
        }

        // --- 3. Ab hier gilt der Request als authentisch -----------------
        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            return $this->accept('ignored: no payload');
        }

        // Board-Bindung: nur Ereignisse registrierter Boards.
        // Ein einziger Endpunkt bedient alle Boards; die Zuordnung laeuft
        // ueber model.id im Payload, nicht ueber die URL.
        $boardId = isset($payload['model']['id']) ? $payload['model']['id'] : null;
        $board   = TrelloBoardRegistry::findByBoardId($boardId);
        if (!$board) {
            return $this->accept('ignored: unregistered board');
        }

        // --- Loop-Schutz Ebene 1: Urheber-Pruefung ----------------------
        // War die Aktion vom eigenen Token ausgeloest, ist es das Echo
        // unserer eigenen Schreiboperation. Verwerfen.
        $actor = isset($payload['action']['memberCreator']['id'])
            ? $payload['action']['memberCreator']['id']
            : null;
        $botId = (string) $config->get('trello_bot_member_id');
        if ($actor !== null && $botId !== '' && hash_equals($botId, (string) $actor)) {
            return $this->accept('ignored: own action');
        }

        // --- Verarbeitung -----------------------------------------------
        $type = isset($payload['action']['type']) ? (string) $payload['action']['type'] : '';

        try {
            $service = new TrelloSyncService($config);

            switch ($type) {
                case 'createCard':
                    $result = $service->inboundCreateCard($payload, $board);
                    break;
                case 'updateCard':
                    $result = $service->inboundUpdateCard($payload, $board);
                    break;
                case 'commentCard':
                    $result = $service->inboundCommentCard($payload, $board);
                    break;
                default:
                    // Unbekannte Aktionstypen werden bewusst ignoriert
                    // statt bestmoeglich interpretiert.
                    $result = 'ignored: unhandled type';
            }
        } catch (Exception $e) {
            // Details nur ins Log, nie an den Client.
            $ost->logError('Trello Sync',
                'Verarbeitung fehlgeschlagen (' . substr($type, 0, 32) . '): '
                . $e->getMessage(), false);
            return $this->accept('error logged');
        }

        $ost->logDebug('Trello Sync', sprintf('Board "%s", %s -> %s',
            $board->label, substr($type, 0, 32), $result));

        return $this->accept($result);
    }

    /**
     * Einheitliche Ablehnung. Immer 401, immer derselbe Body —
     * kein Rueckschluss auf den Ablehnungsgrund moeglich.
     */
    private function deny() {
        self::send(401, array('error' => 'unauthorized'));
    }

    private function accept($note) {
        self::send(200, array('status' => $note));
    }

    /**
     * Antwortet als JSON.
     *
     * Bewusst NICHT ueber Controller::response() — die Methode nimmt nur
     * ($code, $response) und reicht keinen Content-Type durch, die Antwort
     * ginge als text/html raus. Http::response() unterstuetzt ihn und
     * beendet den Request selbst.
     */
    private static function send($code, array $payload) {
        if (!headers_sent()) {
            header('X-Content-Type-Options: nosniff');
            header('Referrer-Policy: no-referrer');
        }
        Http::response($code, json_encode($payload), 'application/json');
        exit();
    }
}
