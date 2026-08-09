<?php
/**
 * Persistentes Mapping Ticket <-> Trello-Karte.
 *
 * Ersetzt das Titel-Parsing des Referenz-Plugins ("123 - Betreff"), das bei
 * jeder Umbenennung einer Karte lautlos abriss. Ausserdem macht die Tabelle
 * das board-weite Abrufen aller Karten ueberfluessig — das war der Grund,
 * warum das Original in Trello-Rate-Limits lief.
 *
 * Hinweis zu "parametrisiert": osTicket besitzt keine Bound-Parameter-API.
 * Der Core-Weg ist db_input(), das escaped und quotet. Alle externen Werte
 * laufen hier ausnahmslos durch db_input() bzw. werden vorher auf ein
 * striktes Zeichenformat geprueft.
 *
 * @copyright 2026 studio-prisma
 * @license GPL-2.0-or-later
 */
class TrelloMap {

    /**
     * Trello-IDs sind 24-stellige Hex-Strings (MongoDB ObjectIds).
     * Alles andere wird verworfen, bevor es die DB sieht.
     */
    public static function isValidTrelloId($id) {
        return is_string($id) && preg_match('/^[a-f0-9]{24}$/i', $id) === 1;
    }

    public static function install() {
        $sql = 'CREATE TABLE IF NOT EXISTS ' . TRELLO_SYNC_TABLE . ' ('
             . '  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,'
             . '  `ticket_id` int(11) unsigned NOT NULL,'
             . '  `card_id` varchar(32) NOT NULL,'
             . '  `board_id` varchar(32) NOT NULL,'
             . '  `last_sync_hash` char(64) DEFAULT NULL,'
             . "  `last_sync_source` enum('osticket','trello') DEFAULT NULL,"
             . "  `status` enum('active','inactive') NOT NULL DEFAULT 'active',"
             . '  `created_at` datetime NOT NULL,'
             . '  `updated_at` datetime NOT NULL,'
             . '  PRIMARY KEY (`id`),'
             . '  UNIQUE KEY `uq_ticket` (`ticket_id`),'
             . '  UNIQUE KEY `uq_card` (`card_id`)'
             . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';

        if (db_query($sql, false) === false) {
            return false;
        }
        return self::migrate();
    }

    /**
     * Nachtraegliche Schemaaenderungen fuer bestehende Installationen.
     *
     * CREATE TABLE IF NOT EXISTS legt bei einer vorhandenen Tabelle keine
     * neuen Spalten an — deshalb hier explizit pruefen und ergaenzen.
     */
    public static function migrate() {
        $res = db_query('SHOW COLUMNS FROM ' . TRELLO_SYNC_TABLE . " LIKE 'status'", false);
        if ($res && db_num_rows($res) === 0) {
            return db_query('ALTER TABLE ' . TRELLO_SYNC_TABLE
                . " ADD COLUMN `status` enum('active','inactive') NOT NULL DEFAULT 'active'",
                false) !== false;
        }
        return true;
    }

    public static function uninstall() {
        return db_query('DROP TABLE IF EXISTS ' . TRELLO_SYNC_TABLE, false) !== false;
    }

    public static function tableExists() {
        $res = db_query("SHOW TABLES LIKE '" . TRELLO_SYNC_TABLE . "'", false);
        return $res && db_num_rows($res) > 0;
    }

    /**
     * Legt eine Verknuepfung an. Idempotent: existiert sie bereits, wird
     * nur der Zeitstempel aktualisiert.
     */
    public static function link($ticketId, $cardId, $boardId) {
        $ticketId = (int) $ticketId;
        if ($ticketId <= 0
            || !self::isValidTrelloId($cardId)
            || !self::isValidTrelloId($boardId)) {
            return false;
        }

        $sql = 'INSERT INTO ' . TRELLO_SYNC_TABLE . ' '
             . '(ticket_id, card_id, board_id, created_at, updated_at) VALUES ('
             . db_input($ticketId) . ', '
             . db_input($cardId) . ', '
             . db_input($boardId) . ', NOW(), NOW()) '
             . 'ON DUPLICATE KEY UPDATE updated_at = NOW()';

        return db_query($sql, false) !== false;
    }

    public static function unlinkByTicket($ticketId) {
        $ticketId = (int) $ticketId;
        if ($ticketId <= 0) {
            return false;
        }
        return db_query('DELETE FROM ' . TRELLO_SYNC_TABLE
            . ' WHERE ticket_id = ' . db_input($ticketId), false) !== false;
    }

    public static function getCardId($ticketId) {
        $ticketId = (int) $ticketId;
        if ($ticketId <= 0) {
            return null;
        }
        $res = db_query('SELECT card_id FROM ' . TRELLO_SYNC_TABLE
            . ' WHERE ticket_id = ' . db_input($ticketId) . ' LIMIT 1', false);
        if ($res && db_num_rows($res)) {
            list($cardId) = db_fetch_row($res);
            return $cardId;
        }
        return null;
    }

    /**
     * Vollstaendiger Mapping-Datensatz zu einem Ticket.
     *
     * @return array|null  array('card_id','board_id','status') oder null
     */
    public static function getMapping($ticketId) {
        $ticketId = (int) $ticketId;
        if ($ticketId <= 0) {
            return null;
        }
        $res = db_query('SELECT card_id, board_id, status FROM ' . TRELLO_SYNC_TABLE
            . ' WHERE ticket_id = ' . db_input($ticketId) . ' LIMIT 1', false);
        if ($res && db_num_rows($res)) {
            return db_fetch_array($res);
        }
        return null;
    }

    /**
     * Legt ein Mapping stilllegen oder reaktiviert es.
     * Die Trello-Karte bleibt in jedem Fall unangetastet.
     */
    public static function setStatus($ticketId, $status) {
        $ticketId = (int) $ticketId;
        if ($ticketId <= 0) {
            return false;
        }
        if ($status !== 'active' && $status !== 'inactive') {
            return false;
        }
        return db_query('UPDATE ' . TRELLO_SYNC_TABLE
            . ' SET status = ' . db_input($status) . ', updated_at = NOW()'
            . ' WHERE ticket_id = ' . db_input($ticketId), false) !== false;
    }

    /**
     * Alle aktiven Mappings — fuer den Bestandsabgleich.
     */
    public static function allActive() {
        $out = array();
        $res = db_query('SELECT ticket_id, card_id, board_id FROM ' . TRELLO_SYNC_TABLE
            . " WHERE status = 'active'", false);
        if ($res) {
            while ($row = db_fetch_array($res)) {
                $out[] = $row;
            }
        }
        return $out;
    }

    /**
     * Aufloesung Karte -> Ticket. Der EINZIGE erlaubte Weg, aus einem
     * eingehenden Webhook eine Ticket-ID zu gewinnen. Es wird nie eine
     * ID aus dem Payload direkt als Ticket-ID interpretiert.
     */
    public static function getTicketId($cardId) {
        if (!self::isValidTrelloId($cardId)) {
            return null;
        }
        $res = db_query('SELECT ticket_id FROM ' . TRELLO_SYNC_TABLE
            . ' WHERE card_id = ' . db_input($cardId) . ' LIMIT 1', false);
        if ($res && db_num_rows($res)) {
            list($ticketId) = db_fetch_row($res);
            return (int) $ticketId;
        }
        return null;
    }

    /**
     * Loop-Schutz Ebene 2: Payload-Hash.
     *
     * Vor jedem Schreibvorgang wird der Hash des zu uebertragenden Werts
     * gebildet. Stimmt er mit dem zuletzt synchronisierten ueberein, ist der
     * Wert bereits auf beiden Seiten identisch und es wird nicht geschrieben.
     */
    public static function hashPayload($value) {
        return hash('sha256', (string) $value);
    }

    public static function isDuplicate($ticketId, $hash) {
        $ticketId = (int) $ticketId;
        if ($ticketId <= 0 || !is_string($hash) || $hash === '') {
            return false;
        }
        $res = db_query('SELECT last_sync_hash FROM ' . TRELLO_SYNC_TABLE
            . ' WHERE ticket_id = ' . db_input($ticketId) . ' LIMIT 1', false);
        if ($res && db_num_rows($res)) {
            list($stored) = db_fetch_row($res);
            return is_string($stored) && hash_equals($stored, $hash);
        }
        return false;
    }

    public static function touchSync($ticketId, $hash, $source) {
        $ticketId = (int) $ticketId;
        if ($ticketId <= 0) {
            return false;
        }
        if ($source !== 'osticket' && $source !== 'trello') {
            return false;
        }
        if (!is_string($hash) || !preg_match('/^[a-f0-9]{64}$/', $hash)) {
            return false;
        }

        return db_query('UPDATE ' . TRELLO_SYNC_TABLE . ' SET '
            . 'last_sync_hash = ' . db_input($hash) . ', '
            . 'last_sync_source = ' . db_input($source) . ', '
            . 'updated_at = NOW() '
            . 'WHERE ticket_id = ' . db_input($ticketId), false) !== false;
    }
}
