# Security Policy

## Supported versions

| Version | Supported |
|---|---|
| 0.5.x | ✅ |
| < 0.5 | ❌ |

While the major version is `0`, only the latest minor receives fixes. There are no backports.

## Reporting a vulnerability

Use GitHub's [private vulnerability reporting](https://github.com/studio-prisma/osticket-plugin-trello-sync/security/advisories/new). Do not open a public issue.

Please include the plugin version, the osTicket and PHP version, and a minimal reproduction. A first response follows within 7 days.

## Threat model in one paragraph

The plugin exposes exactly one endpoint that accepts unauthenticated internet traffic: `api.trello_sync.php`, the Trello webhook receiver. Everything else runs inside osTicket's own authentication. The security of this plugin therefore rests almost entirely on whether that one endpoint can be made to act on a request Trello did not send.

## In scope

A report on any of the following is a finding:

- **Signature bypass.** Any way to make `api.trello_sync.php` act on a request without a valid `base64(HMAC-SHA1(body + callbackURL, apiSecret))`, including timing attacks against the comparison, encoding tricks, or a path where the body is parsed before verification.
- **Verification-order violation.** Any code path that decodes, logs, or otherwise interprets the request body before the signature check has passed.
- **Board-binding bypass.** Getting the plugin to act on an event that belongs to a board other than the one mapped to the ticket.
- **Card/ticket confusion.** Any path where an identifier taken from the payload is used as a ticket ID instead of being resolved through the mapping table.
- **Internal notes leaving osTicket.** ThreadEntry type `N` must never reach Trello. Any input that gets a note synchronised — including type spoofing or unknown types passing the allowlist — is a high-severity finding.
- **Credential exposure.** API key, token or secret appearing in logs, error output, HTTP responses, or the built ZIP.
- **Injection** into the database layer or into outbound Trello requests.
- **Denial of service** through the webhook endpoint beyond the documented 256 KB payload limit.

## Out of scope

These are documented design decisions, not defects. A report on them will be closed with a link here:

- **No IP allowlist, and `X-Real-IP` / `X-Forwarded-For` are ignored.** Both headers are client-settable. The reference implementation this plugin replaces checked only `X-Real-IP` against four hard-coded 2016-era AWS addresses, which left the endpoint effectively unauthenticated. HMAC verification replaces that entirely — adding an allowlist on top would suggest a guarantee it cannot give.
- **Identical `401` for every rejection.** Reasons go to the osTicket log only. Distinguishable errors would turn the endpoint into an oracle. Debugging happens server-side with `tools/diagnose.php`.
- **Trello is a US provider.** Anything synchronised to a board leaves the EU. That is a deployment decision for the operator, including the data-processing agreement and the record of processing activities. The plugin makes the boundary controllable (opt-in per ticket, no internal notes) but cannot make it disappear.
- **Operator-supplied board configuration is trusted.** The board registry is maintained by osTicket administrators. A malicious administrator is not part of the threat model.
- **`tools/*.php` are CLI scripts.** They are not routed over HTTP by osTicket and expect a shell with filesystem access. Reachability of these files through a misconfigured web server is a server configuration issue, not a plugin issue — but tell us anyway if the plugin's own instructions caused it.
- **No transport security of its own.** The plugin refuses to store a configuration without HTTPS, but the certificate and TLS setup belong to the web server.

## What a good report looks like

The two rejection paths that matter most are easy to test. Both must return `401` with an identical body:

```bash
# No signature at all
curl -i -X POST https://<helpdesk>/api/trello \
     -H 'Content-Type: application/json' \
     -d '{"action":{"type":"commentCard"}}'

# Forged X-Real-IP must change nothing
curl -i -X POST https://<helpdesk>/api/trello \
     -H 'X-Real-IP: 203.0.113.10' \
     -H 'Content-Type: application/json' \
     -d '{"action":{"type":"commentCard"}}'
```

If either returns anything other than `401`, that is a finding.
