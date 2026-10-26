# Installation: osTicket ↔ Trello Sync for osTicket 1.18.x

The order below is binding. Every step has an abort point — if a check fails, stop there rather than continuing and debugging later on a live helpdesk.

Read [§0 Lockout risks](#0-lockout-risks-read-this-first) before you touch anything.

---

## 0. Lockout risks, read this first

This plugin cannot lock you out of osTicket. It adds no login gate and no field validation on the agent side. The realistic damage is different:

| Risk | What happens | Prevention |
|---|---|---|
| **Customer data reaches Trello** | Ticket subject and public replies are copied to a US-hosted board. This is the whole point of the plugin, but it is a data transfer you must be allowed to make. | Clear it before go-live: data-processing agreement with Atlassian, entry in the record of processing activities. See [§9](#9-before-go-live). |
| **Wrong board mapped** | A customer sees another customer's ticket on a shared board. | One board per customer, verified in [§5](#5-board-registry). Never a shared board. |
| **Internal notes leaking** | They do not. Type `N` is filtered before any request is built — but verify it yourself in [§8](#8-security-gate). |

The kill switch is one click: **Admin Panel → Manage → Plugins → Trello Sync → disable**. Tickets and cards stay as they are, nothing is deleted. Sync simply stops.

---

## 1. Check prerequisites

On the server:

```bash
php -v                                    # 8.1 or newer
php -m | grep -E '^(curl|mbstring|hash)$' # all three must appear
```

In osTicket: Admin Panel → Settings → System → the **Helpdesk URL** must start with `https://`.

**Abort if** PHP is older than 8.1, an extension is missing, or the helpdesk URL is plain HTTP. The plugin refuses to save its configuration in all three cases, so you would only find out later and wonder why.

---

## 2. Create the Trello credentials

1. Open https://trello.com/power-ups/admin and create a Power-Up. Any name.
2. Note the **API key**.
3. On the same page, next to the key, note the **API secret**. It is easy to miss and it is required — without it there is no signature verification and the plugin will not activate.
4. Use the token link on that page to generate a **token** with scope `read,write`.
5. Get the member ID belonging to that token:
   ```bash
   curl -s "https://api.trello.com/1/members/me?key=KEY&token=TOKEN" | grep -o '"id":"[^"]*"' | head -1
   ```

**Abort if** you only have key and token. The secret is not optional.

**Whose token is it?** If the token belongs to your personal account, every card and comment the plugin writes appears under your name. A dedicated bot account is cleaner. `tools/probe-auth.php` tells you afterwards which account the token actually belongs to.

---

## 3. Upload the files

Build the package from a checkout, or take the ZIP from the release page:

```bash
./tools/build-zip.sh
# → dist/trello-sync-<version>.zip
```

Unpack it so that the folder lands at:

```
/path/to/osticket/include/plugins/trello-sync/
```

Match owner and permissions to the neighbouring plugin folders:

```bash
cd /path/to/osticket/include/plugins
ls -la                                    # note owner and mode of a sibling
chown -R <web-user>:<web-group> trello-sync
find trello-sync -type d -exec chmod 755 {} \;
find trello-sync -type f -exec chmod 644 {} \;
```

Then the syntax check, on the server, not on your laptop:

```bash
cd trello-sync
find . -name "*.php" -exec php -l {} \;
```

**Abort if** any file reports something other than `No syntax errors detected`. A partially uploaded file is the most common cause — re-upload rather than investigate.

---

## 4. Install the plugin

Admin Panel → Manage → Plugins → **Add New Plugin** → "Trello Sync" → **Install**.

Do **not** enable it yet. Configure first, enable last. An enabled plugin without a board registry writes nothing, but it does log on every ticket event, and that noise makes the next steps harder to read.

Fill in the configuration:

| Field | Value |
|---|---|
| API Key | from §2 |
| API Token | from §2 |
| API Secret | from §2 |
| Member ID | from §2 |
| Board list | the custom list from §5 — create it first, then come back |
| Ticket form field name | `trello_board` unless you have a reason |

**Abort if** the configuration refuses to save. The message names the reason: missing extension, missing secret, or a helpdesk URL that is not HTTPS. All three are §1 and §2 problems, not configuration problems.

---

## 5. Board registry

Boards live in an osTicket custom list, not in the plugin config. The same list is the dropdown in the ticket form.

**5.1** Admin Panel → Manage → Lists → **Add New Custom List**, e.g. "Trello Boards".

**5.2** Open **Properties** and add four fields. The variable names must match exactly — a typo here produces entries that are silently skipped:

| Variable name | Type |
|---|---|
| `board_id` | Short Answer |
| `inbox_list_id` | Short Answer |
| `default_dept_id` | Short Answer |
| `default_user_email` | Short Answer |

**5.3** Find the IDs. Open the board URL with `.json` appended in a logged-in browser:

```
https://trello.com/b/<shortlink>/<name>.json
```

`id` at the top level is the board ID. The target list for new cards is under `lists`, each with its own `id`. Both are 24 hex characters.

**5.4** Add one entry per customer, with all four properties filled. The entry name is what agents see in the dropdown, so use the customer name.

**5.5** Admin Panel → Manage → Forms → Ticket Details → add a field of this list type, variable name `trello_board`.

**Abort if** an entry cannot be filled completely. Incomplete entries are skipped, and the skip is only visible in the log.

---

## 6. Enable and verify the wiring

Enable the plugin. Then:

```bash
cd /path/to/osticket/include/plugins/trello-sync
php tools/diagnose.php
```

This prints the full state: which credentials are present (masked, never in full), whether the token is valid, which account it belongs to, what permissions it has, and which board entries were parsed.

**Abort if** diagnose reports a missing key, an invalid token, or zero parsed board entries. Everything after this depends on those three.

---

## 7. Smoke test, in this order

**7.1 osTicket → Trello.** Create a test ticket, set the board field, save. A card must appear in the configured inbox list within seconds. If not: `php tools/diagnose.php` again and check the osTicket log.

**7.2 Public reply.** Answer the ticket publicly. The text must appear as a comment on the card.

**7.3 Internal note.** Add an internal note. **Nothing may appear in Trello.** If it does, disable the plugin immediately and file a security report — this is the one failure that matters most.

**7.4 Trello → osTicket.** Comment on the card as a *different* Trello user than the token owner. The comment must appear in the ticket as an **internal note**, not as a public reply.

**7.5 Loop check.** Your own writes must not bounce. Reply publicly in osTicket, watch the card get the comment, and confirm no second comment appears in the ticket.

**7.6 Board change.** Change the board field to another customer's board. The card must **move**, not be duplicated.

Note on 7.4: if the comment does not arrive, the inbound direction may be inactive. Trello registers the webhook against your callback URL and refuses if that URL does not answer with `200` to its validation request. A WAF or bot protection in front of the helpdesk is the usual cause — `VALIDATOR_URL_RETURNED_ERROR` in the log means Trello's validator got a `403`. Outbound sync works regardless.

---

## 8. Security gate

Run this before you consider the installation done. Both requests must return `401` with an identical body.

```bash
# No signature at all
curl -i -X POST https://<helpdesk>/api/trello \
     -H 'Content-Type: application/json' \
     -d '{"action":{"type":"commentCard"}}'
# expected: HTTP/1.1 401, body {"error":"unauthorized"}

# Forged X-Real-IP must change nothing
curl -i -X POST https://<helpdesk>/api/trello \
     -H 'X-Real-IP: 203.0.113.10' \
     -H 'Content-Type: application/json' \
     -d '{"action":{"type":"commentCard"}}'
# expected: identical 401
```

| Case | Expected | If it differs |
|---|---|---|
| No signature | `401`, generic body | **Stop.** Disable the plugin, file a report. |
| Forged `X-Real-IP` | identical `401` | Same. |
| Internal note (§7.3) | nothing in Trello | Same. |
| `tools/*.php` over HTTP | not reachable | Web server config, block it. |

---

## 9. Before go-live

Trello is a US provider. Everything synchronised leaves the EU. That is a deployment decision, not a plugin setting:

- Data-processing agreement with Atlassian in place
- Entry in the record of processing activities
- Decided which content may be transferred at all — the plugin gives you opt-in per ticket, so "metadata only" is achievable by keeping the board field empty for sensitive tickets
- Customers informed where required

---

## 10. Known limitations

- Attachments, due dates, labels, member assignments and checklists are not synchronised.
- One board per ticket. No fan-out.
- The inbound direction requires Trello to reach your callback URL. Behind a WAF that blocks unknown user agents, it will not register.
- The osTicket-coupled classes are not unit-tested. Signature verification, board validation and the sync rules are, with 80 tests.
- Not yet verified against a live osTicket instance beyond the author's own.

---

## Rollback

Disable the plugin in the admin panel. Nothing is deleted, on either side. To remove it entirely, uninstall it in the plugin list and delete `include/plugins/trello-sync/`. **Uninstalling is not reversible:** it deletes the webhooks at Trello and drops both tables, `ost_trello_map` (ticket ↔ card links) and `ost_trello_board`. Back them up first if you may need the links again.

**Updating** means replacing the folder contents under the **same folder name**. osTicket finds an installed plugin by its install path and keeps its configuration under that installation; do not uninstall to update.
