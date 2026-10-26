<?php
/**
 * Instrumentierter Auth-Test gegen die Trello-API.
 *
 * Zweck: aufhoeren zu raten. Dieses Skript sendet denselben Schreib-Request
 * in drei Varianten und zeigt fuer jede den TATSAECHLICH abgesetzten Request
 * (Header + Body) sowie die vollstaendige Antwort. Danach ist belegt, welche
 * Variante funktioniert — statt dass es eine weitere Vermutung gibt.
 *
 * Hintergrund: der curl-Gegentest hat funktioniert, das Plugin nicht. Der
 * Unterschied lag in Details, die man nur sieht, wenn man den ausgehenden
 * Request mitschreibt (CURLINFO_HEADER_OUT).
 *
 * Aufruf:
 *   php probe-auth.php                 nur lesen + Varianten testen
 *   php probe-auth.php --out=/pfad.txt Ausgabe zusaetzlich in Datei
 *
 * Angelegte Testkarten werden sofort wieder geloescht.
 *
 * @copyright 2026 studio-prisma
 * @license GPL-2.0-or-later
 */

if (PHP_SAPI !== 'cli') {
    header('HTTP/1.1 404 Not Found');
    exit;
}
if (!empty($_SERVER['REMOTE_ADDR']) || !empty($_SERVER['REQUEST_METHOD'])) {
    fwrite(STDERR, "Nur auf der Kommandozeile ausfuehrbar.\n");
    exit(1);
}

$outFile = null;
foreach ($argv as $a) {
    if (strpos($a, '--out=') === 0) { $outFile = substr($a, 6); }
}
if ($outFile) {
    $dir = dirname($outFile);
    if (!is_dir($dir) || !is_writable($dir)) {
        fwrite(STDERR, "Zielverzeichnis nicht beschreibbar: $dir\n");
        exit(1);
    }
    @unlink($outFile);
    ob_start(function ($c) use ($outFile) {
        file_put_contents($outFile, $c, FILE_APPEND); return $c;
    }, 1);
    register_shutdown_function(function () use ($outFile) { @chmod($outFile, 0600); });
}

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

$config = TrelloSyncPlugin::getActiveConfig();
if (!$config) {
    fwrite(STDERR, "Plugin-Instanz nicht aktiv.\n");
    exit(1);
}

$key    = trim((string) $config->get('trello_api_key'));
$token  = trim((string) $config->get('trello_api_token'));
$boards = TrelloBoardRegistry::all();

if (!$boards) {
    fwrite(STDERR, "Keine Boards in der Registry.\n");
    exit(1);
}
$board = reset($boards);

/** Maskiert Zugangsdaten in jeder Ausgabe. */
function redact($s) {
    global $key, $token;
    $s = (string) $s;
    if ($key !== '')   { $s = str_replace($key,   '<KEY>',   $s); }
    if ($token !== '') { $s = str_replace($token, '<TOKEN>', $s); }
    return $s;
}

/**
 * Setzt einen POST ab und protokolliert Request wie Antwort vollstaendig.
 */
function probe($label, $url, $postFields, $headers) {
    echo "\n", str_repeat('=', 68), "\n";
    echo "VARIANTE: $label\n";
    echo str_repeat('=', 68), "\n";

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    curl_setopt($ch, CURLINFO_HEADER_OUT, true);   // <- der entscheidende Punkt
    if ($postFields !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
    }
    if ($headers) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }

    $raw    = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $sent   = curl_getinfo($ch, CURLINFO_HEADER_OUT);
    $err    = curl_error($ch);
    curl_close($ch);

    echo "--- Gesendeter Request ---\n";
    echo redact(rtrim((string) $sent)), "\n";
    echo "--- Gesendeter Body ---\n";
    echo redact($postFields === null ? '(keiner)' : $postFields), "\n";
    echo "--- Antwort ---\n";
    echo "HTTP $status", ($err ? "  cURL-Fehler: $err" : ''), "\n";
    echo redact(substr((string) $raw, 0, 600)), "\n";

    $ok = ($status >= 200 && $status < 300);
    echo $ok ? ">>> FUNKTIONIERT\n" : ">>> scheitert\n";

    // Aufraeumen: angelegte Testkarte sofort entfernen
    if ($ok) {
        $d = json_decode((string) $raw, true);
        if (!empty($d['id'])) {
            $del = curl_init();
            curl_setopt($del, CURLOPT_URL, 'https://api.trello.com/1/cards/' . $d['id']
                . '?' . http_build_query(array('key' => $GLOBALS['key'], 'token' => $GLOBALS['token'])));
            curl_setopt($del, CURLOPT_CUSTOMREQUEST, 'DELETE');
            curl_setopt($del, CURLOPT_RETURNTRANSFER, true);
            curl_exec($del);
            curl_close($del);
            echo "    (Testkarte " . $d['id'] . " wieder entfernt)\n";
        }
    }
    return $ok;
}

echo str_repeat('=', 68), "\n";
echo "  Trello Auth-Probe\n";
echo "  Board: {$board->label}   Liste: {$board->inboxListId}\n";
echo str_repeat('=', 68), "\n";
echo "Key-Laenge: " . strlen($key) . "   Token-Laenge: " . strlen($token) . "\n";

$base   = 'https://api.trello.com/1/cards';
$fields = array('idList' => $board->inboxListId, 'name' => '[Probe] Testkarte');

$results = array();

// A) Zugangsdaten im Body — das entspricht dem funktionierenden curl
$results['body'] = probe(
    'A) key/token im BODY (form-encoded)',
    $base,
    http_build_query(array_merge($fields, array('key' => $key, 'token' => $token))),
    array('Content-Type: application/x-www-form-urlencoded')
);

// B) Zugangsdaten im Query-String, Nutzdaten im Body
$results['query'] = probe(
    'B) key/token im QUERY, Daten im Body',
    $base . '?' . http_build_query(array('key' => $key, 'token' => $token)),
    http_build_query($fields),
    array('Content-Type: application/x-www-form-urlencoded')
);

// C) Authorization-Header
$results['header'] = probe(
    'C) Authorization: OAuth Header',
    $base,
    http_build_query($fields),
    array(
        'Content-Type: application/x-www-form-urlencoded',
        sprintf('Authorization: OAuth oauth_consumer_key="%s", oauth_token="%s"', $key, $token),
    )
);

echo "\n", str_repeat('=', 68), "\n  ERGEBNIS\n", str_repeat('=', 68), "\n";
foreach ($results as $mode => $ok) {
    printf("  %-8s %s\n", $mode, $ok ? 'FUNKTIONIERT' : 'scheitert');
}

$winner = null;
foreach ($results as $mode => $ok) { if ($ok) { $winner = $mode; break; } }

if ($winner) {
    echo "\nIn class.trello_http.php setzen:  private \$authMode = '$winner';\n";
} else {
    echo "\nKeine Variante funktioniert. Dann liegt es nicht am Transport —\n";
    echo "Request und Antwort oben zeigen, woran stattdessen.\n";
}
