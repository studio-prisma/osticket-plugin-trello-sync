<?php
/**
 * Board-Registry: liest die konfigurierten Boards aus einer osTicket-Custom-List.
 *
 * Ein Listeneintrag = ein Kunde = ein Board. Dieselbe Liste dient gleichzeitig
 * als Auswahlfeld im Ticket-Formular. Dadurch gibt es genau eine Pflegestelle
 * und keine eigene Plugin-Oberflaeche.
 *
 * Erforderliche Properties je Listeneintrag (Admin -> Verwalten -> Listen ->
 * <Liste> -> Properties):
 *   board_id            Trello-Board-ID       (24 Hex)
 *   inbox_list_id       Ziel-Liste fuer neue Karten (24 Hex)
 *   default_dept_id     Department-ID         (Zahl)
 *   default_user_email  Absender fuer Tickets aus diesem Board
 *
 * Verifiziert gegen osTicket 1.18.4:
 *   DynamicList::getItems(), DynamicListItem::getVar(), ::getConfiguration()
 *   SelectionField::to_php() liefert array(item_id => label)
 *
 * @copyright 2026 studio-prisma
 * @license GPL-2.0-or-later
 */

require_once(INCLUDE_DIR . 'class.list.php');

class TrelloBoardRegistry {

    /** @var TrelloBoard[]|null  Cache je Request, Schluessel = board_id */
    private static $cache = null;
    /** @var string[]  Beanstandungen aus dem letzten Ladevorgang */
    private static $problems = array();

    /**
     * Laedt alle gueltigen Boards. Ergebnis wird pro Request gecacht.
     *
     * @return TrelloBoard[]  Schluessel = Trello-Board-ID
     */
    public static function all() {
        if (self::$cache !== null) {
            return self::$cache;
        }

        self::$cache    = array();
        self::$problems = array();

        $list = self::getList();
        if (!$list) {
            self::$problems[] = 'Board-Liste nicht gefunden. Ist sie in der Plugin-Config korrekt hinterlegt?';
            return self::$cache;
        }

        foreach ($list->getItems() as $item) {
            if (!$item->isEnabled()) {
                continue;
            }

            $props = $item->getConfiguration();
            if (!is_array($props)) {
                $props = array();
            }

            // getConfiguration() liefert die Werte unter der FELD-ID.
            // Fuer die Zuordnung ueber den Feld-NAMEN nutzen wir getVar().
            $byName = array();
            foreach (array('board_id', 'inbox_list_id', 'default_dept_id', 'default_user_email') as $key) {
                $v = $item->getVar($key);
                $byName[$key] = is_scalar($v) ? (string) $v : '';
            }

            $err   = null;
            $board = TrelloBoard::fromProperties(
                $item->getId(), $item->getValue(), $byName, $err);

            if (!$board) {
                self::$problems[] = sprintf('Listeneintrag "%s": %s',
                    $item->getValue(), $err);
                continue;
            }

            if (isset(self::$cache[$board->boardId])) {
                self::$problems[] = sprintf(
                    'Board-ID %s ist mehrfach vergeben ("%s" und "%s") — zweiter Eintrag ignoriert.',
                    $board->boardId, self::$cache[$board->boardId]->label, $board->label);
                continue;
            }

            self::$cache[$board->boardId] = $board;
        }

        return self::$cache;
    }

    /**
     * Beanstandungen des letzten Ladevorgangs — fuer das Admin-Log.
     * @return string[]
     */
    public static function getProblems() {
        if (self::$cache === null) {
            self::all();
        }
        return self::$problems;
    }

    /**
     * Aufloesung eingehender Webhooks: Board-ID -> Board.
     * Unbekannte Board-ID ergibt null; der Aufrufer MUSS dann verwerfen.
     */
    public static function findByBoardId($boardId) {
        if (!TrelloBoard::isValidTrelloId($boardId)) {
            return null;
        }
        $all = self::all();
        return isset($all[$boardId]) ? $all[$boardId] : null;
    }

    public static function findByItemId($itemId) {
        $itemId = (int) $itemId;
        foreach (self::all() as $board) {
            if ($board->itemId === $itemId) {
                return $board;
            }
        }
        return null;
    }

    /**
     * Ermittelt das im Ticket gewaehlte Board.
     *
     * Liefert null, wenn kein Board gewaehlt ist — das ist der Normalfall
     * und bedeutet: dieses Ticket wird nicht synchronisiert.
     *
     * SelectionField::to_php() gibt array(item_id => label) zurueck. Wir
     * behandeln zusaetzlich Skalar- und Objektformen defensiv, weil der
     * konkrete Rueckgabetyp je nach Feldkonfiguration abweichen kann.
     *
     * @param  Ticket $ticket
     * @param  string $fieldName  Variablenname des Listenfeldes im Ticketformular
     * @return TrelloBoard|null
     */
    public static function getTicketBoard($ticket, $fieldName) {
        if (!$ticket || !is_string($fieldName) || $fieldName === '') {
            return null;
        }

        $answer = null;
        try {
            $answer = $ticket->getAnswer($fieldName);
        } catch (Exception $e) {
            return null;
        }
        if (!$answer) {
            return null;
        }

        $value = $answer->getValue();
        if (empty($value)) {
            return null;
        }

        // Normalfall: array(item_id => label)
        if (is_array($value)) {
            foreach (array_keys($value) as $itemId) {
                $board = self::findByItemId($itemId);
                if ($board) {
                    return $board;
                }
            }
            return null;
        }

        // Objektform: DynamicListItem
        if (is_object($value) && method_exists($value, 'getId')) {
            return self::findByItemId($value->getId());
        }

        // Skalarform: reine Item-ID
        if (is_numeric($value)) {
            return self::findByItemId((int) $value);
        }

        return null;
    }

    /**
     * Findet die konfigurierte Custom-List.
     */
    private static function getList() {
        $config = TrelloSyncPlugin::getActiveConfig();
        if (!$config) {
            return null;
        }

        $raw = $config->get('trello_board_list_id');

        // Abwaertskompatibel: aeltere Konfigurationen koennen 'list-5'
        // enthalten (Format von DynamicList::getSelections()). Reines
        // (int) haette daraus 0 gemacht und immer null geliefert.
        if (is_string($raw) && preg_match('/^list-(\d+)$/', $raw, $m)) {
            $listId = (int) $m[1];
        } else {
            $listId = (int) $raw;
        }

        if ($listId <= 0) {
            return null;
        }

        try {
            return DynamicList::lookup($listId);
        } catch (Throwable $t) {
            return null;
        }
    }

    /** Nur fuer Tests und nach Konfigurationsaenderungen. */
    public static function resetCache() {
        self::$cache    = null;
        self::$problems = array();
    }
}
