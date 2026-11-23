<?php
/**
 * Regressionstest Log-Flooding.
 *
 * Jeder unsignierte POST auf den Webhook schrieb bis 0.6.0 eine Warnung ins
 * osTicket-Syslog, also eine Zeile in die Datenbank. Jetzt geht hoechstens
 * eine Meldung je Art und TrelloLog::THROTTLE_SECONDS dorthin; das
 * PHP-Error-Log bekommt weiter jede.
 *
 * Ausfuehren:  php tests/test_log_throttle.php
 */

require_once __DIR__ . '/bootstrap.php';
trello_test_require('class.trello_log.php');

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

class FakeOst {
    public $warnings = array();
    public function logWarning($title, $msg, $alert = true) { $this->warnings[] = $msg; }
    public function logError($title, $msg, $alert = true) {}
    public function logDebug($title, $msg, $force = false) {}
}

/** Ersatz fuer osTickets Config: get()/set(), set() liefert null bei Fehler. */
class MemoryStore {
    public $data = array();
    public $failSet = false;
    public $throw = false;
    public function get($k) {
        if ($this->throw) { throw new RuntimeException('DB weg'); }
        return isset($this->data[$k]) ? $this->data[$k] : null;
    }
    public function set($k, $v) {
        if ($this->failSet) { return null; }
        $this->data[$k] = $v;
        return $v;
    }
}

// PHP-Error-Log in eine Datei umleiten, damit es zaehlbar ist.
$errLog = tempnam(sys_get_temp_dir(), 'trello-test-');
ini_set('error_log', $errLog);

$store = new MemoryStore();
TrelloLog::$throttleStore = $store;

$ost = new FakeOst();
$GLOBALS['ost'] = $ost;
$t0 = 1700000000;

echo "Drossel\n";

for ($i = 0; $i < 200; $i++) {
    TrelloLog::warnThrottled('bad-signature', 'Webhook mit ungueltiger Signatur abgewiesen.', $t0 + $i);
}
check('200 unsignierte POSTs -> 1 Syslog-Eintrag', count($ost->warnings), 1);

$lines = array_filter(file($errLog), function ($l) {
    return strpos($l, '[trello-sync] WARN:') !== false;
});
check('PHP-Error-Log bekommt alle 200', count($lines), 200);

check('andere Meldungsart hat eigene Drossel',
    TrelloLog::warnThrottled('oversize', 'zu gross', $t0 + 10), true);

check('innerhalb des Intervalls weiter gedrosselt',
    TrelloLog::warnThrottled('bad-signature', 'x', $t0 + TrelloLog::THROTTLE_SECONDS - 1), false);

check('nach Ablauf des Intervalls wieder ein Syslog-Eintrag',
    TrelloLog::warnThrottled('bad-signature', 'x', $t0 + TrelloLog::THROTTLE_SECONDS), true);

check('Syslog-Text nennt die Drossel',
    strpos($ost->warnings[0], 'nur im PHP-Error-Log') !== false, true);

echo "\nEingaben\n";

check('ungueltiger Schluessel: kein Syslog',
    TrelloLog::warnThrottled('../../etc/x', 'x', $t0), false);
check('leerer Schluessel abgelehnt', TrelloLog::warnThrottled('', 'x', $t0), false);
check('Zeitstempel landet unter dem Schluessel im Speicher',
    $store->data['bad-signature'], (string) ($t0 + TrelloLog::THROTTLE_SECONDS));

echo "\nSpeicher nicht nutzbar -> lieber kein Syslog als keine Drossel\n";

$n = count($ost->warnings);
$store->data = array();
$store->failSet = true;
check('set() scheitert: kein Syslog', TrelloLog::warnThrottled('bad-signature', 'x', $t0), false);
$store->failSet = false;
$store->throw = true;
check('get() wirft: kein Syslog, keine Exception', TrelloLog::warnThrottled('bad-signature', 'x', $t0), false);
$store->throw = false;
check('Syslog blieb unberuehrt', count($ost->warnings), $n);

$GLOBALS['ost'] = null;
$store->data = array();
check('ohne $ost (Bootstrap-Phase): kein Fehler, false',
    TrelloLog::warnThrottled('bad-signature', 'x', $t0), false);

@unlink($errLog);

echo "\n$passed gruen, $failed rot\n";
exit($failed === 0 ? 0 : 1);
