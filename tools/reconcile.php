<?php
/**
 * Einmaliger Bestandsabgleich zwischen osTicket und Trello.
 *
 * NUR ueber die Kommandozeile. Der Zugriff ueber den Webserver wird hart
 * verweigert — das Skript liegt im Plugin-Verzeichnis und koennte sonst
 * unter Umstaenden erreichbar sein.
 *
 * Aufruf (aus dem Plugin-Verzeichnis):
 *
 *   php reconcile.php                    Dry-Run. Schreibt NICHTS.
 *   php reconcile.php --apply            Fuehrt aus.
 *   php reconcile.php --apply --limit=50 Obergrenze je Lauf (Default 200).
 *   php reconcile.php --board=<id>       Nur ein Board.
 *
 * Dry-Run ist Default. --apply muss ausdruecklich gesetzt werden.
 *
 * VOR DEM ERSTEN --apply: Datenbank sichern.
 *
 * @copyright 2026 studio-prisma
 * @license GPL-2.0-or-later
 */

// --- Harte CLI-Schranke --------------------------------------------------
if (PHP_SAPI !== 'cli') {
    header('HTTP/1.1 404 Not Found');
    exit;
}
if (!empty($_SERVER['REMOTE_ADDR']) || !empty($_SERVER['REQUEST_METHOD'])) {
    fwrite(STDERR, "Dieses Skript laeuft ausschliesslich auf der Kommandozeile.\n");
    exit(1);
}

// --- osTicket laden ------------------------------------------------------
// osTicket-Wurzel suchen. Im Paket liegen die Skripte unter
// include/plugins/trello-sync/tools/, von Hand kopiert auch eine Ebene hoeher;
// ein fester Pfad wie '../../..' passt nur zu einem der beiden Orte.
$root = null;
for ($dir = dirname(__FILE__), $i = 0; $i < 5; $i++, $dir = dirname($dir)) {
    if (is_file($dir . '/main.inc.php')) {
        $root = realpath($dir);
        break;
    }
}
if (!$root) {
    fwrite(STDERR, "osTicket-Wurzel nicht gefunden: keine main.inc.php in den fuenf "
        . "Verzeichnissen ueber " . dirname(__FILE__) . ".\n"
        . "Das Skript muss unter include/plugins/trello-sync/ liegen.\n");
    exit(1);
}
require_once $root . '/main.inc.php';

// --- Argumente -----------------------------------------------------------
$opts = getopt('', array('apply', 'limit::', 'board::', 'help'));

if (isset($opts['help'])) {
    fwrite(STDOUT, "Bestandsabgleich osTicket <-> Trello\n\n"
        . "  --apply           tatsaechlich schreiben (ohne: Dry-Run)\n"
        . "  --limit=N         Obergrenze je Lauf, Default 200\n"
        . "  --board=<id>      nur dieses Board\n\n");
    exit(0);
}

$apply      = isset($opts['apply']);
$limit      = isset($opts['limit']) ? max(1, (int) $opts['limit']) : 200;
$boardFilter = isset($opts['board']) ? trim((string) $opts['board']) : null;

// --- Plugin-Kontext ------------------------------------------------------
$config = TrelloSyncPlugin::getActiveConfig();
if (!$config) {
    fwrite(STDERR, "Plugin ist nicht aktiv oder nicht konfiguriert.\n");
    exit(1);
}

$boardField = trim((string) $config->get('trello_board_field')) ?: 'trello_board';
$boards     = TrelloBoardRegistry::all();

if (!$boards) {
    fwrite(STDERR, "Keine Boards in der Registry.\n");
    foreach (TrelloBoardRegistry::getProblems() as $p) {
        fwrite(STDERR, "  - $p\n");
    }
    exit(1);
}

if ($boardFilter !== null) {
    $boards = array_filter($boards, function ($b) use ($boardFilter) {
        return $b->boardId === $boardFilter;
    });
    if (!$boards) {
        fwrite(STDERR, "Board $boardFilter ist nicht registriert.\n");
        exit(1);
    }
}

$http    = new TrelloHttp(
    trim((string) $config->get('trello_api_key')),
    trim((string) $config->get('trello_api_token')));
$service = new TrelloSyncService($config);

echo str_repeat('=', 72), "\n";
echo $apply ? "MODUS: AUSFUEHRUNG (--apply)\n" : "MODUS: DRY-RUN — es wird nichts geschrieben\n";
echo 'Boards: ', count($boards), '   Obergrenze: ', $limit, "\n";
echo str_repeat('=', 72), "\n\n";

$planCreateCard   = array();  // Ticket hat Board, aber keine Karte
$planLinkExisting = array();  // Kandidaten fuer bestehende Paare
$planCreateTicket = array();  // Karte ohne Ticket

// --- Richtung 1: Tickets mit Board, ohne Mapping -------------------------
foreach ($boards as $board) {
    $res = db_query('SELECT t.ticket_id, t.number FROM ' . TICKET_TABLE . ' t'
        . ' LEFT JOIN ' . TRELLO_SYNC_TABLE . ' m ON m.ticket_id = t.ticket_id'
        . ' WHERE m.ticket_id IS NULL LIMIT ' . (int) ($limit * 4), false);

    if (!$res) {
        continue;
    }

    while ($row = db_fetch_array($res)) {
        $ticket = Ticket::lookup($row['ticket_id']);
        if (!$ticket) {
            continue;
        }
        $tb = TrelloBoardRegistry::getTicketBoard($ticket, $boardField);
        if (!$tb || $tb->boardId !== $board->boardId) {
            continue;
        }
        $planCreateCard[] = array('ticket' => $ticket, 'board' => $board);
        if (count($planCreateCard) >= $limit) {
            break 2;
        }
    }
}

// --- Richtung 2: Karten ohne Mapping -------------------------------------
foreach ($boards as $board) {
    $cards = $http->get('boards/' . rawurlencode($board->boardId) . '/cards',
        array('filter' => 'open', 'fields' => 'id,name,shortLink'));

    if (!is_array($cards)) {
        echo "  ! Karten von \"{$board->label}\" nicht lesbar: ", $http->getLastError(), "\n";
        continue;
    }

    foreach ($cards as $card) {
        if (empty($card['id']) || TrelloMap::getTicketId($card['id'])) {
            continue;
        }

        // Kandidat fuer eine bestehende Verknuepfung? Titel-Aehnlichkeit.
        // NUR als Vorschlag — es wird niemals automatisch verknuepft.
        $match = findTicketByTitle(isset($card['name']) ? $card['name'] : '');
        if ($match) {
            $planLinkExisting[] = array(
                'card' => $card, 'board' => $board, 'ticket' => $match);
        } else {
            $planCreateTicket[] = array('card' => $card, 'board' => $board);
        }
    }
    // Rate-Limit-Schonung im Batch
    usleep(300000);
}

/**
 * Sucht ein Ticket, dessen Nummer im Kartentitel vorkommt.
 * Bewusst konservativ: nur eine eindeutige Ticketnummer zaehlt als Treffer.
 */
function findTicketByTitle($title) {
    if (!preg_match('/#?(\d{4,})/', (string) $title, $m)) {
        return null;
    }
    $res = db_query('SELECT ticket_id FROM ' . TICKET_TABLE
        . ' WHERE number = ' . db_input($m[1]) . ' LIMIT 2', false);
    if (!$res || db_num_rows($res) !== 1) {
        return null;
    }
    list($id) = db_fetch_row($res);
    return Ticket::lookup($id);
}

// --- Report --------------------------------------------------------------
printf("A) Tickets mit Board, ohne Karte: %d\n", count($planCreateCard));
foreach (array_slice($planCreateCard, 0, 20) as $p) {
    printf("   #%-10s -> %s\n", $p['ticket']->getNumber(), $p['board']->label);
}
if (count($planCreateCard) > 20) { echo "   ... und ", count($planCreateCard) - 20, " weitere\n"; }

printf("\nB) Karten, die zu einem bestehenden Ticket passen: %d   [VORSCHLAG — bitte pruefen]\n",
    count($planLinkExisting));
foreach (array_slice($planLinkExisting, 0, 20) as $p) {
    printf("   Karte \"%s\" <-> Ticket #%s (%s)\n",
        mb_substr($p['card']['name'], 0, 40), $p['ticket']->getNumber(), $p['board']->label);
}
if (count($planLinkExisting) > 20) { echo "   ... und ", count($planLinkExisting) - 20, " weitere\n"; }

printf("\nC) Karten ohne Ticket: %d\n", count($planCreateTicket));
foreach (array_slice($planCreateTicket, 0, 20) as $p) {
    printf("   \"%s\" (%s)\n", mb_substr($p['card']['name'], 0, 50), $p['board']->label);
}
if (count($planCreateTicket) > 20) { echo "   ... und ", count($planCreateTicket) - 20, " weitere\n"; }

if (!$apply) {
    echo "\n", str_repeat('-', 72), "\n";
    echo "DRY-RUN beendet. Es wurde nichts geschrieben.\n";
    echo "Pruefe insbesondere Abschnitt B — Titel-Zuordnung ist eine Heuristik.\n";
    echo "Danach: DB sichern, dann `php reconcile.php --apply`.\n";
    exit(0);
}

// --- Ausfuehrung ---------------------------------------------------------
echo "\n", str_repeat('-', 72), "\nAUSFUEHRUNG\n\n";

$done = 0;

foreach ($planLinkExisting as $p) {
    if ($done >= $limit) { break; }
    if (TrelloMap::link($p['ticket']->getId(), $p['card']['id'], $p['board']->boardId)) {
        printf("  verknuepft: #%s <-> %s\n", $p['ticket']->getNumber(), $p['card']['id']);
        $done++;
    }
    usleep(200000);
}

foreach ($planCreateCard as $p) {
    if ($done >= $limit) { break; }
    $service->onTicketCreated($p['ticket']);
    printf("  Karte angelegt fuer #%s\n", $p['ticket']->getNumber());
    $done++;
    usleep(1000000); // 1 Request/s im Batch
}

echo "\nFertig. $done Objekte verarbeitet.\n";
echo "Abschnitt C (Karten ohne Ticket) wird bewusst NICHT automatisch angelegt —\n";
echo "das erzeugt Tickets mit realen Kundendaten. Bitte einzeln entscheiden.\n";
exit(0);
