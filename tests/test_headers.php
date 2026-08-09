<?php
/**
 * Regressionstest gegen den Header-Listen-Bug (0.5.6).
 *
 * Ein null-Eintrag in CURLOPT_HTTPHEADER beendet fuer libcurl die Liste —
 * nachfolgende Header, insbesondere Content-Type, werden verworfen. Trello
 * antwortet darauf mit "401 missing scopes", was auf die Rechte zeigt statt
 * auf die Ursache.
 *
 * Geprueft wird die Aufbaulogik isoliert: die Header-Liste darf niemals
 * einen leeren oder null-Eintrag enthalten.
 */

$passed = 0; $failed = 0;
function check($name, $actual, $expected) {
    global $passed, $failed;
    if ($actual === $expected) { $passed++; echo "  PASS  $name\n"; }
    else { $failed++; echo "  FAIL  $name (erwartet " . var_export($expected, true)
        . ", erhalten " . var_export($actual, true) . ")\n"; }
}

/** Nachbau der Aufbaulogik aus TrelloHttp::request() */
function buildHeaders($authHeader, $hasBody) {
    $headers = array();
    if ($authHeader !== null && $authHeader !== '') { $headers[] = $authHeader; }
    if ($hasBody) { $headers[] = 'Content-Type: application/x-www-form-urlencoded'; }
    return $headers;
}

function hasEmpty(array $h) {
    foreach ($h as $v) { if ($v === null || $v === '') { return true; } }
    return false;
}

echo "Header-Liste\n";

$h = buildHeaders(null, true);   // Modus 'body'
check('body-Modus: kein leerer Eintrag', hasEmpty($h), false);
check('body-Modus: Content-Type vorhanden',
    in_array('Content-Type: application/x-www-form-urlencoded', $h, true), true);
check('body-Modus: genau ein Header', count($h), 1);

$h = buildHeaders('Authorization: OAuth x', true);   // Modus 'header'
check('header-Modus: kein leerer Eintrag', hasEmpty($h), false);
check('header-Modus: zwei Header', count($h), 2);
check('header-Modus: Content-Type ueberlebt',
    in_array('Content-Type: application/x-www-form-urlencoded', $h, true), true);

$h = buildHeaders(null, false);  // GET
check('GET: leere Liste', $h, array());

$h = buildHeaders('', true);     // Leerstring wie null behandeln
check('Leerstring wird verworfen', count($h), 1);

echo "\n$passed gruen, $failed rot\n";
exit($failed === 0 ? 0 : 1);
