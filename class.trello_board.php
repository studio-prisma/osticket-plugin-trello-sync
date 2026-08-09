<?php
/**
 * Ein registriertes Trello-Board (= ein Kunde).
 *
 * Bewusst OHNE osTicket-Abhaengigkeiten: die Validierung ist eine reine
 * Funktion und damit isoliert unit-testbar. Das Einlesen aus der osTicket-
 * Custom-List uebernimmt TrelloBoardRegistry.
 *
 * @copyright 2026 studio-prisma
 * @license GPL-2.0-or-later
 */
class TrelloBoard {

    /** @var int    ID des DynamicListItem */
    public $itemId;
    /** @var string Anzeigename, z. B. der Kundenname */
    public $label;
    /** @var string Trello-Board-ID */
    public $boardId;
    /** @var string Liste, in die neue Karten geschrieben werden */
    public $inboxListId;
    /** @var int    Department fuer Tickets aus diesem Board */
    public $deptId;
    /** @var string Absenderadresse fuer Tickets aus diesem Board */
    public $userEmail;

    private function __construct() {}

    /**
     * Trello-IDs sind 24-stellige Hex-Strings.
     */
    public static function isValidTrelloId($id) {
        return is_string($id) && preg_match('/^[a-f0-9]{24}$/i', $id) === 1;
    }

    /**
     * Baut ein Board aus den Properties eines Listeneintrags.
     *
     * Reine Funktion. Gibt bei jedem Problem null zurueck und traegt den Grund
     * in $error ein — der Aufrufer entscheidet, ob das geloggt wird. Es wird
     * nie geraten und nie ein Default gesetzt: ein unvollstaendig gepflegter
     * Listeneintrag darf nicht zu einem halb funktionierenden Board fuehren.
     *
     * @param  array  $props  Property-Array des Listeneintrags
     * @param  string $error  Wird bei Fehlschlag gefuellt
     * @return TrelloBoard|null
     */
    public static function fromProperties($itemId, $label, array $props, &$error = null) {

        $get = function ($key) use ($props) {
            return isset($props[$key]) && is_string($props[$key])
                ? trim($props[$key])
                : '';
        };

        $boardId = $get('board_id');
        if (!self::isValidTrelloId($boardId)) {
            $error = 'board_id fehlt oder ist keine gueltige Trello-ID (24 Hex)';
            return null;
        }

        $inbox = $get('inbox_list_id');
        if (!self::isValidTrelloId($inbox)) {
            $error = 'inbox_list_id fehlt oder ist keine gueltige Trello-ID (24 Hex)';
            return null;
        }

        $deptRaw = isset($props['default_dept_id']) ? $props['default_dept_id'] : null;
        $deptId  = is_numeric($deptRaw) ? (int) $deptRaw : 0;
        if ($deptId <= 0) {
            $error = 'default_dept_id fehlt oder ist keine gueltige Department-ID';
            return null;
        }

        $email = $get('default_user_email');
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'default_user_email fehlt oder ist keine gueltige Adresse';
            return null;
        }

        $itemId = (int) $itemId;
        if ($itemId <= 0) {
            $error = 'Ungueltige Listeneintrag-ID';
            return null;
        }

        $b = new self();
        $b->itemId      = $itemId;
        $b->label       = is_string($label) && trim($label) !== ''
                          ? trim($label)
                          : ('Board ' . substr($boardId, 0, 8));
        $b->boardId     = $boardId;
        $b->inboxListId = $inbox;
        $b->deptId      = $deptId;
        $b->userEmail   = $email;

        $error = null;
        return $b;
    }

    public function __toString() {
        return $this->label . ' (' . $this->boardId . ')';
    }
}
