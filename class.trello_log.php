<?php
/**
 * Logging, das auch waehrend des Plugin-Bootstraps ankommt.
 *
 * Das Problem: osTicket::start() ruft $ost->plugins->bootstrap() auf einer
 * LOKALEN Variablen auf und gibt $ost erst danach zurueck. main.inc.php
 * belegt die globale $ost also erst, NACHDEM alle Plugins gebootstrapt sind.
 *
 * Waehrend bootstrap() ist `global $ost` folglich null. Jeder Logger, der
 * `if ($ost)` prueft, verwirft in dieser Phase stillschweigend alles —
 * genau die Phase, in der Konfigurations- und Registrierungsfehler auftreten.
 *
 * Deshalb hier zweigleisig: osTicket-Syslog wenn verfuegbar, und IMMER
 * zusaetzlich das PHP-Error-Log. Eine Meldung darf nie verloren gehen.
 *
 * @copyright 2026 studio-prisma
 * @license GPL-2.0-or-later
 */
class TrelloLog {

    const PREFIX = '[trello-sync] ';

    public static function error($msg) {
        self::write('error', $msg);
    }

    public static function warn($msg) {
        self::write('warn', $msg);
    }

    public static function info($msg) {
        self::write('info', $msg);
    }

    private static function write($level, $msg) {
        $msg = (string) $msg;

        // 1. Immer ins PHP-Error-Log. Funktioniert in jeder Phase,
        //    auch vor der Initialisierung von osTicket.
        error_log(self::PREFIX . strtoupper($level) . ': ' . $msg);

        // 2. Zusaetzlich ins osTicket-Syslog, sobald das moeglich ist.
        //    Waehrend des Bootstraps ist $ost null — dann bleibt es beim
        //    Error-Log, statt die Meldung zu verlieren.
        global $ost;
        if (!$ost) {
            return;
        }

        try {
            switch ($level) {
                case 'error':
                    $ost->logError('Trello Sync', $msg, false);
                    break;
                case 'warn':
                    $ost->logWarning('Trello Sync', $msg, false);
                    break;
                default:
                    // force=true, damit die Meldung auch bei niedrigem
                    // Log-Level ankommt. Ohne das ist Diagnose Gluecksache.
                    $ost->logDebug('Trello Sync', $msg, true);
            }
        } catch (Throwable $t) {
            error_log(self::PREFIX . 'Syslog-Schreibfehler: ' . $t->getMessage());
        }
    }
}
