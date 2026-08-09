<?php
/**
 * HMAC-Verifikation eingehender Trello-Webhooks.
 *
 * Bewusst frei von osTicket-Abhaengigkeiten, damit die Funktion isoliert
 * unit-testbar ist. Keine Includes, keine Globals, keine Seiteneffekte.
 *
 * Trello signiert jeden Webhook-Request:
 *   base64( HMAC-SHA1( requestBody + callbackURL, apiSecret ) )
 * und liefert das Ergebnis im Header X-Trello-Webhook.
 *
 * Die callbackURL MUSS exakt der bei der Webhook-Registrierung hinterlegten URL
 * entsprechen — inklusive Schema, Host, Pfad und ohne Trailing Slash, sofern
 * sie so registriert wurde. Sie wird aus der konfigurierten BASE_URL gebildet,
 * NIEMALS aus dem Host-Header (Host-Header-Poisoning).
 *
 * @copyright 2026 studio-prisma
 * @license GPL-2.0-or-later
 */
class TrelloSignature {

    /**
     * Verifiziert die Signatur eines eingehenden Webhook-Requests.
     *
     * Alle Parameter werden als potentiell feindlich behandelt. Die Funktion
     * gibt ausschliesslich bool zurueck und wirft nicht — der Aufrufer soll
     * keine Fehlerdetails an den Client weitergeben koennen.
     *
     * @param string $rawBody      Roher Request-Body, unveraendert (KEIN json_decode davor)
     * @param string $callbackUrl  Registrierte Callback-URL aus der Plugin-Config
     * @param string $secret       Trello API Secret
     * @param string $headerValue  Inhalt des Headers X-Trello-Webhook
     * @return bool
     */
    public static function isValid($rawBody, $callbackUrl, $secret, $headerValue) {

        // Fail-Closed: fehlt eine Komponente, ist die Anfrage ungueltig.
        // Insbesondere ein leeres Secret darf NIEMALS zu einem Pass fuehren —
        // genau dieser Pfad war die Luecke im Referenz-Plugin.
        if (!is_string($secret) || $secret === '') {
            return false;
        }
        if (!is_string($headerValue) || $headerValue === '') {
            return false;
        }
        if (!is_string($callbackUrl) || $callbackUrl === '') {
            return false;
        }
        if (!is_string($rawBody)) {
            return false;
        }

        $expected = base64_encode(
            hash_hmac('sha1', $rawBody . $callbackUrl, $secret, true)
        );

        // Timing-safe. hash_equals wirft bei nicht-String, daher sind die
        // Typpruefungen oben Voraussetzung.
        return hash_equals($expected, $headerValue);
    }

    /**
     * Liest den Signatur-Header aus einem Server-Array.
     *
     * Getrennt gehalten, damit Tests ein $_SERVER-Aequivalent injizieren koennen.
     *
     * @param array $server  Ueblicherweise $_SERVER
     * @return string  Leerstring, wenn nicht vorhanden
     */
    public static function extractHeader(array $server) {
        if (isset($server['HTTP_X_TRELLO_WEBHOOK'])
            && is_string($server['HTTP_X_TRELLO_WEBHOOK'])) {
            return $server['HTTP_X_TRELLO_WEBHOOK'];
        }
        return '';
    }
}
