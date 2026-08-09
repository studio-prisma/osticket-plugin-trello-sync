<?php
/**
 * Test-Bootstrap.
 *
 * Die Tests muessen in zwei Verzeichnislayouts laufen:
 *
 *   Repo:          osticket-plugin-trello-sync/*.php
 *                  osticket-plugin-trello-sync/tests/*.php
 *
 *   Installation:  include/plugins/trello-sync/*.php
 *                  include/plugins/trello-sync/tests/*.php
 *
 * Beide loesen ueber '../' auf. Der zusaetzliche '../plugin/'-Kandidat stammt
 * aus dem frueheren Repo-Layout, in dem die Quellen in plugin/ lagen, und
 * bleibt als Rueckfallebene stehen. Ein fest verdrahtetes '../plugin/' machte
 * die Tests im ausgelieferten ZIP unbrauchbar.
 */

/**
 * Laedt eine Plugin-Klasse, egal welches Layout vorliegt.
 *
 * @param string $file  Dateiname, z. B. 'class.trello_board.php'
 */
function trello_test_require($file) {
    $candidates = array(
        __DIR__ . '/../plugin/' . $file,  // Repo
        __DIR__ . '/../' . $file,         // Installation
    );

    foreach ($candidates as $path) {
        if (is_file($path)) {
            require_once $path;
            return;
        }
    }

    fwrite(STDERR, "FEHLER: $file in keinem der erwarteten Pfade gefunden:\n");
    foreach ($candidates as $p) {
        fwrite(STDERR, "  - $p\n");
    }
    exit(2);
}
