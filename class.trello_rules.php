<?php
/**
 * Reine Entscheidungslogik der Synchronisation.
 *
 * Bewusst OHNE osTicket-Abhaengigkeiten. Alles hier ist eine reine Funktion
 * und damit isoliert testbar — insbesondere der Notizfilter, der die
 * datenschutzrelevanteste Entscheidung des Plugins trifft.
 *
 * @copyright 2026 studio-prisma
 * @license GPL-2.0-or-later
 */
class TrelloRules {

    /** osTicket-ThreadEntry-Typen (verifiziert gegen 1.18.4) */
    const ENTRY_MESSAGE  = 'M';  // Ursprungsnachricht des Melders
    const ENTRY_RESPONSE = 'R';  // oeffentliche Antwort eines Agenten
    const ENTRY_NOTE     = 'N';  // INTERNE Notiz — darf osTicket nie verlassen

    /** Laengenbegrenzungen gegen Riesen-Payloads */
    const MAX_TITLE = 400;
    /**
     * Kartenbeschreibung. Bewusst klein gehalten: ein 14-KB-Request hat
     * dazu gefuehrt, dass Trello die Formulardaten nicht mehr vollstaendig
     * ausgewertet hat. Die Karte ist ein Arbeitsobjekt, nicht das Archiv —
     * der vollstaendige Verlauf steht im Ticket.
     */
    const MAX_BODY  = 4000;

    /**
     * Darf dieser Thread-Eintrag nach Trello?
     *
     * POSITIVLISTE. Nur ausdruecklich freigegebene Typen werden uebertragen.
     * Ein unbekannter oder neuer Typ fuehrt zu false — nicht zu einem Leck.
     *
     * Das ist die Umsetzung von AC-7: interne Notizen (Typ N) verlassen
     * osTicket unter keinen Umstaenden.
     */
    public static function isSyncableEntryType($type) {
        if (!is_string($type)) {
            return false;
        }
        return $type === self::ENTRY_MESSAGE
            || $type === self::ENTRY_RESPONSE;
    }

    /**
     * Explizite Gegenprobe — dient der Lesbarkeit an der Aufrufstelle
     * und macht den Notizfilter im Code sichtbar.
     */
    public static function isInternalNote($type) {
        return $type === self::ENTRY_NOTE;
    }

    /**
     * Normalisiert einen Namen fuer den Abgleich Liste <-> Status.
     * Gross-/Kleinschreibung und Randleerzeichen sind unerheblich.
     */
    public static function normalizeName($name) {
        if (!is_string($name)) {
            return '';
        }
        return mb_strtolower(trim($name));
    }

    /**
     * Ordnet einen Trello-Listennamen einem osTicket-Status zu.
     *
     * @param string $listName
     * @param array  $statuses  status_id => status_name
     * @return int|null  Status-ID oder null, wenn es keine Entsprechung gibt
     */
    public static function listNameToStatusId($listName, array $statuses) {
        $needle = self::normalizeName($listName);
        if ($needle === '') {
            return null;
        }
        foreach ($statuses as $id => $name) {
            if (self::normalizeName($name) === $needle) {
                return (int) $id;
            }
        }
        return null;
    }

    /**
     * Ordnet einen osTicket-Statusnamen einer Trello-Liste zu.
     *
     * @param string $statusName
     * @param array  $lists  Liste von array('id' => ..., 'name' => ...)
     * @return string|null  Trello-List-ID
     */
    public static function statusNameToListId($statusName, array $lists) {
        $needle = self::normalizeName($statusName);
        if ($needle === '') {
            return null;
        }
        foreach ($lists as $list) {
            if (!is_array($list) || !isset($list['name'], $list['id'])) {
                continue;
            }
            if (self::normalizeName($list['name']) === $needle) {
                return (string) $list['id'];
            }
        }
        return null;
    }

    /**
     * Kartentitel aus einem Ticket.
     *
     * Rein kosmetisch — die Verknuepfung laeuft ueber die Mapping-Tabelle.
     * Ein Umbenennen der Karte in Trello hat deshalb keine Wirkung auf die
     * Zuordnung.
     */
    public static function buildCardTitle($ticketNumber, $subject) {
        $number  = trim((string) $ticketNumber);
        $subject = trim((string) $subject);
        if ($subject === '') {
            $subject = '(ohne Betreff)';
        }
        $title = ($number !== '' ? '#' . $number . ' ' : '') . $subject;
        return self::truncate($title, self::MAX_TITLE);
    }

    /**
     * Sieht dieser Text nach HTML aus?
     *
     * Bewusst konservativ: ein einzelnes "<" in Fliesstext (etwa "a < b")
     * soll nicht als HTML durchgehen.
     */
    public static function looksLikeHtml($text) {
        if (!is_string($text) || $text === '') {
            return false;
        }
        return preg_match('/<(?:[a-z][a-z0-9]*)\b[^>]*>/i', $text) === 1;
    }

    /**
     * Raeumt Text fuer die Anzeige auf einer Trello-Karte auf.
     *
     * Trello-Karten rendern Markdown, kein HTML. Ohne Aufbereitung landet
     * bei einer HTML-Mail (Plesk-, WordPress-Benachrichtigungen) das rohe
     * Tabellen-Markup in der Beschreibung.
     *
     * Reine Funktion — die eigentliche HTML-Umwandlung macht osTicket per
     * Format::html2text(), hier wird nur nachbereitet.
     */
    public static function tidyText($text) {
        $text = (string) $text;

        // Zeilenenden vereinheitlichen
        $text = str_replace(array("\r\n", "\r"), "\n", $text);

        // Nicht umbrechende Leerzeichen und Zero-Width-Zeichen entfernen
        $text = str_replace(array("\xC2\xA0", "\xE2\x80\x8B", "\xEF\xBB\xBF"), ' ', $text);

        // Zeilenweise trimmen, damit Einrueckungen aus Tabellen verschwinden
        $lines = array_map('trim', explode("\n", $text));

        // Mehrfache Leerzeichen innerhalb einer Zeile zusammenfassen
        $lines = array_map(function ($l) {
            return preg_replace('/[ \t]{2,}/', ' ', $l);
        }, $lines);

        $text = implode("\n", $lines);

        // Mehr als eine Leerzeile bringt auf einer Karte nichts
        $text = preg_replace("/\n{3,}/", "\n\n", $text);

        return trim($text);
    }

    /**
     * Kuerzt auf eine Maximallaenge, ohne Multibyte-Zeichen zu zerschneiden.
     */
    public static function truncate($text, $max) {
        $text = (string) $text;
        $max  = (int) $max;
        if ($max <= 0) {
            return '';
        }
        if (mb_strlen($text) <= $max) {
            return $text;
        }
        return mb_substr($text, 0, $max - 1) . "\u{2026}";
    }

    /**
     * Hinweiszeile, die an aus Trello erzeugte Ticketinhalte gehaengt wird,
     * damit im Ticket sichtbar ist, woher der Inhalt stammt.
     */
    public static function originNote($cardShortLink) {
        if (!is_string($cardShortLink) || !preg_match('/^[A-Za-z0-9]{4,16}$/', $cardShortLink)) {
            return '';
        }
        return "\n\n---\nQuelle: https://trello.com/c/" . $cardShortLink;
    }

    /**
     * Entscheidet, was bei einem Wechsel des Board-Feldes zu tun ist.
     *
     * @param string|null $oldBoardId  bisher verknuepftes Board (aus dem Mapping)
     * @param string|null $newBoardId  im Ticket gewaehltes Board
     * @return string  'none' | 'create' | 'move' | 'deactivate'
     */
    public static function boardTransition($oldBoardId, $newBoardId) {
        $old = is_string($oldBoardId) && $oldBoardId !== '' ? $oldBoardId : null;
        $new = is_string($newBoardId) && $newBoardId !== '' ? $newBoardId : null;

        if ($old === null && $new === null) {
            return 'none';
        }
        if ($old === null && $new !== null) {
            return 'create';
        }
        if ($old !== null && $new === null) {
            // Karte bleibt bestehen, Mapping wird stillgelegt.
            return 'deactivate';
        }
        if ($old !== $new) {
            return 'move';
        }
        return 'none';
    }
}
