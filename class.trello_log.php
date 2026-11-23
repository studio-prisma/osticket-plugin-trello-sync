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

    /** Mindestabstand zwischen zwei gedrosselten Syslog-Eintraegen */
    const THROTTLE_SECONDS = 900;

    /**
     * Warnung fuer Ereignisse, die jeder von aussen beliebig oft ausloesen
     * kann (unsignierte Webhooks, Riesen-Payloads).
     *
     * Das PHP-Error-Log bekommt jede Meldung, das osTicket-Syslog hoechstens
     * eine je $key und THROTTLE_SECONDS. Ohne Drossel schreibt jeder
     * anonyme POST eine Zeile in die osTicket-Datenbank.
     *
     * Der Zeitstempel liegt in osTickets Config-Tabelle (Namespace
     * THROTTLE_NAMESPACE), nicht als Datei: eine Datei mit festem Namen im
     * gemeinsamen Temp-Verzeichnis liesse sich auf Shared Hosting per Symlink
     * auf eine fremde Datei umbiegen. Kosten: ein SELECT je Aufruf, hoechstens
     * ein UPDATE je THROTTLE_SECONDS. Ist der Speicher nicht nutzbar, bleibt
     * es beim Error-Log: lieber eine Syslog-Zeile verlieren als die Drossel.
     *
     * @param string $key  Kennung der Meldungsart, nur [a-z0-9-]
     * @return bool        true, wenn die Meldung ins Syslog ging
     */
    public static function warnThrottled($key, $msg, $now = null) {
        $msg = (string) $msg;
        error_log(self::PREFIX . 'WARN: ' . $msg);

        if (!is_string($key) || !preg_match('/^[a-z0-9-]{1,32}$/', $key)) {
            return false;
        }
        $now = $now === null ? time() : (int) $now;

        try {
            $store = self::throttleStore();
            if (!$store) {
                return false;
            }
            $last = (string) $store->get($key);
            if (ctype_digit($last) && $now - (int) $last < self::THROTTLE_SECONDS) {
                return false;
            }
            if ($store->set($key, (string) $now) === null) {
                return false;
            }
        } catch (Throwable $t) {
            error_log(self::PREFIX . 'Drossel nicht nutzbar: ' . $t->getMessage());
            return false;
        }

        global $ost;
        if (!$ost) {
            return false;
        }
        try {
            $ost->logWarning('Trello Sync', $msg
                . ' (weitere Meldungen dieser Art fuer '
                . (self::THROTTLE_SECONDS / 60) . ' Min. nur im PHP-Error-Log)', false);
        } catch (Throwable $t) {
            error_log(self::PREFIX . 'Syslog-Schreibfehler: ' . $t->getMessage());
            return false;
        }
        return true;
    }

    /** Config-Namespace der Drossel-Zeitstempel */
    const THROTTLE_NAMESPACE = 'plugin.trello-sync.throttle';

    /**
     * Speicher mit get($key) / set($key, $value). Tests setzen hier einen
     * Ersatz; im Betrieb bleibt es null und osTickets Config wird genutzt.
     *
     * @var object|null
     */
    public static $throttleStore = null;

    private static function throttleStore() {
        if (self::$throttleStore !== null) {
            return self::$throttleStore;
        }
        if (!class_exists('Config')) {
            return null;
        }
        return new Config(self::THROTTLE_NAMESPACE);
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
