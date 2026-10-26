<?php
/**
 * Orchestrierung der Synchronisation in beide Richtungen.
 *
 * Die Entscheidungslogik liegt in TrelloRules (rein, testbar). Hier steht
 * nur das Zusammenspiel mit osTicket und der Trello-API.
 *
 * Grundsaetze:
 *  - Es wird NIE etwas in Trello geloescht.
 *  - Interne Notizen verlassen osTicket nie (TrelloRules::isSyncableEntryType).
 *  - Jeder Fehler wird geloggt, aber bricht nie den osTicket-Request ab.
 *
 * @copyright 2026 studio-prisma
 * @license GPL-2.0-or-later
 */
class TrelloSyncService {

    /**
     * Version dieses Codestands. Erscheint in Fehlermeldungen, damit
     * erkennbar ist, WELCHER Stand tatsaechlich ausgefuehrt wird.
     * Bei aktivem OPcache kann das vom hochgeladenen Stand abweichen.
     */
    const VERSION = '0.6.0';

    /** @var TrelloHttp|null */
    private $http = null;
    /** @var TrelloSyncConfig */
    private $config;
    /** @var string */
    private $boardField;

    public function __construct($config) {
        $this->config     = $config;
        $this->boardField = trim((string) $config->get('trello_board_field')) ?: 'trello_board';
    }

    /**
     * Bereitet einen Ticket-Body fuer eine Trello-Karte auf.
     *
     * Trello rendert Markdown, kein HTML. Ohne diese Umwandlung landet bei
     * jeder HTML-Mail (Plesk, WordPress, Monitoring) das rohe Tabellen-Markup
     * in der Kartenbeschreibung.
     */
    private function bodyToText($body) {
        if (!$body) {
            return '';
        }

        $raw = (string) $body->getClean();

        if (TrelloRules::looksLikeHtml($raw) && class_exists('Format')
                && method_exists('Format', 'html2text')) {
            // Breite gross waehlen: der Default von 74 Zeichen erzwingt harte
            // Umbrueche, die auf einer Karte unschoen aussehen.
            $raw = Format::html2text($raw, 10000, true);
        } else {
            $raw = Format::htmldecode($raw);
        }

        return TrelloRules::truncate(TrelloRules::tidyText($raw), TrelloRules::MAX_BODY);
    }

    private function http() {
        if ($this->http === null) {
            $this->http = new TrelloHttp(
                trim((string) $this->config->get('trello_api_key')),
                trim((string) $this->config->get('trello_api_token'))
            );
        }
        return $this->http;
    }

    // =====================================================================
    // osTicket  ->  Trello
    // =====================================================================

    /**
     * Neues Ticket. Nur aktiv, wenn im Ticket ein Board gewaehlt ist.
     */
    public function onTicketCreated($ticket) {
        try {
            $board = TrelloBoardRegistry::getTicketBoard($ticket, $this->boardField);
            if (!$board) {
                return; // kein Board gewaehlt = kein Sync. Normalfall.
            }
            $this->createCardFor($ticket, $board);
        } catch (Exception $e) {
            $this->logError('onTicketCreated: ' . $e->getMessage());
        }
    }

    /**
     * Ticket geaendert. Prueft, ob sich das Board-Feld veraendert hat,
     * und haelt zusaetzlich den Status nach.
     */
    public function onTicketUpdated($ticket) {
        try {
            $newBoard = TrelloBoardRegistry::getTicketBoard($ticket, $this->boardField);
            $mapped   = TrelloMap::getMapping($ticket->getId());

            $oldBoardId = $mapped && $mapped['status'] === 'active' ? $mapped['board_id'] : null;
            $newBoardId = $newBoard ? $newBoard->boardId : null;

            switch (TrelloRules::boardTransition($oldBoardId, $newBoardId)) {

                case 'create':
                    $this->createCardFor($ticket, $newBoard);
                    break;

                case 'move':
                    $this->moveCardFor($ticket, $mapped['card_id'], $newBoard);
                    break;

                case 'deactivate':
                    // Karte bleibt bestehen. Nur das Mapping wird stillgelegt.
                    TrelloMap::setStatus($ticket->getId(), 'inactive');
                    $this->logInfo(sprintf(
                        'Ticket #%s: Board-Feld geleert, Sync stillgelegt. Karte bleibt bestehen.',
                        $ticket->getNumber()));
                    break;

                case 'none':
                default:
                    if ($newBoard && $mapped) {
                        $this->pushStatus($ticket, $mapped['card_id'], $newBoard);
                    }
                    break;
            }
        } catch (Exception $e) {
            $this->logError('onTicketUpdated: ' . $e->getMessage());
        }
    }

    /**
     * Neuer Thread-Eintrag.
     *
     * HIER greift der Notizfilter (AC-7). Der Typ wird gegen eine
     * Positivliste geprueft — alles ausser M und R wird verworfen,
     * ohne dass ueberhaupt ein Trello-Request entsteht.
     */
    public function onThreadEntry($entry) {
        try {
            $type = $entry->getType();

            if (!TrelloRules::isSyncableEntryType($type)) {
                // Interne Notiz oder unbekannter Typ. Kein Request, kein Log-Inhalt.
                return;
            }

            $thread = $entry->getThread();
            if (!$thread) {
                return;
            }
            $ticket = $thread->getObject();
            if (!$ticket || !($ticket instanceof Ticket)) {
                return;
            }

            $mapped = TrelloMap::getMapping($ticket->getId());
            if (!$mapped || $mapped['status'] !== 'active') {
                return;
            }

            $text = $this->bodyToText($entry->getBody());
            if ($text === '') {
                return;
            }

            // Loop-Schutz Ebene 2: identischer Inhalt wird nicht erneut geschrieben
            $hash = TrelloMap::hashPayload('comment:' . $text);
            if (TrelloMap::isDuplicate($ticket->getId(), $hash)) {
                return;
            }

            $res = $this->http()->post(
                'cards/' . rawurlencode($mapped['card_id']) . '/actions/comments',
                array('text' => $text));

            if ($res === null) {
                $this->logError('Kommentar nicht uebertragen: ' . $this->http()->getLastError());
                return;
            }

            TrelloMap::touchSync($ticket->getId(), $hash, 'osticket');

        } catch (Exception $e) {
            $this->logError('onThreadEntry: ' . $e->getMessage());
        }
    }

    /**
     * Legt eine Karte fuer ein Ticket an und verknuepft sie.
     */
    private function createCardFor($ticket, TrelloBoard $board) {
        if (TrelloMap::getCardId($ticket->getId())) {
            // Bereits verknuepft — nichts doppelt anlegen.
            TrelloMap::setStatus($ticket->getId(), 'active');
            return;
        }

        $title = TrelloRules::buildCardTitle($ticket->getNumber(), $ticket->getSubject());
        $desc = '';
        $msg  = $ticket->getLastMessage();
        if ($msg) {
            $desc = $this->bodyToText($msg->getBody());
        }

        $res = $this->http()->post('cards', array(
            'idList' => $board->inboxListId,
            'name'   => $title,
            'desc'   => $desc,
        ));

        if (!$res || empty($res['id'])) {
            // Versionskennung und Auth-Modus mitloggen. Ohne die ist nicht
            // erkennbar, ob ueberhaupt der hochgeladene Stand laeuft — bei
            // aktivem OPcache mit validate_timestamps=0 wird weiter der alte
            // Bytecode ausgefuehrt, und jeder Fix bleibt wirkungslos.
            $this->logError(sprintf(
                'Karte fuer Ticket #%s auf "%s" fehlgeschlagen [%s, auth=%s]: %s',
                $ticket->getNumber(), $board->label,
                TrelloSyncService::VERSION, $this->http()->getAuthMode(),
                $this->http()->getLastError()));
            return;
        }

        TrelloMap::link($ticket->getId(), $res['id'], $board->boardId);
        TrelloMap::touchSync($ticket->getId(),
            TrelloMap::hashPayload('create:' . $title), 'osticket');

        $this->logInfo(sprintf('Ticket #%s -> Karte %s auf "%s"',
            $ticket->getNumber(), $res['id'], $board->label));
    }

    /**
     * Verschiebt eine bestehende Karte auf ein anderes Board.
     * Es wird NICHT dupliziert und nichts geloescht.
     */
    private function moveCardFor($ticket, $cardId, TrelloBoard $target) {
        $res = $this->http()->put('cards/' . rawurlencode($cardId), array(
            'idBoard' => $target->boardId,
            'idList'  => $target->inboxListId,
        ));

        if ($res === null) {
            $this->logError(sprintf('Karte %s konnte nicht nach "%s" verschoben werden: %s',
                $cardId, $target->label, $this->http()->getLastError()));
            return;
        }

        TrelloMap::link($ticket->getId(), $cardId, $target->boardId);
        TrelloMap::setStatus($ticket->getId(), 'active');
        $this->logInfo(sprintf('Ticket #%s: Karte nach "%s" verschoben',
            $ticket->getNumber(), $target->label));
    }

    /**
     * Spiegelt den Ticketstatus in die passend benannte Trello-Liste.
     */
    private function pushStatus($ticket, $cardId, TrelloBoard $board) {
        $statusName = $ticket->getStatus() ? (string) $ticket->getStatus()->getName() : '';
        if ($statusName === '') {
            return;
        }

        $hash = TrelloMap::hashPayload('status:' . $statusName);
        if (TrelloMap::isDuplicate($ticket->getId(), $hash)) {
            return;
        }

        $lists = $this->http()->get('boards/' . rawurlencode($board->boardId) . '/lists',
            array('filter' => 'open', 'fields' => 'id,name'));
        if (!is_array($lists)) {
            return;
        }

        $listId = TrelloRules::statusNameToListId($statusName, $lists);
        if ($listId === null) {
            // Keine gleichnamige Liste — das ist kein Fehler, sondern
            // schlicht ein Status, der auf dem Board nicht abgebildet ist.
            return;
        }

        $res = $this->http()->put('cards/' . rawurlencode($cardId), array('idList' => $listId));
        if ($res === null) {
            $this->logError('Status nicht uebertragen: ' . $this->http()->getLastError());
            return;
        }

        TrelloMap::touchSync($ticket->getId(), $hash, 'osticket');
    }

    // =====================================================================
    // Trello  ->  osTicket
    // =====================================================================

    /**
     * Karte in Trello angelegt -> Ticket erzeugen.
     */
    public function inboundCreateCard(array $payload, TrelloBoard $board) {
        $card = isset($payload['action']['data']['card']) ? $payload['action']['data']['card'] : null;
        if (!is_array($card) || empty($card['id'])) {
            return 'ignored: no card';
        }
        if (!TrelloBoard::isValidTrelloId($card['id'])) {
            return 'ignored: bad card id';
        }
        if (TrelloMap::getTicketId($card['id'])) {
            return 'ignored: already linked';
        }

        $subject = TrelloRules::truncate(
            isset($card['name']) ? (string) $card['name'] : '(ohne Betreff)',
            TrelloRules::MAX_TITLE);

        $message = 'Diese Anfrage wurde als Karte in Trello angelegt.';
        if (!empty($card['shortLink'])) {
            $message .= TrelloRules::originNote($card['shortLink']);
        }

        $errors = array();
        $vars = array(
            'subject'  => $subject,
            'message'  => $message,
            'email'    => $board->userEmail,
            'name'     => $board->label,
            'deptId'   => $board->deptId,
            'source'   => 'API',
        );

        $ticket = Ticket::create($vars, $errors, 'api', false, false);
        if (!$ticket || $errors) {
            $this->logError('Ticket aus Karte fehlgeschlagen: ' . json_encode($errors));
            return 'error: ticket not created';
        }

        TrelloMap::link($ticket->getId(), $card['id'], $board->boardId);
        $this->logInfo(sprintf('Karte %s -> Ticket #%s ("%s")',
            $card['id'], $ticket->getNumber(), $board->label));

        return 'created ticket';
    }

    /**
     * Karte verschoben -> Ticketstatus setzen.
     */
    public function inboundUpdateCard(array $payload, TrelloBoard $board) {
        $data = isset($payload['action']['data']) ? $payload['action']['data'] : array();
        $card = isset($data['card']) ? $data['card'] : null;

        if (!is_array($card) || empty($card['id'])) {
            return 'ignored: no card';
        }

        $ticketId = TrelloMap::getTicketId($card['id']);
        if (!$ticketId) {
            return 'ignored: card not linked';
        }

        // Nur der Listenwechsel ist relevant.
        if (empty($data['listAfter']['name'])) {
            return 'ignored: no list change';
        }

        $ticket = Ticket::lookup($ticketId);
        if (!$ticket) {
            return 'ignored: ticket gone';
        }

        $statuses = $this->getStatusMap();
        $statusId = TrelloRules::listNameToStatusId($data['listAfter']['name'], $statuses);
        if ($statusId === null) {
            return 'ignored: list has no matching status';
        }
        if ((int) $ticket->getStatusId() === $statusId) {
            return 'ignored: status unchanged';
        }

        $hash = TrelloMap::hashPayload('status:' . $data['listAfter']['name']);

        $errors = array();
        if (!$ticket->setStatus($statusId, 'Status aus Trello uebernommen', $errors)) {
            $this->logError('Status nicht gesetzt: ' . json_encode($errors));
            return 'error: status not set';
        }

        TrelloMap::touchSync($ticketId, $hash, 'trello');
        return 'status updated';
    }

    /**
     * Kommentar auf der Karte -> oeffentliche Antwort im Ticket.
     */
    public function inboundCommentCard(array $payload, TrelloBoard $board) {
        $data = isset($payload['action']['data']) ? $payload['action']['data'] : array();
        $card = isset($data['card']) ? $data['card'] : null;
        $text = isset($data['text']) ? trim((string) $data['text']) : '';

        if (!is_array($card) || empty($card['id']) || $text === '') {
            return 'ignored: incomplete';
        }

        $ticketId = TrelloMap::getTicketId($card['id']);
        if (!$ticketId) {
            return 'ignored: card not linked';
        }

        $text = TrelloRules::truncate($text, TrelloRules::MAX_BODY);
        $hash = TrelloMap::hashPayload('comment:' . $text);
        if (TrelloMap::isDuplicate($ticketId, $hash)) {
            return 'ignored: duplicate';
        }

        $ticket = Ticket::lookup($ticketId);
        if (!$ticket) {
            return 'ignored: ticket gone';
        }

        // INTERNE NOTIZ, keine oeffentliche Antwort.
        //
        // Ein Trello-Kommentar ist interne Arbeitskommunikation. Als Antwort
        // (addResponse) waere er im Kundenportal sichtbar und wuerde je nach
        // Konfiguration eine E-Mail an den Melder ausloesen — fuer eine
        // Notiz, die jemand nebenbei an eine Karte geschrieben hat, waere das
        // ein Datenschutz- und Peinlichkeitsproblem zugleich.
        //
        // Nebeneffekt: Notizen haben ThreadEntry-Typ N und werden von
        // onThreadEntry() gefiltert. Der Kommentar kann also nicht nach
        // Trello zurueckwandern — Loop-Schutz ohne Zusatzlogik.
        $errors = array();
        $entry = $ticket->getThread()->addNote(array(
            'note'  => $text,
            'title' => 'Kommentar aus Trello',
        ), $errors);

        if (!$entry) {
            $this->logError('Notiz nicht angelegt: ' . json_encode($errors));
            return 'error: note not added';
        }

        TrelloMap::touchSync($ticketId, $hash, 'trello');
        return 'note added';
    }

    /**
     * status_id => status_name, nur Status mit Zustand open oder closed.
     * Ein Status mit Zustand deleted wuerde das Ticket loeschen, archived
     * es aus dem Betrieb nehmen; beides darf ein Listenname nicht ausloesen.
     */
    private function getStatusMap() {
        $out = array();
        try {
            foreach (TicketStatusList::getStatuses()->all() as $s) {
                $state = method_exists($s, 'getState') ? $s->getState() : null;
                if (!TrelloRules::isSyncableStatusState($state)) {
                    continue;
                }
                $out[$s->getId()] = $s->getName();
            }
        } catch (Exception $e) {
            $this->logError('Statusliste nicht lesbar: ' . $e->getMessage());
        }
        return $out;
    }

    // --- Logging ---------------------------------------------------------

    private function logError($msg) { TrelloLog::error($msg); }
    private function logInfo($msg)  { TrelloLog::info($msg); }
}
