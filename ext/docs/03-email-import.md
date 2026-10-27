# Phase 3: built-in email import

**Goal:** port the Python importer ([06](06-python-importer-reference.md)) into the fork. Mailboxes, banks and account mappings are configured in the Firefly UI. Transactions are created in-process; there is no Data Importer and no API token. The port must keep **byte-identical external ids**, so it never re-imports what the Python importer already did.

## Components (`ext/src/Mail`)

| Class | Responsibility |
|---|---|
| `MailMessage` (DTO) | `mailboxId`, `messageId`, `receivedAt` (UTC Carbon), `sender` (lowercased address), `subject` (`normaliseLine`), `authResults` (list, header order), `bodies` (list, HTML-derived first, then plain). |
| `Text` | `normaliseLine`, `normaliseText`, `htmlToText`: exact ports of `text.py` ([06 §3](06-python-importer-reference.md#3-fnb-email-template-banksfnbpy-banksbasepy-textpy)). `htmlToText` uses `DOMDocument` (UTF-8, `LIBXML_NOERROR`): remove script/style/head, add `\n` around the same block tags, `textContent`, then `normaliseText`. |
| `MailProvider` (interface) | `profile(): string` (email), `search(string $query): list<string>`, `fetch(string $id): MailMessage`, `fetchRaw(string $id): string`, `modifyLabels(list $ids, list $add, list $remove): void` |
| `Gmail\GmailProvider` | REST over Guzzle (below) |
| `Gmail\GmailOAuth` | Authorise URL, code exchange, refresh. Tokens are persisted on the mailbox. |
| `EmailTemplate` (interface, per bank) | `matches(MailMessage)`, `isAuthentic(MailMessage, bool $required)`, `parse(MailMessage): ParseResult` |
| `Banks\Fnb\FnbEmailTemplate` | Verbatim port of `banks/fnb.py` + `base.py` helpers (`senderAuthenticated`, `parseAmount`, `inferDateTime`, `stableId`) |
| `ParseResult`, `ParsedTransaction` | Same fields as Python ([06 §3](06-python-importer-reference.md#building-a-transaction-fnbtemplate_transaction)). Money as strings. |
| `MailImporter` | The pipeline (below) |
| `Console\PollMail` | `ext:mail:poll {--mailbox=} {--dry-run} {--retry-failed}` |
| `Console\ImportPythonConfig` | `ext:import-python-config {file} {--user=} {--dry-run}` |

### Porting notes for the FNB template
- **Regexes:** PCRE with the `u` flag. Python's `(?P<name>…)` works as-is.
- **Line ends:** Python `$` matches at the end or before a final `\n`; segments never hold `\n`, so plain `$` is fine.
- **Case-insensitive patterns:** use `i` where Python used `re.IGNORECASE`.
- **`str(Decimal)` balance:** strip `,` and spaces, keep the sign and the written decimals, drop leading integer zeros (keep a single `0` before `.`).
- **Upper-casing:** `mb_strtoupper($counterparty, 'UTF-8')`.
- **`infer_datetime`:** build with `Carbon::create(..., tz: app timezone)`. Reject impossible dates **without overflow**: `Carbon::create(2026, 2, 30)` rolls over to March, so check with `checkdate()` first.
- **Key format:** `occurred_at` is formatted **local** `Y-m-d\TH:i`.

## Gmail REST (`GmailProvider`, Guzzle, no SDK)

| Call | Endpoint |
|---|---|
| Authorise | `https://accounts.google.com/o/oauth2/v2/auth?client_id=…&redirect_uri=…&response_type=code&scope=https://www.googleapis.com/auth/gmail.modify&access_type=offline&prompt=consent&state=…` |
| Token / refresh | `POST https://oauth2.googleapis.com/token` (`grant_type=authorization_code` or `refresh_token`) |
| Profile | `GET https://gmail.googleapis.com/gmail/v1/users/me/profile` → `emailAddress` |
| Search | `GET …/users/me/messages?q=…&maxResults=500&pageToken=…` (follow `nextPageToken`) |
| Fetch | `GET …/users/me/messages/{id}?format=full` |
| Raw (unmatched only) | `GET …/users/me/messages/{id}?format=raw` → base64url |
| Labels | `GET …/users/me/labels`; `POST …/users/me/labels` `{name, labelListVisibility:"labelShow", messageListVisibility:"show"}` |
| Apply labels | `POST …/users/me/messages/batchModify` `{ids, addLabelIds, removeLabelIds}`, chunks of 1000 |

- **Query:** exactly Python's `build_query` ([06 §2](06-python-importer-reference.md#2-gmail-provider)). Senders come from the mailbox's enabled banks; `since` = now − `lookback_days`; exclude the imported label.
- **`format=full` decoding:**
  - `payload.headers` keeps header order. Take `From`, `Subject`, `Date` and every `Authentication-Results` in order.
  - Walk `payload.parts` recursively. Pick the first non-attachment `text/html` part and the first `text/plain` part, preferring parts inside `multipart/alternative` (this mirrors Python's `get_body`).
  - Decode `body.data` (base64url). If a part has `body.attachmentId` instead of data, fetch it.
  - Convert from the part's `Content-Type` charset to UTF-8 (`mb_convert_encoding`; default UTF-8).
  - Decode MIME-encoded subjects (`iconv_mime_decode` / `mb_decode_mimeheader`).
  - `receivedAt` = `internalDate` (ms, UTC).
- **Errors:** 401 → refresh once and retry. Refresh failure (`invalid_grant`) → the mailbox goes into error `reconnect required`. 429/5xx → exponential backoff, 3 tries.

## OAuth inside Firefly
- **Client setup:** the site owner sets a Google OAuth client of type **Web application** on `/ext/admin/google` (route group `admin`).
  - The page shows the exact redirect URI to register: `APP_URL/ext/mail/oauth/callback`.
  - Stored in `FireflyConfig` as `ext_google_client_id` and `ext_google_client_secret` (`Crypt::encrypt`).
  - The page explains that the consent screen must be **In production**, or refresh tokens expire after 7 days.
- **Connect a mailbox:** `/ext/mailboxes/{id}/connect` redirects to Google with a `state` held in the session and bound to user + mailbox. The callback checks `state`, exchanges the code and saves to `ext_mailboxes.oauth` (encrypted cast): `{client_id, client_secret|null, refresh_token, access_token, expires_at, scope}`. It then calls `profile()` to fill `email_address`.
- **Imported tokens:** tokens brought over from Python carry their own Desktop `client_id`/`client_secret`, and refresh works with them. A mailbox's own client takes precedence over the global one.

## Pipeline (`MailImporter::run(Mailbox $mailbox, Options $o)`)

Writes are synchronous inside Firefly, so Python's FIDI `submitted`/settle/verify machinery goes away.

1. **Lock:** `Cache::lock("ext-mail-poll:{$mailbox->id}", 7200)`. If held, skip.
2. **Retry failed** (if `retry_failed`): failed transactions → `pending`, attempts 0; failed messages → `pending`.
3. **Search** the query, then go through each id:
   - **Skip** if `ext_mail_messages` already holds it with a status other than `unmatched`.
   - **Otherwise** fetch and choose the first enabled bank template whose `matches()` is true:
     - **No template** → unmatched (reason "no bank template").
     - **`isAuthentic` false** (with the bank profile's `require_authenticated`) → unmatched (reason "failed sender authentication").
     - **Otherwise** `parse()`. A parser exception → unmatched, with the exception message.
   - **Save the message:** outcome and status. For unmatched messages, also `unmatched_text` (the unmatched segments) and `raw` (`fetchRaw`, encrypted).
   - **Save its transactions:** each one into `ext_mail_transactions`, unless a row for `(user_group_id, external_id)` already exists (**first sighting wins**). Always add the link row.
   - Commit after each message.
4. **Load** every `pending` and `unmapped` transaction of the user group:
   - Resolve accounts with `AccountResolver` and the bank profile ([00](00-overview.md#shared-building-blocks-used-by-phases-2-and-3)). Apply the **transfer matrix** ([06 §7](06-python-importer-reference.md#7-how-rows-become-firefly-transactions)).
   - **Unresolved** → `unmapped` + error text. It is retried on every run.
   - **`ExternalIdLookup` finds the id** → `imported`, linking the existing group. This is what dedupes against the Python importer's work.
   - **Otherwise** `TransactionWriter` stores it:
     - fields: date = `occurred_at` local, description, counterparty, tags, `internal_reference` = `card..<card_ref>`, notes as in [06 §6](06-python-importer-reference.md#6-notes-notes-field), import tag `Email import Y-m-d`;
     - rules and webhooks on;
     - on success → `imported` + `transaction_group_id`;
     - on exception → attempts+1 and the error saved; `failed` once attempts ≥ 3.
5. **Refresh message statuses** with Python's derivation ([06 §5](06-python-importer-reference.md#5-pipeline-and-state-pipelinepy-statepy)). A status change resets `label_synced`.
6. **Sync labels** (live mode, `label_synced = 0`):
   - `imported` → add imported, remove unmatched;
   - `unmatched` → add unmatched;
   - others → none.
   - On failure, leave `label_synced = 0`.
7. Update `last_polled_at` and `last_error` on the mailbox. **Errors in one mailbox never stop the others.**

**Shadow mode** (`ext_mailboxes.mode = shadow`), used to run safely next to the Python importer:
- Steps 1–5 run, but step 4 **never writes**. It records `status = shadow_exists` (already in Firefly) or `shadow_would_create` / `unmapped`.
- No labels are applied.
- Switching a mailbox from shadow to **live** deletes its shadow rows (messages and transactions), so live mode looks at everything again. External-id dedup and the Gmail imported label stop any double import.

**Dry run** (`--dry-run`): like shadow, but nothing is saved at all; the summary is printed.

## Scheduling
- `ExtServiceProvider` schedules `ext:mail:poll` **every 15 minutes**, `withoutOverlapping()`, through `callAfterResolving(Schedule::class, …)`. Upstream doesn't use Laravel's scheduler, so there's no conflict.
- In Docker, an `ext-scheduler` service runs the same image with `php artisan schedule:work` ([04](04-deployment-and-cutover.md)).
- There is also a **"Poll now"** button per mailbox. It runs synchronously with a 120 s limit and flashes the summary. **Decided, kept:** it stays synchronous; it is not dispatched to the scheduler.

## UI (Blade, extends upstream layout, `user-full-auth` unless noted)

| Route | Page |
|---|---|
| `/ext/mailboxes` | List: name, address, banks, mode, enabled, last poll, last error, Connect/Reconnect, Poll now. Create/edit form: name, banks (checkboxes from `BankRegistry`), query, lookback days, labels, mode, enabled. |
| `/ext/mail` | Activity log of `ext_mail_messages`, filterable by status and mailbox. Detail view: subject, received, bodies (parsed text), unmatched segments, linked transactions (links to Firefly), errors, and **Retry** for failed or unmapped. |
| `/ext/banks` | Per bank profile: account match mode, default account, tags, require authenticated, enabled. **Decided, kept:** a bank with no saved profile tags its imports with just the bank id (`fnb`). `ext:import-python-config` carries over the Python tags (e.g. `[fnb, email-import]`). |
| `/ext/accounts` | Every active asset and liability account, with its identifiers per bank (partial account numbers, card last-4s). Add or remove. It also shows which accounts the automatic number match would pick. |
| `/ext/admin/google` (`admin`) | OAuth client id and secret, the redirect URI to register, consent-screen notes. |

- **Menu:** the `ext::partials.menu` touchpoint adds "Bank integrations": Statements, Mailboxes, Email activity, Accounts, Banks, Periods (+ Google for owners).
- **Strings:** in `ext/resources/lang/en_US/*.php`.
- **JS:** vanilla, in `public/ext/js/`, loaded with the layout's CSP nonce. **No inline handlers** (the app sends a CSP).

## Moving the Python config over (`ext:import-python-config`)

The command reads a **JSON** file. At v6.7.9, `symfony/yaml` is not installed (it only appears as a suggestion in `composer.lock`), and we add no packages.

```json
{
  "timezone": "Africa/Johannesburg",
  "mailboxes": [
    {"id": "personal", "provider": "gmail", "enabled": true, "query": "", "lookback_days": 30,
     "banks": ["fnb"], "labels": {"imported": "Firefly/Imported", "unmatched": "Firefly/Unmatched"},
     "token": {"refresh_token": "…", "client_id": "…", "client_secret": "…", "token_uri": "…", "scopes": ["…"]}}
  ],
  "banks": {
    "fnb": {"accounts": {"100100": 12}, "account_match": "prefix", "default_account_id": null,
            "tags": ["fnb", "email-import"], "require_authenticated": true}
  }
}
```

- `--user=<email>`: the Firefly user who owns the mailboxes. Data goes into that user's current user group.
- Mailboxes are created in **shadow** mode.
- Every account id must exist in the group, or the command fails before writing anything (one DB transaction).
- `--dry-run` prints what would be created.
- The owner produces this JSON locally with a small exporter in the Python repo (Phase 4) and deletes it after import, because it holds refresh tokens.

## Acceptance
- **Golden parity:** a test iterates [`golden.json`](../tests/fixtures/fnb-email/golden.json):
  - `normalise_line`, `html_to_text`, `split_notifications`, `sender_authenticated`, `infer_datetime` (including the error cases);
  - every `messages[]` case: `matches`, `is_authentic`, `is_authentic_without_check`, `outcome`, `ignored`, `unmatched`, and every transaction field including `external_id` and `signed_amount`.
  - **100%, no skips.**
- **Gmail provider unit tests** (Guzzle `MockHandler`):
  - query string;
  - pagination;
  - `format=full` decoding: build Gmail-shaped JSON from the golden `parts` (base64url), including a non-UTF-8 charset part and a MIME-encoded subject;
  - label create-if-missing and remove-if-exists;
  - `batchModify` chunking at 1000;
  - token refresh saved; `invalid_grant` → reconnect state.
- **Pipeline feature tests** reproduce [06 §8](06-python-importer-reference.md#8-behaviour-locked-in-by-the-python-tests-reproduce-all-of-them) with a fake `MailProvider`, including:
  - idempotent second run;
  - unmapped → waits → imports once mapped;
  - existing `external_id` (including soft-deleted) → not created again;
  - the same alert from two mailboxes → one transaction, both emails labelled;
  - transfer matrix;
  - shadow mode writes nothing and sets no labels.
- **`ext:import-python-config`** creates the expected rows from a sample JSON. A bad account id rolls back.
