<?php
/**
 * Unit-Tests fuer TrelloSignature.
 *
 * Laeuft OHNE osTicket — die Klasse ist bewusst abhaengigkeitsfrei.
 *
 * Ausfuehren:
 *   php tests/test_signature.php
 *
 * Exit-Code 0 = alle Tests gruen, 1 = mindestens ein Test rot.
 */

// Funktioniert in beiden Layouts: Repo (../plugin/) und Installation (../).
require_once __DIR__ . '/bootstrap.php';
trello_test_require('class.trello_signature.php');

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

// --- Testdaten -----------------------------------------------------------
$secret   = 'test-secret-do-not-use-in-production';
$callback = 'https://helpdesk.example.org/api/trello';
$body     = '{"action":{"type":"updateCard","id":"5f0000000000000000000001"}}';

$validSig = base64_encode(hash_hmac('sha1', $body . $callback, $secret, true));

echo "TrelloSignature\n";

// --- Happy Path ----------------------------------------------------------
check('gueltige Signatur wird akzeptiert',
    TrelloSignature::isValid($body, $callback, $secret, $validSig), true);

// --- Unhappy Paths -------------------------------------------------------
check('falsche Signatur wird abgelehnt',
    TrelloSignature::isValid($body, $callback, $secret, 'AAAAAAAAAAAAAAAAAAAAAAAAAAA='), false);

check('leere Signatur wird abgelehnt',
    TrelloSignature::isValid($body, $callback, $secret, ''), false);

check('LEERES SECRET fuehrt NIE zu einem Pass',
    TrelloSignature::isValid($body, $callback, '', $validSig), false);

check('null als Secret wird abgelehnt',
    TrelloSignature::isValid($body, $callback, null, $validSig), false);

check('leere Callback-URL wird abgelehnt',
    TrelloSignature::isValid($body, '', $secret, $validSig), false);

check('veraenderter Body invalidiert die Signatur',
    TrelloSignature::isValid($body . 'x', $callback, $secret, $validSig), false);

check('andere Callback-URL invalidiert die Signatur',
    TrelloSignature::isValid($body, 'https://evil.example.org/api/trello', $secret, $validSig), false);

check('anderes Secret invalidiert die Signatur',
    TrelloSignature::isValid($body, $callback, $secret . 'x', $validSig), false);

check('leerer Body mit passender Signatur wird akzeptiert',
    TrelloSignature::isValid('', $callback, $secret,
        base64_encode(hash_hmac('sha1', '' . $callback, $secret, true))), true);

// --- Regression gegen die Luecken des Referenz-Plugins -------------------
echo "\nRegression: Luecken des Referenz-Plugins\n";

check('gefaelschter X-Real-IP-Header hat keinerlei Wirkung',
    TrelloSignature::isValid($body, $callback, $secret, '203.0.113.10'), false);

check('fehlender Header laesst nichts durch',
    TrelloSignature::extractHeader(array('HTTP_X_REAL_IP' => '203.0.113.10')), '');

check('Header wird korrekt gelesen',
    TrelloSignature::extractHeader(array('HTTP_X_TRELLO_WEBHOOK' => $validSig)), $validSig);

// --- Ergebnis ------------------------------------------------------------
echo "\n$passed gruen, $failed rot\n";
exit($failed === 0 ? 0 : 1);
