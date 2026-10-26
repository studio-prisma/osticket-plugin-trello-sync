<?php
/**
 * Unit-Tests fuer TrelloRules.
 *
 * Schwerpunkt: der Notizfilter (AC-7). Interne Notizen duerfen osTicket
 * unter keinen Umstaenden verlassen — das ist die datenschutzkritischste
 * Entscheidung im Plugin und wird hier erschoepfend geprueft.
 *
 * Ausfuehren:  php tests/test_rules.php
 */

require_once __DIR__ . '/bootstrap.php';
trello_test_require('class.trello_rules.php');

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
echo "AC-7: Notizfilter (Positivliste)\n";
// =====================================================================

check('Ursprungsnachricht (M) wird uebertragen',
    TrelloRules::isSyncableEntryType('M'), true);

check('oeffentliche Antwort (R) wird uebertragen',
    TrelloRules::isSyncableEntryType('R'), true);

check('INTERNE NOTIZ (N) wird NIEMALS uebertragen',
    TrelloRules::isSyncableEntryType('N'), false);

check('isInternalNote erkennt N',
    TrelloRules::isInternalNote('N'), true);

// Unbekannte und kuenftige Typen duerfen nie durchrutschen.
foreach (array('X', 'n', 'm', 'r', '', 'MR', 'note', '0', 'NN') as $t) {
    check("unbekannter Typ '" . $t . "' wird abgelehnt",
        TrelloRules::isSyncableEntryType($t), false);
}

check('null wird abgelehnt',        TrelloRules::isSyncableEntryType(null), false);
check('Zahl wird abgelehnt',        TrelloRules::isSyncableEntryType(1), false);
check('Array wird abgelehnt',       TrelloRules::isSyncableEntryType(array('M')), false);
check('true wird abgelehnt',        TrelloRules::isSyncableEntryType(true), false);

// =====================================================================
echo "\nStatus <-> Liste\n";
// =====================================================================

$statuses = array(1 => 'Open', 2 => 'Resolved', 3 => 'Closed', 4 => 'In Bearbeitung');

check('exakter Treffer',
    TrelloRules::listNameToStatusId('Resolved', $statuses), 2);
check('Gross-/Kleinschreibung egal',
    TrelloRules::listNameToStatusId('rESOLVED', $statuses), 2);
check('Randleerzeichen egal',
    TrelloRules::listNameToStatusId('  Closed  ', $statuses), 3);
check('Umlaute/Leerzeichen im Namen',
    TrelloRules::listNameToStatusId('in bearbeitung', $statuses), 4);
check('kein Treffer ergibt null',
    TrelloRules::listNameToStatusId('Backlog', $statuses), null);
check('leerer Name ergibt null',
    TrelloRules::listNameToStatusId('', $statuses), null);
check('null ergibt null',
    TrelloRules::listNameToStatusId(null, $statuses), null);

$lists = array(
    array('id' => 'aaaaaaaaaaaaaaaaaaaaaaaa', 'name' => 'Open'),
    array('id' => 'bbbbbbbbbbbbbbbbbbbbbbbb', 'name' => 'Closed'),
);
check('Status -> Listen-ID',
    TrelloRules::statusNameToListId('closed', $lists), 'bbbbbbbbbbbbbbbbbbbbbbbb');
check('Status ohne Liste ergibt null',
    TrelloRules::statusNameToListId('Resolved', $lists), null);
check('kaputter Listeneintrag wird uebersprungen',
    TrelloRules::statusNameToListId('Open', array(array('name' => 'Open'))), null);

// =====================================================================
echo "\nBoard-Wechsel\n";
// =====================================================================

$A = 'aaaaaaaaaaaaaaaaaaaaaaaa';
$B = 'bbbbbbbbbbbbbbbbbbbbbbbb';

check('kein Board, kein Mapping -> nichts',
    TrelloRules::boardTransition(null, null), 'none');
check('neues Board -> anlegen',
    TrelloRules::boardTransition(null, $A), 'create');
check('Board gewechselt -> verschieben',
    TrelloRules::boardTransition($A, $B), 'move');
check('gleiches Board -> nichts',
    TrelloRules::boardTransition($A, $A), 'none');
check('Board entfernt -> stilllegen (NICHT loeschen)',
    TrelloRules::boardTransition($A, null), 'deactivate');
check('Leerstring zaehlt als leer',
    TrelloRules::boardTransition('', $A), 'create');

// =====================================================================
echo "\nTitel und Kuerzung\n";
// =====================================================================

check('Titel mit Nummer',
    TrelloRules::buildCardTitle('123456', 'Website offline'), '#123456 Website offline');
check('leerer Betreff bekommt Platzhalter',
    TrelloRules::buildCardTitle('1', ''), '#1 (ohne Betreff)');
check('kurzer Text bleibt unveraendert',
    TrelloRules::truncate('kurz', 100), 'kurz');
check('langer Text wird gekuerzt',
    mb_strlen(TrelloRules::truncate(str_repeat('a', 500), 100)), 100);
check('Multibyte wird nicht zerschnitten',
    TrelloRules::truncate('äöüß', 10), 'äöüß');
check('max 0 ergibt Leerstring',
    TrelloRules::truncate('abc', 0), '');

// =====================================================================
echo "\nHerkunftshinweis\n";
// =====================================================================

check('gueltiger ShortLink erzeugt Hinweis',
    strpos(TrelloRules::originNote('aBc12345'), 'https://trello.com/c/aBc12345') !== false, true);
check('Sonderzeichen im ShortLink werden abgelehnt',
    TrelloRules::originNote('../../etc/passwd'), '');
check('leerer ShortLink ergibt Leerstring',
    TrelloRules::originNote(''), '');
check('null ergibt Leerstring',
    TrelloRules::originNote(null), '');

// =====================================================================
echo "\nHTML-Erkennung und Textaufbereitung\n";
// =====================================================================

check('Tabellen-Markup wird als HTML erkannt',
    TrelloRules::looksLikeHtml('<table role="presentation"><tr><td>x</td></tr></table>'), true);
check('einfacher Absatz wird als HTML erkannt',
    TrelloRules::looksLikeHtml('<p>Hallo</p>'), true);
check('Fliesstext mit Kleiner-Zeichen ist kein HTML',
    TrelloRules::looksLikeHtml('Bedingung: a < b und c > d'), false);
check('reiner Text ist kein HTML',
    TrelloRules::looksLikeHtml('Nur Text'), false);
check('Leerstring ist kein HTML',
    TrelloRules::looksLikeHtml(''), false);
check('null ist kein HTML',
    TrelloRules::looksLikeHtml(null), false);

check('Einrueckungen werden entfernt',
    TrelloRules::tidyText("   Zeile A   \n      Zeile B  "), "Zeile A\nZeile B");
check('mehrfache Leerzeilen werden zusammengefasst',
    TrelloRules::tidyText("A\n\n\n\n\nB"), "A\n\nB");
check('mehrfache Leerzeichen werden zusammengefasst',
    TrelloRules::tidyText("A     B"), "A B");
check('CRLF wird normalisiert',
    TrelloRules::tidyText("A\r\nB"), "A\nB");
check('geschuetztes Leerzeichen verschwindet',
    TrelloRules::tidyText("A\xC2\xA0B"), "A B");
check('Rand-Leerraum wird getrimmt',
    TrelloRules::tidyText("\n\n  Inhalt  \n\n"), "Inhalt");
check('Umlaute bleiben erhalten',
    TrelloRules::tidyText("Grüße   aus  Köln"), "Grüße aus Köln");

// Statuszustand: nur open/closed duerfen aus Trello gesetzt werden.
check('Zustand open erlaubt', TrelloRules::isSyncableStatusState('open'), true);
check('Zustand closed erlaubt', TrelloRules::isSyncableStatusState('closed'), true);
check('Grossschreibung egal', TrelloRules::isSyncableStatusState(' Closed '), true);
check('Zustand deleted gesperrt', TrelloRules::isSyncableStatusState('deleted'), false);
check('Zustand archived gesperrt', TrelloRules::isSyncableStatusState('archived'), false);
check('leerer Zustand gesperrt', TrelloRules::isSyncableStatusState(''), false);
check('null gesperrt', TrelloRules::isSyncableStatusState(null), false);

// Webhook-Registrierung: nur Staff-Bereich und Cron.
check('scp-Seite prueft Webhooks', TrelloRules::shouldEnsureWebhooks('/scp/tickets.php'), true);
check('scp im Unterverzeichnis', TrelloRules::shouldEnsureWebhooks('/support/scp/index.php'), true);
check('Cron prueft Webhooks', TrelloRules::shouldEnsureWebhooks('/api/cron.php'), true);
check('CLI-Cron mit relativem Pfad', TrelloRules::shouldEnsureWebhooks('api/cron.php'), true);
check('Kundenportal nicht', TrelloRules::shouldEnsureWebhooks('/index.php'), false);
check('Kunden-Login nicht', TrelloRules::shouldEnsureWebhooks('/login.php'), false);
check('Webhook-Eingang nicht', TrelloRules::shouldEnsureWebhooks('/api/http.php'), false);
check('Backslash-Pfad wird normalisiert', TrelloRules::shouldEnsureWebhooks('\\scp\\index.php'), true);
check('leerer SCRIPT_NAME nicht', TrelloRules::shouldEnsureWebhooks(''), false);

echo "\n$passed gruen, $failed rot\n";
exit($failed === 0 ? 0 : 1);
