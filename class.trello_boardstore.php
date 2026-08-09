<?php
/**
 * Persistenz der Webhook-Registrierungen je Board.
 *
 * Die Board-Stammdaten liegen in der osTicket-Custom-List (siehe
 * TrelloBoardRegistry). Hier liegt nur, was osTicket selbst erzeugt:
 * welche Webhook-ID Trello fuer welches Board vergeben hat.
 *
 * Trennung ist Absicht — die Registry ist vom Nutzer gepflegt, dieser
 * Store ist vom Plugin gepflegt. Beides zu vermischen wuerde bedeuten,
 * dass ein Tippfehler in der Liste eine Webhook-Registrierung verliert.
 *
 * @copyright 2026 studio-prisma
 * @license GPL-2.0-or-later
 */
class TrelloBoardStore {

    public static function install() {
        $sql = 'CREATE TABLE IF NOT EXISTS ' . TRELLO_BOARD_TABLE . ' ('
             . '  `board_id` varchar(32) NOT NULL,'
             . '  `webhook_id` varchar(32) DEFAULT NULL,'
             . '  `label` varchar(128) DEFAULT NULL,'
             . '  `last_error` text,'
             . '  `created_at` datetime NOT NULL,'
             . '  `updated_at` datetime NOT NULL,'
             . '  PRIMARY KEY (`board_id`)'
             . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';

        return db_query($sql, false) !== false;
    }

    public static function uninstall() {
        return db_query('DROP TABLE IF EXISTS ' . TRELLO_BOARD_TABLE, false) !== false;
    }

    public static function tableExists() {
        $res = db_query("SHOW TABLES LIKE '" . TRELLO_BOARD_TABLE . "'", false);
        return $res && db_num_rows($res) > 0;
    }

    public static function getWebhookId($boardId) {
        if (!TrelloBoard::isValidTrelloId($boardId)) {
            return null;
        }
        $res = db_query('SELECT webhook_id FROM ' . TRELLO_BOARD_TABLE
            . ' WHERE board_id = ' . db_input($boardId) . ' LIMIT 1', false);
        if ($res && db_num_rows($res)) {
            list($id) = db_fetch_row($res);
            return ($id === null || $id === '') ? null : $id;
        }
        return null;
    }

    public static function setWebhook($boardId, $webhookId, $label = null) {
        if (!TrelloBoard::isValidTrelloId($boardId)) {
            return false;
        }
        // Webhook-IDs haben dasselbe Format wie andere Trello-IDs.
        if (!TrelloBoard::isValidTrelloId($webhookId)) {
            return false;
        }

        $sql = 'INSERT INTO ' . TRELLO_BOARD_TABLE
             . ' (board_id, webhook_id, label, last_error, created_at, updated_at) VALUES ('
             . db_input($boardId) . ', '
             . db_input($webhookId) . ', '
             . db_input((string) $label) . ', NULL, NOW(), NOW()) '
             . 'ON DUPLICATE KEY UPDATE '
             . 'webhook_id = ' . db_input($webhookId) . ', '
             . 'label = ' . db_input((string) $label) . ', '
             . 'last_error = NULL, updated_at = NOW()';

        return db_query($sql, false) !== false;
    }

    /**
     * Haelt den letzten Fehler fest, damit eine fehlgeschlagene Registrierung
     * im Admin sichtbar wird statt still im Log zu verschwinden.
     */
    public static function setError($boardId, $message, $label = null) {
        if (!TrelloBoard::isValidTrelloId($boardId)) {
            return false;
        }
        $message = substr((string) $message, 0, 1000);

        $sql = 'INSERT INTO ' . TRELLO_BOARD_TABLE
             . ' (board_id, webhook_id, label, last_error, created_at, updated_at) VALUES ('
             . db_input($boardId) . ', NULL, '
             . db_input((string) $label) . ', '
             . db_input($message) . ', NOW(), NOW()) '
             . 'ON DUPLICATE KEY UPDATE '
             . 'last_error = ' . db_input($message) . ', updated_at = NOW()';

        return db_query($sql, false) !== false;
    }

    public static function forget($boardId) {
        if (!TrelloBoard::isValidTrelloId($boardId)) {
            return false;
        }
        return db_query('DELETE FROM ' . TRELLO_BOARD_TABLE
            . ' WHERE board_id = ' . db_input($boardId), false) !== false;
    }

    /**
     * Alle bekannten Registrierungen — fuer Uninstall und Statusanzeige.
     * @return array  Liste von array('board_id','webhook_id','label','last_error')
     */
    public static function all() {
        $out = array();
        $res = db_query('SELECT board_id, webhook_id, label, last_error FROM '
            . TRELLO_BOARD_TABLE, false);
        if ($res) {
            while ($row = db_fetch_array($res)) {
                $out[] = $row;
            }
        }
        return $out;
    }
}
