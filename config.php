<?php
/**
 * Konfiguration des Trello-Sync-Plugins.
 *
 * Enthaelt das Fail-Fast-Gate: ohne API-Secret und ohne https-BASE_URL
 * laesst sich das Plugin nicht speichern und damit nicht scharf schalten.
 * Es gibt keinen Code-Pfad, der ohne Secret verarbeitet.
 *
 * @copyright 2026 studio-prisma
 * @license GPL-2.0-or-later
 */

require_once(INCLUDE_DIR . 'class.plugin.php');
require_once(INCLUDE_DIR . 'class.forms.php');
require_once(INCLUDE_DIR . 'class.dept.php');

define('TRELLO_SYNC_TABLE', TABLE_PREFIX . 'trello_map');
define('TRELLO_BOARD_TABLE', TABLE_PREFIX . 'trello_board');
define('TRELLO_SYNC_ROOT', INCLUDE_DIR . 'plugins/' . basename(__DIR__) . '/');

class TrelloSyncConfig extends PluginConfig {

    /**
     * Baut die Callback-URL aus der KONFIGURIERTEN BASE_URL.
     *
     * Bewusst nicht aus $_SERVER['HTTP_HOST'] / X-Forwarded-Host:
     * beides ist client-setzbar (Host-Header-Poisoning). Die URL geht in die
     * HMAC-Berechnung ein — ein manipulierbarer Host wuerde die Signatur-
     * pruefung angreifbar machen.
     *
     * @return string  Leerstring, wenn keine brauchbare BASE_URL vorliegt
     */
    public static function buildCallbackUrl() {
        $base = '';

        // $cfg ist waehrend des Plugin-Bootstraps NICHT gesetzt:
        // osTicket::start() bootstrapt Plugins, bevor main.inc.php die
        // globalen $ost/$cfg belegt. Ein Zugriff darauf liefert dort einen
        // Leerstring — die Webhook-Registrierung ist deshalb bislang bei
        // jedem Start mit "Helpdesk-URL ist nicht https (leer)"
        // abgebrochen, ohne dass je ein Webhook entstanden waere.
        global $cfg;
        if ($cfg && method_exists($cfg, 'getBaseUrl')) {
            $base = rtrim((string) $cfg->getBaseUrl(), '/');
        }

        // Fallback: direkt aus der Konfigurationstabelle lesen. Funktioniert
        // in jeder Phase, auch vor der Initialisierung von $cfg.
        if ($base === '') {
            $res = db_query("SELECT value FROM " . CONFIG_TABLE
                . " WHERE namespace = 'core' AND `key` = 'helpdesk_url' LIMIT 1", false);
            if ($res && db_num_rows($res)) {
                list($v) = db_fetch_row($res);
                $base = rtrim((string) $v, '/');
            }
        }

        if ($base === '') {
            return '';
        }
        return $base . '/api/trello';
    }

    /**
     * Prueft, ob die Callback-URL ueber https laeuft.
     * Trello sendet Webhooks auch an http, aber dann waeren Payload und
     * Signatur im Klartext unterwegs.
     */
    public static function isSecureCallback($url) {
        return is_string($url) && stripos($url, 'https://') === 0;
    }

    /**
     * Auswahlliste aller vorhandenen Custom-Lists.
     *
     * Bewusst NICHT ueber DynamicList::getSelections(): diese Methode ist fuer
     * die Feldtyp-Auswahl im Formular-Editor gedacht und liefert
     *     array('list-5' => array('Trello Boards', 'SelectionField', 5))
     * — Schluessel ist ein String, der Wert ein Array. Wer daraus id => Name
     * erwartet, speichert 'list-5' und bekommt bei (int) den Wert 0. Die
     * Board-Aufloesung schlaegt dann lautlos immer fehl.
     *
     * Hier wird direkt ueber die Objekte iteriert: Schluessel ist die echte
     * numerische ID, Wert der Listenname.
     */
    public static function getListChoices() {
        $out = array('' => '— bitte waehlen —');
        try {
            if (!class_exists('DynamicList')) {
                return $out;
            }
            foreach (DynamicList::objects() as $list) {
                $id = (int) $list->getId();
                if ($id <= 0) {
                    continue;
                }
                $name = $list->getName();
                $out[$id] = ($name !== null && $name !== '')
                    ? (string) $name
                    : ('Liste #' . $id);
            }
        } catch (Throwable $t) {
            // Die Config-Seite muss auch dann bedienbar bleiben.
        }
        return $out;
    }

    function getOptions() {
        return array(

            'trello_credentials' => new SectionBreakField(array(
                'label' => 'Trello-Zugangsdaten',
                'hint'  => 'API-Key und Token unter https://trello.com/power-ups/admin erzeugen. '
                         . 'Das API-Secret wird fuer die Signaturpruefung eingehender Webhooks benoetigt.',
            )),

            'trello_api_key' => new TextboxField(array(
                'id'       => 'trello_api_key',
                'label'    => 'Trello API Key',
                'required' => true,
                'configuration' => array('length' => 0, 'size' => 60),
            )),

            'trello_api_token' => new TextboxField(array(
                'id'       => 'trello_api_token',
                'label'    => 'Trello API Token',
                'required' => true,
                'hint'     => 'Scope read,write. Der Token bestimmt, als welcher Trello-Nutzer das Plugin schreibt.',
                'configuration' => array('length' => 0, 'size' => 60),
            )),

            // OFFEN: Maskierung dieses Feldes gegen die installierte osTicket-Version
            // verifizieren. osTicket bietet je nach Version kein dediziertes
            // Password-Widget fuer Plugin-Configs. Bis dahin gilt: Wert ist in der
            // Admin-UI sichtbar, aber nur fuer Admins mit Plugin-Zugriff.
            'trello_api_secret' => new TextboxField(array(
                'id'       => 'trello_api_secret',
                'label'    => 'Trello API Secret',
                'required' => true,
                'hint'     => 'PFLICHT. Ohne Secret verweigert das Plugin die Aktivierung. '
                            . 'Zu finden neben dem API-Key in der Power-Up-Verwaltung.',
                'configuration' => array('length' => 0, 'size' => 60),
            )),

            'trello_bot_member_id' => new TextboxField(array(
                'id'       => 'trello_bot_member_id',
                'label'    => 'Trello Member-ID des Tokens',
                'required' => true,
                'hint'     => 'Loop-Schutz. Aktionen dieses Mitglieds werden bei eingehenden Webhooks '
                            . 'verworfen. Ermitteln: https://api.trello.com/1/members/me?key=KEY&token=TOKEN -> Feld "id".',
                'configuration' => array('length' => 32, 'size' => 40),
            )),

            'trello_target' => new SectionBreakField(array(
                'label' => 'Board-Registry',
                'hint'  => 'Ein Board je Kunde. Die Boards werden als Custom-List gepflegt '
                         . '(Verwalten -> Listen). Jeder Listeneintrag ist ein Kunde und traegt '
                         . 'die Properties board_id, inbox_list_id, default_dept_id und '
                         . 'default_user_email. Dieselbe Liste wird als Feld ins Ticket-Formular '
                         . 'aufgenommen und steuert dort, ob und wohin ein Ticket synchronisiert wird.',
            )),

            // Bewusst NICHT 'required': solange keine Custom-List existiert,
            // gaebe es keine waehlbare Option und die Konfiguration liesse
            // sich ueberhaupt nicht speichern. Die Pruefung passiert in
            // pre_save() mit einer Meldung, die auch ankommt.
            'trello_board_list_id' => new ChoiceField(array(
                'id'       => 'trello_board_list_id',
                'label'    => 'Board-Liste',
                'required' => false,
                'hint'     => 'Die Custom-List mit den Kunden-Boards. Erscheint hier erst, '
                            . 'wenn sie unter Verwalten -> Listen angelegt wurde. '
                            . 'Ohne sie laeuft kein Sync — Zugangsdaten lassen sich aber vorab speichern.',
                'choices'  => self::getListChoices(),
                'configuration' => array('multiselect' => false),
            )),

            'trello_board_field' => new TextboxField(array(
                'id'       => 'trello_board_field',
                'label'    => 'Feldname im Ticket-Formular',
                'required' => true,
                'hint'     => 'Variablenname des Listenfeldes im Ticket-Formular. '
                            . 'Leeres Feld im Ticket = dieses Ticket wird nicht synchronisiert.',
                'default'  => 'trello_board',
                'configuration' => array('length' => 64, 'size' => 30),
            )),
        );
    }

    /**
     * Fail-Fast-Gate (Spec AC-6).
     *
     * Laeuft vor dem Speichern. Schlaegt eine Bedingung fehl, wird die
     * Konfiguration nicht uebernommen und es wird kein Webhook registriert.
     */
    function pre_save(&$config, &$errors) {
        global $msg;

        $ok = true;

        // Benoetigte PHP-Erweiterungen. osTicket selbst setzt mbstring voraus,
        // aber darauf zu bauen ohne es zu pruefen waere eine stille Annahme:
        // ohne mbstring wuerde die Kuerzung langer Texte einen Fatal Error
        // werfen — mitten in einem Ticket-Vorgang.
        // WICHTIG: Fehler NIE nach $errors['err'] schreiben.
        // scp/plugins.php ueberschreibt diesen Schluessel nach dem Aufruf mit
        // "Unable to update plugin instance." — die Begruendung ginge verloren.
        // Nur feldbezogene Schluessel erreichen den Nutzer.
        foreach (array('curl' => 'php-curl', 'mbstring' => 'php-mbstring',
                       'hash' => 'php-hash') as $ext => $pkg) {
            if (!extension_loaded($ext)) {
                $errors['trello_api_key'] = 'PHP-Erweiterung "' . $ext . '" fehlt (' . $pkg
                    . '). Das Plugin kann ohne sie nicht betrieben werden.';
                $ok = false;
            }
        }

        $secret = isset($config['trello_api_secret']) ? trim($config['trello_api_secret']) : '';
        if ($secret === '') {
            $errors['trello_api_secret'] =
                'Ohne API-Secret kann die Signatur eingehender Webhooks nicht geprueft werden. '
              . 'Das Plugin verweigert den Betrieb.';
            $ok = false;
        }

        $memberId = isset($config['trello_bot_member_id']) ? trim($config['trello_bot_member_id']) : '';
        if ($memberId === '') {
            $errors['trello_bot_member_id'] =
                'Ohne Member-ID greift der Loop-Schutz nicht. Pflichtfeld.';
            $ok = false;
        }

        // Board-Liste ist fuer den Betrieb noetig, blockiert aber bewusst
        // NICHT das Speichern: sonst koennte man die Zugangsdaten nicht
        // hinterlegen, bevor die Liste existiert.
        $listId = isset($config['trello_board_list_id']) ? (int) $config['trello_board_list_id'] : 0;
        if ($listId <= 0) {
            global $msg;
            $msg = 'Gespeichert — aber es ist noch keine Board-Liste gewaehlt. '
                 . 'Bis dahin synchronisiert das Plugin nichts. '
                 . 'Anlegen unter Verwalten -> Listen, danach hier auswaehlen.';
        }

        $fieldName = isset($config['trello_board_field']) ? trim($config['trello_board_field']) : '';
        if ($fieldName === '' || !preg_match('/^[a-z0-9_]{1,64}$/i', $fieldName)) {
            $errors['trello_board_field'] =
                'Feldname fehlt oder enthaelt unzulaessige Zeichen (erlaubt: a-z, 0-9, Unterstrich).';
            $ok = false;
        }

        $callback = self::buildCallbackUrl();
        if ($callback === '') {
            $errors['trello_api_secret'] =
                'Die osTicket-Basis-URL ist nicht konfiguriert. '
              . 'Admin-Panel -> Einstellungen -> System -> Helpdesk-URL.';
            $ok = false;
        }
        elseif (!self::isSecureCallback($callback)) {
            $errors['trello_api_secret'] =
                'Die Helpdesk-URL muss https sein (aktuell: ' . Format::htmlchars($callback) . '). '
              . 'Ueber http waeren Webhook-Payload und Signatur im Klartext unterwegs.';
            $ok = false;
        }

        if ($ok && !$errors && $listId > 0) {
            $msg = 'Konfiguration gespeichert. Callback-URL: ' . Format::htmlchars($callback);
        }

        return $ok;
    }
}
