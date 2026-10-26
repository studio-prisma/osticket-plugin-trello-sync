<?php
/**
 * Zustandsdiagnose des Trello-Sync-Plugins.
 *
 * Beantwortet in einem Durchlauf, warum "nichts passiert": Erweiterungen,
 * Konfiguration, Custom-List, Board-Registry, Webhooks, Mapping.
 *
 * Secrets werden NIE vollstaendig ausgegeben — nur Laenge und die ersten
 * Zeichen, damit man "gesetzt vs. leer" unterscheiden kann.
 *
 * Aufruf aus dem Plugin-Verzeichnis:
 *   php diagnose.php
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

/**
 * Ausgabe zusaetzlich in eine Datei schreiben:  --out=/pfad/report.txt
 *
 * Noetig bei Plesk-Aufgaben, die stdout nicht anzeigen. Der Pfad sollte
 * AUSSERHALB des Dokumentenstamms liegen — osTicket schuetzt include/ zwar
 * per .htaccess ("Deny from all"), unter Plesk kann nginx statische Dateien
 * aber am Apache vorbei ausliefern. Dann waere ein Report im Plugin-Ordner
 * ueber das Web abrufbar.
 */
$__outFile = null;
foreach ($argv as $a) {
    if (strpos($a, '--out=') === 0) {
        $__outFile = substr($a, 6);
    }
}
if ($__outFile) {
    $dir = dirname($__outFile);
    if (!is_dir($dir) || !is_writable($dir)) {
        fwrite(STDERR, "Zielverzeichnis nicht beschreibbar: $dir\n");
        exit(1);
    }
    @unlink($__outFile);
    ob_start(function ($chunk) use ($__outFile) {
        file_put_contents($__outFile, $chunk, FILE_APPEND);
        return $chunk;
    }, 1);
    register_shutdown_function(function () use ($__outFile) {
        @chmod($__outFile, 0600);
    });
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

function h($t) { echo "\n", $t, "\n", str_repeat('-', strlen($t)), "\n"; }
function ok($t)   { echo "  [ ok ]   $t\n"; }
function bad($t)  { echo "  [FEHLT]  $t\n"; }
function info($t) { echo "           $t\n"; }
function mask($v) {
    $v = (string) $v;
    if ($v === '') return '(leer)';
    return substr($v, 0, 4) . str_repeat('.', max(0, min(8, strlen($v) - 4)))
         . ' [' . strlen($v) . ' Zeichen]';
}

echo str_repeat('=', 70), "\n  Trello-Sync — Diagnose\n", str_repeat('=', 70), "\n";

// --- 1. PHP -------------------------------------------------------------
h('1. PHP');
info('Version: ' . PHP_VERSION);
foreach (array('curl', 'mbstring', 'hash', 'json') as $ext) {
    extension_loaded($ext) ? ok("Erweiterung $ext") : bad("Erweiterung $ext");
}

// OPcache: haeufigste Ursache dafuer, dass ein hochgeladener Fix wirkungslos
// bleibt. Bei validate_timestamps=0 laeuft weiter der alte Bytecode.
if (function_exists('opcache_get_configuration')) {
    $oc = @opcache_get_configuration();
    $enabled = !empty($oc['directives']['opcache.enable']);
    if (!$enabled) {
        ok('OPcache aus — hochgeladene Aenderungen greifen sofort');
    } else {
        $vt   = !empty($oc['directives']['opcache.validate_timestamps']);
        $freq = isset($oc['directives']['opcache.revalidate_freq'])
              ? (int) $oc['directives']['opcache.revalidate_freq'] : 0;
        if (!$vt) {
            bad('OPcache aktiv mit validate_timestamps=0 — hochgeladene Dateien '
              . 'werden IGNORIERT, bis PHP-FPM neu gestartet wird!');
        } else {
            ok('OPcache aktiv, validate_timestamps=1, revalidate_freq=' . $freq . 's');
            if ($freq > 5) {
                info('  Aenderungen greifen erst nach bis zu ' . $freq . ' Sekunden.');
            }
        }
    }
} else {
    info('OPcache-Status nicht abfragbar (CLI nutzt oft eine andere Konfiguration als FPM)');
    info('  Achtung: dieser Check laeuft im CLI. Entscheidend ist die FPM-Konfiguration.');
}

// Laufender Codestand
info('Plugin-Dateien:  ' . (defined('TRELLO_SYNC_ROOT') ? TRELLO_SYNC_ROOT : '?'));
info('Service-Version: ' . (class_exists('TrelloSyncService') ? TrelloSyncService::VERSION : '?'));

// --- 2. Plugin ----------------------------------------------------------
h('2. Plugin und Instanz');
$config = TrelloSyncPlugin::getActiveConfig();
if (!$config) {
    bad('Keine aktive Plugin-Instanz. bootstrap() laeuft nicht.');
    info('Verwalten -> Plugins -> Trello Sync -> Instanz anlegen UND aktivieren.');
    echo "\nAbbruch: ohne aktive Instanz ist alles weitere gegenstandslos.\n";
    exit(1);
}
ok('Instanz ist aktiv, bootstrap() laeuft');

// --- 3. Konfiguration ---------------------------------------------------
h('3. Konfiguration');
$key    = trim((string) $config->get('trello_api_key'));
$token  = trim((string) $config->get('trello_api_token'));
$secret = trim((string) $config->get('trello_api_secret'));
$member = trim((string) $config->get('trello_bot_member_id'));
$listId = $config->get('trello_board_list_id');
$field  = trim((string) $config->get('trello_board_field'));

$key    !== '' ? ok('API-Key:    ' . mask($key))    : bad('API-Key fehlt');
$token  !== '' ? ok('API-Token:  ' . mask($token))  : bad('API-Token fehlt');
$secret !== '' ? ok('API-Secret: ' . mask($secret)) : bad('API-Secret fehlt');
$member !== '' ? ok('Member-ID:  ' . $member)       : bad('Member-ID fehlt (Loop-Schutz)');
info('Board-Liste (roh): ' . var_export($listId, true));
info('Feldname:          ' . ($field !== '' ? $field : '(leer)'));

$callback = TrelloSyncConfig::buildCallbackUrl();
TrelloSyncConfig::isSecureCallback($callback)
    ? ok('Callback-URL: ' . $callback)
    : bad('Callback-URL nicht https: ' . ($callback !== '' ? $callback : '(leer)'));

if ($key === '' || $token === '' || $secret === '') {
    echo "\nAbbruch: Zugangsdaten unvollstaendig. Plugin-Konfiguration speichern.\n";
    exit(1);
}

// --- 4. Board-Registry --------------------------------------------------
h('4. Board-Registry');
TrelloBoardRegistry::resetCache();
$boards   = TrelloBoardRegistry::all();
$problems = TrelloBoardRegistry::getProblems();

foreach ($problems as $p) { bad($p); }

if (!$boards) {
    bad('Keine gueltigen Boards.');
    info('Pruefen: Liste gewaehlt? Items angelegt (nicht nur Properties)?');
    info('Items aktiviert? Properties board_id / inbox_list_id / default_dept_id / default_user_email gefuellt?');
} else {
    ok(count($boards) . ' Board(s) geladen:');
    foreach ($boards as $b) {
        info(sprintf('  "%s"  board=%s  inbox=%s  dept=%d  mail=%s',
            $b->label, $b->boardId, $b->inboxListId, $b->deptId, $b->userEmail));
    }
}

// --- 5. Trello erreichbar ----------------------------------------------
h('5. Trello-API');
$http = new TrelloHttp($key, $token);
$me   = $http->get('members/me', array('fields' => 'id,username'));
if (!$me || empty($me['id'])) {
    bad('members/me fehlgeschlagen: ' . $http->getLastError());
} else {
    ok('Verbunden als ' . (isset($me['username']) ? $me['username'] : '?') . ' (' . $me['id'] . ')');
    if ($member !== '' && $member !== $me['id']) {
        bad('Konfigurierte Member-ID weicht ab!');
        info('konfiguriert: ' . $member);
        info('tatsaechlich: ' . $me['id']);
        info('Folge: der Loop-Schutz greift nicht korrekt.');
    } elseif ($member === $me['id']) {
        ok('Member-ID passt zum Token');
        info('Hinweis: gehoert der Token DIR (nicht einem Bot-Account), werden');
        info('deine eigenen Kartenbewegungen als Eigen-Echo verworfen.');
    }
}

// Tatsaechliche Rechte des Tokens. "missing scopes" beim Schreiben laesst
// sich nur hier eindeutig klaeren — lesender Zugriff funktioniert auch mit
// einem reinen read-Token.
$tok = $http->get('tokens/' . $token, array('fields' => 'permissions,dateExpires,idMember'));
if (!is_array($tok)) {
    bad('Token-Info nicht abrufbar: ' . $http->getLastError());
} else {
    $perms = isset($tok['permissions']) && is_array($tok['permissions']) ? $tok['permissions'] : array();
    $can = array('read' => false, 'write' => false);
    foreach ($perms as $p) {
        if (!empty($p['read']))  { $can['read']  = true; }
        if (!empty($p['write'])) { $can['write'] = true; }
    }
    $can['read']  ? ok('Token darf lesen')   : bad('Token darf NICHT lesen');
    $can['write']
        ? ok('Token darf schreiben')
        : bad('Token darf NICHT schreiben — das ist die Ursache von "missing scopes". '
            . 'Neuen Token mit scope=read,write erzeugen UND in der Plugin-Config speichern.');

    if (!empty($tok['dateExpires'])) {
        bad('Token laeuft ab: ' . $tok['dateExpires'] . ' — besser expiration=never');
    } else {
        ok('Token laeuft nicht ab');
    }
}

// Schreibtest gegen die Eingangsliste: legt eine Karte an und loescht sie
// sofort wieder. Klaert unabhaengig von der Rechte-Anzeige, ob Schreiben
// auf DIESEM Board wirklich funktioniert.
foreach ($boards as $b) {
    $probe = $http->post('cards', array(
        'idList' => $b->inboxListId,
        'name'   => '[Diagnose] Testkarte — wird sofort geloescht',
    ));
    if ($probe && !empty($probe['id'])) {
        ok('Schreibtest auf "' . $b->label . '" erfolgreich');
        $http->delete('cards/' . $probe['id']);
        info('  Testkarte wieder entfernt (' . $probe['id'] . ')');
    } else {
        bad('Schreibtest auf "' . $b->label . '" fehlgeschlagen: ' . $http->getLastError());
    }
}

foreach ($boards as $b) {
    $res = $http->get('boards/' . $b->boardId, array('fields' => 'name'));
    $res && !empty($res['name'])
        ? ok('Board erreichbar: "' . $b->label . '" -> ' . $res['name'])
        : bad('Board "' . $b->label . '" nicht erreichbar: ' . $http->getLastError());

    $lists = $http->get('lists/' . $b->inboxListId, array('fields' => 'name'));
    $lists && !empty($lists['name'])
        ? ok('  Eingangsliste: ' . $lists['name'])
        : bad('  Eingangsliste nicht erreichbar: ' . $http->getLastError());
}

// --- 6. Webhooks --------------------------------------------------------
h('6. Webhooks');
$rows = TrelloBoardStore::all();
if (!$rows) {
    bad('Keine Webhook-Eintraege in ' . TRELLO_BOARD_TABLE);
} else {
    foreach ($rows as $r) {
        if (!empty($r['webhook_id'])) {
            ok(sprintf('%s -> %s', $r['label'], $r['webhook_id']));
        } else {
            bad(sprintf('%s: %s', $r['label'], $r['last_error'] ?: 'kein Webhook'));
        }
    }
}

$live = $http->get('tokens/' . $token . '/webhooks');
if (is_array($live)) {
    ok(count($live) . ' Webhook(s) bei Trello registriert:');
    foreach ($live as $w) {
        info(sprintf('  %s -> %s  aktiv=%s',
            isset($w['idModel']) ? $w['idModel'] : '?',
            isset($w['callbackURL']) ? $w['callbackURL'] : '?',
            !empty($w['active']) ? 'ja' : 'nein'));
    }
} else {
    bad('Webhook-Liste nicht abrufbar: ' . $http->getLastError());
}

// --- 7. Mapping ---------------------------------------------------------
h('7. Mapping');
$res = db_query('SELECT COUNT(*) FROM ' . TRELLO_SYNC_TABLE, false);
if ($res) {
    list($n) = db_fetch_row($res);
    info('Verknuepfte Tickets: ' . $n);
}

// --- 8. Feld im Ticket-Formular ----------------------------------------
h('8. Ticket-Feld');
if ($field === '') {
    bad('Kein Feldname konfiguriert.');
} else {
    $res = db_query("SELECT COUNT(*) FROM " . FORM_FIELD_TABLE
        . " WHERE name = " . db_input($field), false);
    if ($res) {
        list($n) = db_fetch_row($res);
        $n > 0
            ? ok("Feld \"$field\" existiert in $n Formular(en)")
            : bad("Feld \"$field\" in keinem Formular gefunden — Variablenname pruefen");
    }
}

echo "\n", str_repeat('=', 70), "\nDiagnose beendet.\n";
