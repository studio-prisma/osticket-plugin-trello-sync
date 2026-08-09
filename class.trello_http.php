<?php
/**
 * Minimaler HTTP-Client fuer die Trello-REST-API.
 *
 * Bewusst ohne Guzzle und ohne Wrapper-Bibliothek:
 *  - keine Composer-Dependencies, kein vendor/, kein ungepinnter @dev-Stand
 *  - die Trello-API ist flaches REST, ein Wrapper bringt keinen Mehrwert
 *  - das Referenz-Plugin scheiterte genau an "cdaguerre/php-trello-api": @dev
 *
 * Rate-Limits (Stand Trello-Doku): 300 Requests / 10 s pro API-Key,
 * 100 Requests / 10 s pro Token. Bei HTTP 429 wird mit exponentiellem
 * Backoff wiederholt.
 *
 * @copyright 2026 studio-prisma
 * @license GPL-2.0-or-later
 */
class TrelloHttp {

    const BASE = 'https://api.trello.com/1';

    const CONNECT_TIMEOUT = 5;   // Sekunden
    const TIMEOUT         = 15;  // Sekunden gesamt
    const MAX_RETRIES     = 3;

    /**
     * Wo die Zugangsdaten transportiert werden.
     *
     *   'query'  Key/Token im Query-String. DEFAULT — unabhaengig von der
     *            Groesse des Bodies und damit der einzige Modus, der auch
     *            bei langen Beschreibungen zuverlaessig funktioniert.
     *   'body'   Key/Token im Body. Funktioniert nur bei kleinen Bodies.
     *   'header' Authorization: OAuth. Funktioniert, haelt Zugangsdaten aus
     *            der URL heraus — bei grossem Body ungeprueft.
     *
     * Umschaltbar, damit Varianten getestet werden koennen, statt dass
     * hier geraten wird.
     */
    private $authMode = 'query';

    private $key;
    private $token;
    private $lastError = '';

    public function __construct($key, $token, $authMode = 'query') {
        $this->key      = (string) $key;
        $this->token    = (string) $token;
        $this->authMode = in_array($authMode, array('body', 'query', 'header'), true)
            ? $authMode : 'query';
    }

    public function getAuthMode() {
        return $this->authMode;
    }

    /**
     * Ersetzt Key und Token in beliebigem Text. Pflicht vor jeder
     * Protokollausgabe — Zugangsdaten duerfen nie im Log stehen.
     */
    private function redact($s) {
        $s = (string) $s;
        if ($this->key !== '')   { $s = str_replace($this->key,   '<KEY>',   $s); }
        if ($this->token !== '') { $s = str_replace($this->token, '<TOKEN>', $s); }
        return $s;
    }

    /**
     * Letzter Fehler im Klartext — ausschliesslich fuer das Server-Log.
     * Darf nicht an HTTP-Clients ausgegeben werden.
     */
    public function getLastError() {
        return $this->lastError;
    }

    public function get($path, array $query = array()) {
        return $this->request('GET', $path, $query, null);
    }

    public function post($path, array $body = array()) {
        return $this->request('POST', $path, array(), $body);
    }

    public function put($path, array $body = array()) {
        return $this->request('PUT', $path, array(), $body);
    }

    public function delete($path) {
        return $this->request('DELETE', $path, array(), null);
    }

    /**
     * @return array|null  Dekodierte Antwort, oder null bei Fehler
     */
    private function request($method, $path, array $query, $body) {
        $this->lastError = '';

        if (!function_exists('curl_init')) {
            $this->lastError = 'php-curl ist nicht verfuegbar';
            return null;
        }

        // Leere Zugangsdaten fuehren zu "401 missing scopes" — einer Meldung,
        // die auf die Rechte zeigt statt auf die Ursache. Lieber hier
        // abbrechen und den echten Grund benennen, als Trello raten lassen.
        if ($this->key === '' || $this->token === '') {
            $this->lastError = 'Zugangsdaten leer (key=' . ($this->key === '' ? 'leer' : 'gesetzt')
                . ', token=' . ($this->token === '' ? 'leer' : 'gesetzt')
                . ') — die Plugin-Konfiguration wurde in diesem Kontext nicht geladen.';
            TrelloLog::error('Abbruch vor Request: ' . $this->lastError);
            return null;
        }

        // Platzierung der Zugangsdaten je nach Modus.
        //
        // Belegt am 2026-08-09: Trello antwortet mit 401 "missing scopes",
        // wenn key/token im BODY stehen und dieser gross ist (gemessen:
        // 14760 Byte aus einer langen Ticketbeschreibung). Die Zugangsdaten
        // stehen dort hinter den Nutzdaten und werden nicht mehr
        // ausgewertet. Die Meldung zeigt auf die Rechte, die Ursache ist die
        // Groesse. Der Query-String ist davon unabhaengig — deshalb Default.
        $authHeader = null;

        if ($body !== null) {
            // Request MIT Body
            switch ($this->authMode) {
                case 'header':
                    $authHeader = sprintf(
                        'Authorization: OAuth oauth_consumer_key="%s", oauth_token="%s"',
                        $this->key, $this->token);
                    break;
                case 'body':
                    // Zugangsdaten im Body — funktioniert nur bei kleinen
                    // Bodies zuverlaessig. Bei grossen Nutzdaten (eine lange
                    // Ticketbeschreibung erzeugt schnell >14 KB) landen
                    // key und token am Ende und werden nicht mehr
                    // ausgewertet: die Antwort lautet dann "missing scopes",
                    // obwohl die Rechte stimmen. Deshalb NICHT der Default.
                    $body['key']   = $this->key;
                    $body['token'] = $this->token;
                    break;
                case 'query':
                default:
                    // Default. Der Query-String ist von der Groesse des
                    // Bodies unabhaengig — die Zugangsdaten koennen dort
                    // nicht durch grosse Nutzdaten verdraengt werden.
                    $query['key']   = $this->key;
                    $query['token'] = $this->token;
                    break;
            }
        } else {
            // Request OHNE Body — Query funktioniert hier nachweislich.
            $query['key']   = $this->key;
            $query['token'] = $this->token;
        }

        $url = self::BASE . '/' . ltrim($path, '/');
        if ($query) {
            $url .= '?' . http_build_query($query);
        }

        $attempt = 0;
        while ($attempt < self::MAX_RETRIES) {
            $attempt++;
            $payload = null;

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, self::CONNECT_TIMEOUT);
            curl_setopt($ch, CURLOPT_TIMEOUT, self::TIMEOUT);

            // Den ausgehenden Request mitschreiben. Ohne das ist bei einem
            // Fehler nicht feststellbar, was tatsaechlich gesendet wurde —
            // und jede Ursachenanalyse bleibt Vermutung.
            curl_setopt($ch, CURLINFO_HEADER_OUT, true);

            // POST ueber CURLOPT_POST statt CURLOPT_CUSTOMREQUEST.
            // CUSTOMREQUEST setzt nur den Methoden-String und laesst curl
            // die uebrige Request-Konfiguration selbst waehlen; bei POST mit
            // Body ist CURLOPT_POST der vorgesehene Weg.
            if ($method === 'POST') {
                curl_setopt($ch, CURLOPT_POST, true);
            } else {
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            }
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);

            // WICHTIG: keine leeren Eintraege in die Header-Liste.
            //
            // $authHeader ist null, ausser im Modus 'header'. Ein
            // array(null) erzeugt einen leeren Header-Eintrag, und libcurl
            // behandelt den als Ende der Liste — alles danach wird verworfen,
            // insbesondere der Content-Type. Ohne Content-Type parst Trello
            // den Body nicht als Formulardaten, die darin transportierten
            // Zugangsdaten kommen nie an, und die Antwort lautet
            // "401 missing scopes". Die Meldung zeigt auf die Rechte,
            // die Ursache ist eine kaputte Header-Liste.
            $headers = array();
            if ($authHeader !== null && $authHeader !== '') {
                $headers[] = $authHeader;
            }

            if ($body !== null) {
                // Form-Encoding im Body — so zeigt Trello es in der Doku,
                // und so funktioniert es nachweislich. Im Body statt im
                // Query-String, damit lange Beschreibungen nicht an
                // URL-Laengengrenzen scheitern.
                $payload = http_build_query($body);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
                $headers[] = 'Content-Type: application/x-www-form-urlencoded';
            }

            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

            $raw    = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $sent   = curl_getinfo($ch, CURLINFO_HEADER_OUT);
            $err    = curl_error($ch);
            curl_close($ch);

            // Bei Fehlern den kompletten Request protokollieren. Zugangsdaten
            // werden dabei ersetzt — die Ausgabe landet im Log und darf sie
            // nicht enthalten.
            if ($status < 200 || $status >= 300) {
                TrelloLog::error(
                    "Trello-Request fehlgeschlagen (HTTP $status)\n"
                    . "--- gesendet ---\n" . $this->redact(rtrim((string) $sent)) . "\n"
                    . "--- Body ---\n" . $this->redact(
                        $payload !== null ? $payload : '(keiner)') . "\n"
                    . "--- Antwort ---\n" . $this->redact(substr((string) $raw, 0, 400)));
            }

            if ($raw === false) {
                $this->lastError = 'cURL: ' . $err;
                return null;
            }

            // Rate-Limit -> Backoff und erneut versuchen
            if ($status === 429) {
                $this->lastError = 'Trello Rate-Limit (429), Versuch ' . $attempt;
                if ($attempt < self::MAX_RETRIES) {
                    sleep($attempt * 2);
                    continue;
                }
                return null;
            }

            if ($status < 200 || $status >= 300) {
                // Body kann Trello-Fehlertexte enthalten -> nur ins Log,
                // niemals an den HTTP-Client weitergeben.
                $this->lastError = 'HTTP ' . $status . ': ' . substr((string) $raw, 0, 500);
                return null;
            }

            $decoded = json_decode($raw, true);
            if ($decoded === null && trim((string) $raw) !== 'null') {
                $this->lastError = 'Antwort ist kein gueltiges JSON';
                return null;
            }
            return $decoded;
        }

        return null;
    }

    /**
     * Registriert einen Webhook fuer ein Board.
     *
     * Trello prueft die callbackURL vor dem Anlegen mit einem HEAD-Request
     * und erwartet 200. Der Endpunkt muss also bereits erreichbar sein,
     * bevor diese Methode aufgerufen wird.
     */
    public function createWebhook($modelId, $callbackUrl, $description = 'osTicket Trello Sync') {
        return $this->post('webhooks', array(
            'idModel'     => $modelId,
            'callbackURL' => $callbackUrl,
            'description' => $description,
        ));
    }

    public function deleteWebhook($webhookId) {
        return $this->delete('webhooks/' . rawurlencode($webhookId));
    }
}
