# osTicket ↔ Trello Sync

Two-way synchronisation between osTicket tickets and a Trello board.

Ticket creation, status and comments sync in both directions. One board per customer, sync is opt-in per ticket, and a CLI tool reconciles what already exists. No Composer dependencies, no `vendor/`, no build step.

Current version: see `plugin.php` and [`CHANGELOG.md`](CHANGELOG.md). Deutsche Fassung: [`README.de.md`](README.de.md).

Verified against **PHP 8.3.33**: `php -l` clean, **80 unit tests green**, no deprecations under `E_ALL`.

**What is still open:** a run against a live osTicket instance. The osTicket-coupled classes are not unit-tested — only the pure logic is.

---

## Requirements

- osTicket **1.18.x**
- PHP **8.1+** (tested on 8.3.33) with `curl`, `mbstring` and `hash`. If one is missing, the plugin refuses to save its configuration.
- Helpdesk URL over **HTTPS**. Without it the plugin refuses to run.
- A Trello account with API key, token and **API secret**

---

## Installation

1. Copy the `trello-sync/` folder into `/include/plugins/` on the osTicket server.
2. Check the files before activating:
   ```bash
   cd /path/to/osticket/include/plugins/trello-sync
   find . -name "*.php" -exec php -l {} \;
   ```
   Every file must report `No syntax errors detected`. **Stop here if one does not.**
3. Admin Panel → Manage → Plugins → **Add New Plugin** → select "Trello Sync".
4. **Install** the plugin, then configure it, then **enable** it. In that order.

Step-by-step with abort points and a smoke test: [`docs/INSTALL.md`](docs/INSTALL.md).

---

## Configuration

| Field | Where to get it |
|---|---|
| API Key | https://trello.com/power-ups/admin → create a Power-Up → API Key |
| API Token | Same page, via the token link, scope `read,write` |
| **API Secret** | Next to the API key. **Required** — no secret means no signature verification, which means the plugin will not activate. |
| Member ID of the token | `https://api.trello.com/1/members/me?key=KEY&token=TOKEN` → field `id` |
| Board list | The custom list holding the customer boards (see below) |
| Ticket form field name | Variable name of the list field, default `trello_board` |

### Board registry — one board per customer

Boards are **not** kept in the plugin config. They live in an osTicket custom list, and that same list is the dropdown in the ticket form. One place to maintain instead of two.

**1. Create the list.** Admin Panel → Manage → Lists → **Add New Custom List**, e.g. "Trello Boards".

**2. Define properties.** Open **Properties** on the list and add four fields. The *variable names* must match exactly:

| Variable name | Type | Content |
|---|---|---|
| `board_id` | Short Answer | Trello board ID, 24 hex characters |
| `inbox_list_id` | Short Answer | Target list for new cards, 24 hex |
| `default_dept_id` | Short Answer | Department ID for tickets from this board |
| `default_user_email` | Short Answer | Sender for tickets from this board |

To find board and list IDs, open the board URL with `.json` appended: `id` at the top level is the board, the lists are under `lists`.

**3. One entry per customer**, with all four properties filled. The entry name is the customer name and appears in the ticket dropdown.

**4. Add the field to the ticket form.** Admin Panel → Manage → Forms → Ticket Details → add a field of this list type, variable name `trello_board`.

Incomplete entries are **skipped**, never half-processed. The reason goes to the osTicket log.

### Controlling the sync

The board field on the ticket decides everything:

| Field value | Behaviour |
|---|---|
| empty | **no sync** — this is the default |
| Board A | card on board A |
| changed A → B | the same card **moves** to B, it is not duplicated |
| set → empty | sync stops, **the card stays** |

Nothing is ever deleted in Trello.

The callback URL is derived from the helpdesk URL configured in osTicket: `https://<helpdesk>/api/trello`. It is **never** taken from the Host header — the URL is part of the HMAC input, so a client-controlled host would be an attack vector.

---

## Security

Incoming webhooks are verified with **HMAC-SHA1**:

```
base64( HMAC-SHA1( requestBody + callbackURL, apiSecret ) )
```

The comparison uses `hash_equals()` and is therefore timing-safe.

**Deliberately not implemented:** IP allowlisting and any evaluation of `X-Real-IP` / `X-Forwarded-For`. The reference plugin (`kyleladd/OSTicket-Trello-Plugin`) checked only `$_SERVER['HTTP_X_REAL_IP']` against four hard-coded AWS addresses from 2016. That header is freely set by the client, which left the endpoint effectively unauthenticated.

Further measures:

- **Fail fast.** Without a secret or without HTTPS the configuration cannot be saved. There is no code path that processes anything without a secret.
- **Order matters.** Read the raw body → verify the signature → *only then* decode JSON.
- **Sanitised errors.** Every rejection returns `401` with an identical body. Reasons go to the osTicket log only.
- **Payload limit** of 256 KB.
- **Board binding.** Events from other boards are discarded.
- **Card resolution** goes through the mapping table exclusively. An ID from the payload is never used directly as a ticket ID.

Full threat model with In Scope / Out of Scope: [`SECURITY.md`](SECURITY.md).

---

## Loop protection

Two layers, so a change cannot bounce endlessly between the systems:

1. **Author check (primary).** Every Trello webhook carries `action.memberCreator.id`. If it matches the configured member ID, the write came from us and is discarded. Deterministic, no string matching.
2. **Payload hash (secondary).** `ost_trello_map` stores `last_sync_hash`. If the value to write is identical to the last synchronised one, nothing is written.

---

## Public vs. internal

**Internal notes never leave osTicket.**

Only ThreadEntry types `M` (original message) and `R` (public reply) are synchronised. Type `N` (internal note) is never transmitted. The check is an allowlist, so unknown types are not transmitted either.

The filter runs **before** any Trello request is built. For an internal note, not a single byte leaves the server. `test_rules.php` covers this with 17 cases, including case variations, type confusion and unknown types.

---

## Reconciliation

For a one-off alignment of existing tickets and cards:

```bash
php tools/reconcile.php                      # dry run — writes nothing
php tools/reconcile.php --apply --limit=50   # executes
php tools/reconcile.php --board=<board_id>   # single board
```

---

## Files

| File | Purpose |
|---|---|
| `plugin.php` | Manifest |
| `config.php` | Configuration fields + fail-fast gate |
| `trello_sync.php` | Main class, signal wiring, webhook registration |
| `api.trello_sync.php` | Webhook endpoint, security core |
| `class.trello_signature.php` | HMAC verification, dependency-free and unit-testable |
| `class.trello_map.php` | Ticket ↔ card mapping, DB schema |
| `class.trello_http.php` | cURL client with backoff on HTTP 429 |
| `class.trello_board.php` | Board value object, pure validation, unit-testable |
| `class.trello_registry.php` | Reads the boards from the custom list |
| `class.trello_boardstore.php` | Webhook IDs per board |
| `tools/` | CLI tools: diagnosis, auth probe, reconciliation |

---

## Tests

```bash
php tests/test_signature.php   # HMAC verification
php tests/test_board.php       # board validation
php tests/test_rules.php       # sync rules, focus on the note filter
php tests/test_headers.php     # header handling
```

Exit code 0 means green. `test_signature.php` covers the happy path, every failure path, and two regression tests against the gaps in the reference plugin.

Diagnosis when something misbehaves — prints the complete state:

```bash
php tools/diagnose.php
```

Structural integrity check (does not replace `php -l`, but catches silently truncated files):

```bash
python3 tools/verify_structure.py *.php tests/*.php
```

---

## Not included

Attachments · internal notes · due dates · labels · member assignments · checklists · multiple boards per ticket.

---

## Contributing

See [`CONTRIBUTING.md`](CONTRIBUTING.md). Security reports go through [private vulnerability reporting](https://github.com/studio-prisma/osticket-plugin-trello-sync/security/advisories/new), not public issues.

---

## Licence

GPL-2.0-or-later, compatible with the osTicket core. See [`LICENSE`](LICENSE).

This is an independent implementation. `kyleladd/OSTicket-Trello-Plugin` served as a behavioural reference but ships **no LICENSE file**, and no code was taken from it.
