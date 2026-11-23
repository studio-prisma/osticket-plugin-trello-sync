<?php
/**
 * Regressionstest Mandantenbindung des Mappings und
 * Trello-Kommentare als Text.
 *
 * Laeuft OHNE osTicket. TrelloMap, TrelloRules, TrelloBoard und
 * TrelloSyncService sind die echten Klassen. Ersetzt werden nur die
 * Umgebung: die osTicket-DB-Funktionen (kleine In-Memory-Tabelle, die genau
 * die SQL-Formen von TrelloMap versteht), Ticket, Format, Thread-Body und
 * die Trello-HTTP-Schicht (zeichnet Requests auf, kein Netz).
 *
 * Szenarien:
 *   S1  Board-Feld geleert, spaeter anderer Kunde gewaehlt -> Karte wird
 *       verschoben, Antwort geht NICHT auf das Board des frueheren Kunden.
 *   S1b Verschieben scheitert -> Mapping bleibt stillgelegt, kein Kommentar.
 *   S2  Direkter Board-Wechsel schreibt das neue Board ins Mapping, ein
 *       weiteres Update verschiebt nicht erneut.
 *   S3  Eingehende Ereignisse nur fuer aktive Mappings des Absender-Boards.
 *   S4  Kommentar aus Trello wird als Text-Notiz gespeichert.
 *
 * Ausfuehren:  php tests/test_mapping_binding.php
 */

require_once __DIR__ . '/bootstrap.php';

define('TRELLO_SYNC_TABLE', 'test_trello_sync');

$passed = 0;
$failed = 0;

function check($name, $actual, $expected) {
    global $passed, $failed;
    if ($actual === $expected) {
        $passed++;
        echo "  PASS  $name\n";
    } else {
        $failed++;
        echo "  FAIL  $name (erwartet: " . var_export($expected, true)
           . ", erhalten: " . var_export($actual, true) . ")\n";
    }
}

// =====================================================================
// In-Memory-DB: versteht genau die SQL-Formen aus TrelloMap.
// =====================================================================

$GLOBALS['DB_ROWS'] = array();
$GLOBALS['DB_LOG']  = array();

class FakeResult {
    public $rows;
    public $pos = 0;
    public function __construct(array $rows) { $this->rows = $rows; }
}

function db_input($v) {
    if (is_int($v)) {
        return (string) $v;
    }
    return "'" . str_replace(array('\\', "'"), array('\\\\', "\\'"), (string) $v) . "'";
}

function fake_value($raw) {
    $raw = trim($raw);
    if ($raw === 'NOW()') {
        return '2026-01-01 00:00:00';
    }
    if (preg_match("/^'(.*)'$/s", $raw, $m)) {
        return stripslashes($m[1]);
    }
    if (preg_match('/^\d+$/', $raw)) {
        return $raw;
    }
    throw new RuntimeException('Fake-DB versteht den Wert nicht: ' . $raw);
}

/** "a = 'x', b = NOW()" -> array('a' => 'x', 'b' => ...) */
function fake_assignments($sql) {
    $out = array();
    foreach (preg_split('/\s*,\s*(?=[a-z_]+\s*=)/', trim($sql)) as $part) {
        if (!preg_match('/^([a-z_]+)\s*=\s*(.+)$/s', trim($part), $m)) {
            throw new RuntimeException('Fake-DB versteht die Zuweisung nicht: ' . $part);
        }
        $out[$m[1]] = fake_value($m[2]);
    }
    return $out;
}

/** "a = 'x' AND b = 1" -> Filterfunktion */
function fake_where($sql) {
    $conds = array();
    foreach (preg_split('/\s+AND\s+/i', trim($sql)) as $part) {
        if (!preg_match('/^([a-z_]+)\s*=\s*(.+)$/s', trim($part), $m)) {
            throw new RuntimeException('Fake-DB versteht die Bedingung nicht: ' . $part);
        }
        $conds[$m[1]] = fake_value($m[2]);
    }
    return function ($row) use ($conds) {
        foreach ($conds as $col => $val) {
            if (!isset($row[$col]) || (string) $row[$col] !== (string) $val) {
                return false;
            }
        }
        return true;
    };
}

function db_query($sql, $logError = true) {
    $GLOBALS['DB_LOG'][] = $sql;
    $t = preg_quote(TRELLO_SYNC_TABLE, '/');
    $rows =& $GLOBALS['DB_ROWS'];

    if (preg_match("/^INSERT INTO $t \(([^)]+)\) VALUES \((.+?)\) ON DUPLICATE KEY UPDATE (.+)$/s", $sql, $m)) {
        $cols = array_map('trim', explode(',', $m[1]));
        $vals = array_map('fake_value', preg_split('/\s*,\s*/', $m[2]));
        $new  = array_combine($cols, $vals);
        foreach ($rows as &$row) {
            if ((string) $row['ticket_id'] === (string) $new['ticket_id']
                    || $row['card_id'] === $new['card_id']) {
                foreach (fake_assignments($m[3]) as $c => $v) {
                    $row[$c] = $v;
                }
                return true;
            }
        }
        unset($row);
        $new += array('status' => 'active', 'last_sync_hash' => null, 'last_sync_source' => null);
        $rows[] = $new;
        return true;
    }

    if (preg_match("/^UPDATE $t SET (.+?) WHERE (.+)$/s", $sql, $m)) {
        $set = fake_assignments($m[1]);
        $match = fake_where($m[2]);
        foreach ($rows as &$row) {
            if ($match($row)) {
                foreach ($set as $c => $v) {
                    $row[$c] = $v;
                }
            }
        }
        unset($row);
        return true;
    }

    if (preg_match("/^SELECT (.+?) FROM $t WHERE (.+?)(?: LIMIT 1)?$/s", $sql, $m)) {
        $cols = array_map('trim', explode(',', $m[1]));
        $match = fake_where($m[2]);
        $out = array();
        foreach ($rows as $row) {
            if ($match($row)) {
                $sel = array();
                foreach ($cols as $c) {
                    $sel[$c] = $row[$c];
                }
                $out[] = $sel;
            }
        }
        return new FakeResult($out);
    }

    throw new RuntimeException('Fake-DB versteht die Abfrage nicht: ' . $sql);
}

function db_num_rows($res) { return $res instanceof FakeResult ? count($res->rows) : 0; }
function db_fetch_array($res) {
    return $res instanceof FakeResult && isset($res->rows[$res->pos]) ? $res->rows[$res->pos++] : null;
}
function db_fetch_row($res) {
    $r = db_fetch_array($res);
    return $r === null ? null : array_values($r);
}

// =====================================================================
// osTicket- und Trello-Ersatz
// =====================================================================

class Format {
    public static function htmldecode($s) { return html_entity_decode((string) $s, ENT_QUOTES, 'UTF-8'); }
    public static function html2text($s) { return strip_tags((string) $s); }
    /** wie osTicket 1.18: htmlspecialchars ohne Doppelkodierung */
    public static function htmlchars($s) { return htmlspecialchars((string) $s, ENT_COMPAT | ENT_HTML401, 'UTF-8', false); }
}

class ThreadEntryBody {
    public $body; public $type;
    public function __construct($body, $type) { $this->body = (string) $body; $this->type = $type; }
    public function getClean() { return $this->body; }
    public function getType() { return $this->type; }
}
class TextThreadEntryBody extends ThreadEntryBody {
    public function __construct($body) { parent::__construct($body, 'text'); }
}

class FakeThread {
    public $notes = array();
    private $ticket;
    public function __construct($ticket) { $this->ticket = $ticket; }
    public function getObject() { return $this->ticket; }
    public function addNote($vars, &$errors = array()) { $this->notes[] = $vars; return true; }
}

class Ticket {
    public static $registry = array();
    public $id; public $board; public $thread;
    public function __construct($id) {
        $this->id = $id;
        $this->thread = new FakeThread($this);
        self::$registry[$id] = $this;
    }
    public static function lookup($id) { return isset(self::$registry[$id]) ? self::$registry[$id] : null; }
    public function getId() { return $this->id; }
    public function getNumber() { return 'T' . $this->id; }
    public function getSubject() { return 'Betreff ' . $this->id; }
    public function getLastMessage() { return null; }
    public function getStatus() { return null; }
    public function getThread() { return $this->thread; }
}

class FakeEntry {
    private $ticket; private $text;
    public function __construct($ticket, $text) { $this->ticket = $ticket; $this->text = $text; }
    public function getType() { return 'R'; }
    public function getThread() { return $this->ticket->getThread(); }
    public function getBody() { return new ThreadEntryBody($this->text, 'text'); }
}

/** Ersetzt TrelloBoardRegistry: das Board steht direkt am Fake-Ticket. */
class TrelloBoardRegistry {
    public static function getTicketBoard($ticket, $field) { return $ticket->board; }
}

class TrelloHttp {
    public static $calls = array();
    public static $failPut = false;
    private static $nextCard = 0;
    public function __construct($key, $token) {}
    public function post($path, array $data) {
        self::$calls[] = array('POST', $path, $data);
        if ($path === 'cards') {
            return array('id' => sprintf('c%023d', ++self::$nextCard));
        }
        return array('id' => 'x');
    }
    public function put($path, array $data) {
        self::$calls[] = array('PUT', $path, $data);
        return self::$failPut ? null : array('id' => 'x');
    }
    public function get($path, array $q = array()) { return array(); }
    public function getLastError() { return 'simuliert'; }
    public function getAuthMode() { return 'test'; }
}

class TrelloLog {
    public static function error($m) {}
    public static function warn($m) {}
    public static function info($m) {}
}

class FakeStatusQuery {
    public function all() { return array(); }
}
class TicketStatusList {
    public static function getStatuses() { return new FakeStatusQuery(); }
}

class FakeConfig {
    public function get($k) { return ''; }
}

trello_test_require('class.trello_rules.php');
trello_test_require('class.trello_board.php');
trello_test_require('class.trello_map.php');
trello_test_require('class.trello_sync_service.php');

function board($n, $hexDigit) {
    $e = null;
    $b = TrelloBoard::fromProperties($n, 'Kunde ' . $n, array(
        'board_id'           => str_repeat($hexDigit, 24),
        'inbox_list_id'      => str_repeat($hexDigit, 23) . 'f',
        'default_dept_id'    => '1',
        'default_user_email' => 'kunde' . $n . '@example.com',
    ), $e);
    if (!$b) {
        throw new RuntimeException('Testboard ungueltig: ' . $e);
    }
    return $b;
}

function reset_world() {
    $GLOBALS['DB_ROWS'] = array();
    TrelloHttp::$calls = array();
    TrelloHttp::$failPut = false;
    Ticket::$registry = array();
}

function mapping_row($ticketId) {
    foreach ($GLOBALS['DB_ROWS'] as $r) {
        if ((string) $r['ticket_id'] === (string) $ticketId) {
            return $r;
        }
    }
    return null;
}

function calls_of($method, $pathPrefix) {
    return array_values(array_filter(TrelloHttp::$calls, function ($c) use ($method, $pathPrefix) {
        return $c[0] === $method && strpos($c[1], $pathPrefix) === 0;
    }));
}

function comment_payload($cardId, $text) {
    return array('action' => array('data' => array(
        'card' => array('id' => $cardId),
        'text' => $text,
    )));
}

$A = board(1, 'a');
$B = board(2, 'b');
$svc = new TrelloSyncService(new FakeConfig());

// =====================================================================
echo "S1: Board-Feld geleert, danach anderer Kunde (F1)\n";
// =====================================================================
reset_world();
$t = new Ticket(10);
$t->board = $A;
$svc->onTicketCreated($t);
$row = mapping_row(10);
$card = $row['card_id'];
check('Karte auf Board A angelegt und verknuepft', $row['board_id'], $A->boardId);

$t->board = null;
$svc->onTicketUpdated($t);
check('Feld geleert: Mapping stillgelegt', mapping_row(10)['status'], 'inactive');

TrelloHttp::$calls = array();
$t->board = $B;
$svc->onTicketUpdated($t);
$puts = calls_of('PUT', 'cards/' . $card);
check('anderer Kunde gewaehlt: Karte wird verschoben (1 PUT)', count($puts), 1);
check('Ziel des Verschiebens ist Board B',
    isset($puts[0]) ? $puts[0][2]['idBoard'] : null, $B->boardId);
check('Mapping zeigt auf Board B', mapping_row(10)['board_id'], $B->boardId);
check('Mapping wieder aktiv', mapping_row(10)['status'], 'active');
check('keine zweite Karte angelegt', count(calls_of('POST', 'cards')) - count(calls_of('POST', 'cards/')), 0);

TrelloHttp::$calls = array();
$svc->onThreadEntry(new FakeEntry($t, 'Antwort an Kunde B'));
check('Agenten-Antwort geht als Kommentar an die (verschobene) Karte',
    count(calls_of('POST', 'cards/' . $card . '/actions/comments')), 1);

// =====================================================================
echo "\nS1b: Verschieben scheitert\n";
// =====================================================================
reset_world();
$t = new Ticket(11);
$t->board = $A;
$svc->onTicketCreated($t);
$t->board = null;
$svc->onTicketUpdated($t);
TrelloHttp::$failPut = true;
$t->board = $B;
$svc->onTicketUpdated($t);
check('Mapping bleibt stillgelegt', mapping_row(11)['status'], 'inactive');
check('Mapping zeigt weiter auf Board A', mapping_row(11)['board_id'], $A->boardId);

TrelloHttp::$calls = array();
$svc->onThreadEntry(new FakeEntry($t, 'darf nicht zu Kunde A'));
check('keine Antwort auf das Board des frueheren Kunden',
    count(calls_of('POST', 'cards/')), 0);

// =====================================================================
echo "\nS2: direkter Board-Wechsel (F2)\n";
// =====================================================================
reset_world();
$t = new Ticket(12);
$t->board = $A;
$svc->onTicketCreated($t);
$card = mapping_row(12)['card_id'];
$t->board = $B;
$svc->onTicketUpdated($t);
check('link() schreibt das neue Board ins Mapping', mapping_row(12)['board_id'], $B->boardId);

TrelloHttp::$calls = array();
$svc->onTicketUpdated($t);
check('weiteres Update verschiebt nicht erneut',
    count(array_filter(calls_of('PUT', 'cards/' . $card), function ($c) {
        return isset($c[2]['idBoard']);
    })), 0);

check('link() direkt: Upsert aktualisiert board_id',
    (TrelloMap::link(12, $card, $A->boardId) && mapping_row(12)['board_id'] === $A->boardId), true);

// =====================================================================
echo "\nS3: eingehende Ereignisse nur fuer das eigene Board (F3)\n";
// =====================================================================
reset_world();
$t = new Ticket(13);
$t->board = $A;
$svc->onTicketCreated($t);
$card = mapping_row(13)['card_id'];
$t->board = $B;
$svc->onTicketUpdated($t);

check('Kommentar von Board A (frueherer Kunde) wird ignoriert',
    $svc->inboundCommentCard(comment_payload($card, 'von A'), $A), 'ignored: card not linked');
check('Kommentar von Board B wird angenommen',
    $svc->inboundCommentCard(comment_payload($card, 'von B'), $B), 'note added');
check('Statuswechsel von Board A wird ignoriert',
    $svc->inboundUpdateCard(array('action' => array('data' => array(
        'card' => array('id' => $card), 'listAfter' => array('name' => 'Closed')))), $A),
    'ignored: card not linked');

$t->board = null;
$svc->onTicketUpdated($t);
check('stillgelegtes Mapping: Kommentar auch vom richtigen Board ignoriert',
    $svc->inboundCommentCard(comment_payload($card, 'nach Stilllegung'), $B), 'ignored: card not linked');
check('nur die eine Notiz von Board B ist im Ticket', count($t->thread->notes), 1);

check('Dublettenpruefung beim Anlegen sieht weiterhin jedes Mapping',
    TrelloMap::getTicketId($card), 13);
check('Lookup mit ungueltiger Board-ID liefert nichts',
    TrelloMap::getActiveTicketIdByCard($card, "x' OR '1'='1"), null);

// =====================================================================
echo "\nS4: Trello-Kommentar als Text (F4)\n";
// =====================================================================
reset_world();
$t = new Ticket(14);
$t->board = $A;
$svc->onTicketCreated($t);
$card = mapping_row(14)['card_id'];
$evil = '<img src="https://tracker.example.com/p.gif"> Klick <a href="https://phish.example.com">hier</a>, a<b';
$svc->inboundCommentCard(comment_payload($card, $evil), $A);
$note = isset($t->thread->notes[0]['note']) ? $t->thread->notes[0]['note'] : null;
check('Notiz-Body ist ein TextThreadEntryBody', $note instanceof TextThreadEntryBody, true);
check('Body-Typ ist text', $note ? $note->getType() : null, 'text');
check('Text vollstaendig und escaped, keine Tags',
    $note ? $note->body : null,
    '&lt;img src=&quot;https://tracker.example.com/p.gif&quot;&gt; Klick '
    . '&lt;a href=&quot;https://phish.example.com&quot;&gt;hier&lt;/a&gt;, a&lt;b');
check('"a<b" am Ende bleibt erhalten', $note ? substr($note->body, -6) : null, 'a&lt;b');

echo "\n$passed gruen, $failed rot\n";
exit($failed === 0 ? 0 : 1);
