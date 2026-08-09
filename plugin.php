<?php
/**
 * osTicket <-> Trello Sync
 *
 * Bidirektionale Synchronisation zwischen osTicket-Tickets und Trello-Karten.
 *
 * Sicherheit: eingehende Webhooks werden per HMAC-SHA1 gegen das Trello-API-Secret
 * verifiziert. Es gibt bewusst KEINE IP-Allowlist und keine Auswertung von
 * X-Real-IP / X-Forwarded-For — beides ist client-setzbar und damit wertlos.
 *
 * @author  studio-prisma
 * @copyright 2026 studio-prisma
 * @license GPL-2.0-or-later (kompatibel zu osTicket Core)
 */

return array(
    'id'          => 'studio-prisma:trello-sync',
    'version'     => '0.5.9',
    'name'        => 'Trello Sync',
    'author'      => 'studio-prisma',
    'description' => 'Bidirektionale Synchronisation zwischen osTicket und Trello. '
                   . 'Ein Board je Kunde, Sync opt-in pro Ticket, HMAC-verifizierte Webhooks, '
                   . 'persistentes Mapping, strikte Trennung oeffentlich/intern. '
                   . 'Enthaelt CLI-Werkzeuge fuer Diagnose und Bestandsabgleich.',
    'url'         => 'https://github.com/studio-prisma/osticket-plugin-trello-sync',
    'plugin'      => 'trello_sync.php:TrelloSyncPlugin',
);
