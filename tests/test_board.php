<?php
/**
 * Unit-Tests fuer TrelloBoard::fromProperties().
 *
 * Laeuft OHNE osTicket — die Klasse ist bewusst abhaengigkeitsfrei gehalten,
 * damit genau diese Validierung isoliert pruefbar ist.
 *
 * Ausfuehren:  php tests/test_board.php
 */

// Funktioniert in beiden Layouts: Repo (../plugin/) und Installation (../).
require_once __DIR__ . '/bootstrap.php';
trello_test_require('class.trello_board.php');

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

function valid(array $override = array()) {
    return array_merge(array(
        'board_id'           => '5f1a2b3c4d5e6f7a8b9c0d1e',
        'inbox_list_id'      => 'a1b2c3d4e5f6a7b8c9d0e1f2',
        'default_dept_id'    => '3',
        'default_user_email' => 'support@example.org',
    ), $override);
}

echo "TrelloBoard::fromProperties\n";

// --- Happy Path ----------------------------------------------------------
$err = null;
$b = TrelloBoard::fromProperties(7, 'Eiskaeffchen', valid(), $err);
check('vollstaendiger Eintrag wird akzeptiert', $b !== null, true);
check('kein Fehler gesetzt', $err, null);
if ($b) {
    check('board_id uebernommen',    $b->boardId,     '5f1a2b3c4d5e6f7a8b9c0d1e');
    check('inbox_list_id uebernommen', $b->inboxListId, 'a1b2c3d4e5f6a7b8c9d0e1f2');
    check('dept als int',            $b->deptId,      3);
    check('label uebernommen',       $b->label,       'Eiskaeffchen');
    check('itemId als int',          $b->itemId,      7);
}

// --- Pflichtfelder -------------------------------------------------------
echo "\nPflichtfelder\n";

foreach (array('board_id', 'inbox_list_id', 'default_dept_id', 'default_user_email') as $key) {
    $props = valid();
    unset($props[$key]);
    $e = null;
    check("fehlendes $key wird abgelehnt",
        TrelloBoard::fromProperties(7, 'X', $props, $e), null);
}

// --- Formatpruefung ------------------------------------------------------
echo "\nFormate\n";

$e = null;
check('zu kurze board_id wird abgelehnt',
    TrelloBoard::fromProperties(7, 'X', valid(array('board_id' => 'abc')), $e), null);

check('board_id mit Nicht-Hex wird abgelehnt',
    TrelloBoard::fromProperties(7, 'X', valid(array('board_id' => 'zzzzzzzzzzzzzzzzzzzzzzzz')), $e), null);

check('Kurzlink statt Board-ID wird abgelehnt',
    TrelloBoard::fromProperties(7, 'X', valid(array('board_id' => 'aBcD1234')), $e), null);

check('ungueltige E-Mail wird abgelehnt',
    TrelloBoard::fromProperties(7, 'X', valid(array('default_user_email' => 'kein-at-zeichen')), $e), null);

check('dept 0 wird abgelehnt',
    TrelloBoard::fromProperties(7, 'X', valid(array('default_dept_id' => '0')), $e), null);

check('negative dept wird abgelehnt',
    TrelloBoard::fromProperties(7, 'X', valid(array('default_dept_id' => '-1')), $e), null);

check('nicht-numerische dept wird abgelehnt',
    TrelloBoard::fromProperties(7, 'X', valid(array('default_dept_id' => 'Support')), $e), null);

check('itemId 0 wird abgelehnt',
    TrelloBoard::fromProperties(0, 'X', valid(), $e), null);

// --- Robustheit ----------------------------------------------------------
echo "\nRobustheit\n";

$e = null;
$b2 = TrelloBoard::fromProperties(9, '   ', valid(), $e);
check('leeres Label bekommt Ersatzbezeichnung',
    ($b2 !== null && $b2->label === 'Board 5f1a2b3c'), true);

$b3 = TrelloBoard::fromProperties(9, 'Y', valid(array('board_id' => '  5f1a2b3c4d5e6f7a8b9c0d1e  ')), $e);
check('Leerzeichen um IDs werden getrimmt',
    ($b3 !== null && $b3->boardId === '5f1a2b3c4d5e6f7a8b9c0d1e'), true);

check('Grossschreibung in der ID ist zulaessig',
    TrelloBoard::fromProperties(9, 'Y', valid(array('board_id' => '5F1A2B3C4D5E6F7A8B9C0D1E')), $e) !== null, true);

check('leeres Property-Array wird abgelehnt',
    TrelloBoard::fromProperties(9, 'Y', array(), $e), null);

check('Fehlertext wird gesetzt', is_string($e) && $e !== '', true);

echo "\n$passed gruen, $failed rot\n";
exit($failed === 0 ? 0 : 1);
