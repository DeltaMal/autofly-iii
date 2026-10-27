# Phase 2: statement import and auto-recon

**Goal:** the user uploads a bank statement for an account. The fork parses it, then matches every statement line to an existing Firefly transaction (most were already imported from email alerts). Then it:
- imports the lines Firefly is missing: bank fees, interest, debit orders, anything the alerts missed;
- optionally marks matched and created transactions as **reconciled**;
- explains any balance difference that remains.

All of this is optional per upload, with a review step by default.

**Why:** email alerts never include fees or interest, so Firefly balances drift away from the bank's balance. In production that drift was about R1.5k over 7 weeks on one account.

This phase also builds the shared bank framework from [00 › Shared building blocks](00-overview.md#shared-building-blocks-used-by-phases-2-and-3): `BankRegistry`, `AccountResolver`, `TransactionWriter` and `ExternalIdLookup`.

## Statement parsing (`ext/src/Statements`)

### Interfaces

```php
interface StatementParser {
    public function id(): string;                         // 'fnb.transaction_history_pdf'
    public function supports(StatementFile $file): bool;  // sniff text/mime, cheap
    public function parse(StatementFile $file): ParsedStatement;
}
final class StatementFile { string $path; string $mime; ?string $password; string $text() /* lazy */ }
final class ParsedStatement {
    string $bank; string $parser; string $accountNumber; Carbon $statementDate;
    Carbon $periodStart; Carbon $periodEnd;               // earliest line date .. statement date
    string $openingBalance; string $closingBalance; ?string $availableBalance;  // bcmath strings
    bool $chainValid; array $warnings; /** @var StatementLine[] */ array $lines;
}
final class StatementLine {
    int $postingIndex; Carbon $date; string $description; string $amount; string $fee;
    string $balance; ?string $cardRef /* last 4 */; string $counterparty; string $raw;
}
```

- **Money:** strings, calculated with `bcmath` (upstream uses `Steam::bcround`/bc functions). Never floats.
- **`PdfTextExtractor`:**
  - Runs `pdftotext -layout [-upw <password>] <file> -` through `Symfony\Component\Process\Process` with an argument **array**: no shell, 30 s timeout, files up to 10 MB.
  - Poppler (`poppler-utils`) is installed in our Docker image.
  - The password is used once and never stored or logged.
- **Parsers are registered per bank** in `BankDefinition::statementParsers()`. Upload picks the first parser whose `supports()` returns true.

### FNB "Transaction History" PDF (`Banks/Fnb/TransactionHistoryPdfParser`)

This is the PDF that FNB online and app banking produce from *Transaction History → Download/Print*.

- **Anonymised `pdftotext -layout` output (committed):** [`ext/tests/fixtures/fnb-statement/transaction-history.layout.txt`](../tests/fixtures/fnb-statement/transaction-history.layout.txt).
- **Expected parse:** [`transaction-history.expected.json`](../tests/fixtures/fnb-statement/transaction-history.expected.json).
- **Python reference parser:** [`ext/scripts/anonymise_fnb_history.py`](../scripts/anonymise_fnb_history.py), function `parse()`. It is checked against a real statement: 60 lines, valid balance chain, and the opening/closing balance and sum of amounts all reconcile.

Layout facts the parser must handle:

- **Header block** (`Label:` followed by spaces and a value): `Nickname:` (two lines), `Selected Account:` (the full account number), `Date:` (`14 Jul 2025`, the statement date), `Available Balance:` / `Current Balance:` (`11,968.15 CR`).
- **Table header:** `Date  Description  Service Fee  Amount  Balance`. It repeats on every page, preceded by a form feed (`\f`).
- **Row line:** `^(\d{2} [A-Z][a-z]{2} \d{4}) +(desc) +(fee) +(amount DR|CR) +(balance CR|DR)\s*$`.
  - Fee: `-?[\d,]+\.\d{2}`, always `0.00` in the samples so far.
  - Amount: `-1,250.00 DR` or `12,345.67 CR`. DR is negative.
  - Balance: `x CR` is positive, `x DR` is negative (overdrawn).
  - **Column positions shift by up to 8 characters from row to row.** Parse each row with the regex from the right, never by fixed column offsets.
- **Continuation lines:** indented lines (≥ 10 leading spaces) under a row extend its description. Join them with single spaces.
- **Row and footer ends:** a blank line, a footer line or a page header ends the current row. Footer lines start with `Printed `, `info@`, `Address:` or `Tel:`, and the `©` text sits on the `Printed` line.
- **Card purchases:** `PURCH <merchant…> <card>`, where card is `\d{6}\*{6}\d{4}` (e.g. `400000******1234`, last 4 = card ref). Counterparty = description without `PURCH ` and without the card number.
- **Other shapes seen:**
  - `FNB APP TRANSFER` + `FROM <ref>`
  - `FNB APP PAYMENT FROM` + `<name>`
  - `INTERNET TRF FROM` + `<name>`
  - `#MONTHLY ACCOUNT` + `FEE` (the bank fee emails never mention)
  - bare debit-order or EFT references (`CREDITCARDSETTLE`, `<REF> <number>`)
  - Counterparty for these = the text after the keyword, otherwise the whole description.
- **Row order:** rows are in **posting order, newest first**, and **dates are not monotonic**. A 20 Oct row can sit below a 19 Oct row. Never sort before checking the chain.
- **Balance chain** (posting order, `i` above `i+1`): `balance[i] == balance[i+1] + amount[i]`.
  - Once a sample with a non-zero service fee exists, confirm whether the fee is inside that equation. Until then, a non-zero fee becomes an **extra fee line** and a warning.
  - If the chain breaks, `chainValid=false` and auto-commit is not allowed.
- **Balances:** closing = top row balance, which equals `Current Balance`. Opening = bottom row balance − bottom row amount.
- **Available vs current:** `Available Balance` < `Current Balance` means card reservations not yet posted. They are already in Firefly (from email) and are *not* on the statement. See the balance breakdown below.

Further parsers (follow-ups, once a sample exists): FNB credit card and savings layouts, FNB CSV export, and generic CSV with column mapping. **The MVP supports asset accounts only.** For liabilities, check Firefly's sign conventions first.

## Matching and auto-recon (`ext/src/Statements/Reconciler.php`)

Inputs: a `ParsedStatement`, the Firefly account, and options.

1. **Line identity:**
   - `line_hash = sha256(account_id|Y-m-d|signed amount|running balance|normalised description)`, where normalised = uppercase with whitespace collapsed.
   - Before matching, look up each hash in `ext_statement_lines` for this account. A line already linked (`matched` or `created`) on an earlier statement keeps that link. **Overlapping uploads never create duplicates.**
   - A transaction created from a line gets `external_id = stmt-<first 32 hex of line_hash>`, and `ExternalIdLookup` guards it too.
2. **Candidates:**
   - Journals on the account (source or destination) dated **line.date − 5 days … line.date + 2 days**. Card reservations from email come *before* posting.
   - The signed amount must be **exactly** equal, seen from the account.
   - Journals linked to another statement line are excluded.
3. **Score** (higher is better):
   - date distance (dominant);
   - +card ref equal to the email transaction's `internal_reference` (`card..NNNN`);
   - +token overlap (Jaccard) between the line counterparty and the journal description or opposing account name;
   - +the journal carries an email `external_id` (`fnb-…`).
4. **Assignment:** one to one, **greedy by score**, with a deterministic tie-break (posting index, then journal id).
5. **Outcome per line:**
   - `matched` (journal id, score);
   - `missing` → to be created;
   - `proposal`: no exact-amount match, but a journal within ±2 days with the same card ref or strong token overlap and a **different amount** (fuel pre-auth, tip, FX). It is shown as "update journal amount from X to Y" and **only applied on explicit confirmation**.
   - `ignored`: set by the user.
6. **Firefly-only journals** (dated within the statement window and not matched):
   - `pending`: dated within 3 days of the statement date. Probably reserved, not yet posted.
   - `reversed_pair`: a withdrawal plus a deposit with the same amount and card within 7 days (email reservation + reversal). Net zero; informational.
   - `unexplained`: links to view, edit or delete. **Nothing is ever deleted automatically.**
7. **Balance breakdown,** shown on top of the review page:

   | Row | Value |
   |---|---|
   | Statement closing balance | from the parser |
   | Firefly balance at end of statement date | `Steam::accountsBalancesOptimized(...)`, same as upstream `Account/ReconcileController` |
   | − pending Firefly-only journals | sum |
   | − missing lines (to be created) | sum |
   | **= unexplained difference** | should be 0 after commit |

   In practice, Firefly balance = bank "Current Balance" − not-yet-posted reservations. That matches the bank's "Available Balance" only when the account has no overdraft facility.

### Commit (one DB transaction, idempotent)
1. **Create** every selected `missing` line through `TransactionWriter`:
   - type: withdrawal if negative, deposit if positive;
   - counterparty: the line counterparty;
   - description: the line description;
   - tags: `statement-import`, `<bank>`, plus the import tag;
   - notes: raw line + statement file name;
   - rules applied, unless the option is off.
2. **Apply** confirmed `proposal`s: update the journal amount through upstream's update service, never raw SQL.
3. **Reconcile** (if the option is on): call `JournalRepository::reconcileById()` for matched and created journals. That sets `transactions.reconciled = true`, as upstream reconciliation does.
4. **Adjust** (if the option is on and the unexplained difference ≠ 0): `ext/src/Import/ReconciliationAdjuster` books a `reconciliation` transaction for the difference, dated the statement date. It copies the logic of upstream's private `Account/ReconcileController::createReconciliation()` (`TransactionGroupFactory`, type reconciliation, the account's reconciliation account, `reconciled = true`). Note that upstream doesn't fire events for this.
5. **Persist:** line statuses and journal links, the statement `status = committed`, and a `summary` JSON with the counts and breakdown.

## UI (`/ext/statements`, `user-full-auth`)

- **Index:** past uploads (date, account, period, status, counts, difference) and an **Upload** button.
- **Upload form** (`POST /ext/statements`):
  - **account:** optional. If empty, it is auto-detected from the statement's account number through `AccountResolver`. On ambiguous or not-found, ask the user.
  - **file:** PDF, max 10 MB.
  - **password:** optional, not stored.
  - **options:**
    - [x] Import missing transactions
    - [x] Mark matched as reconciled
    - [ ] Book an adjustment for the unexplained difference
    - [x] Apply rules
    - [ ] **Auto-commit without review.** Allowed only when `chainValid`, the account was resolved, and there are no proposals or ambiguities. Otherwise it falls back to review with a notice.
  - **Exact re-upload:** the same `file_sha256` for the account is refused unless "re-process" is ticked.
- **Review** (`GET /ext/statements/{id}`):
  - the breakdown table;
  - tabs **Missing** (checkbox per line, ticked by default), **Matched** (collapsed), **Proposals**, **Firefly only**, **Warnings**;
  - a **Commit** button.
- **Result:** counts plus links to the created transactions (by tag) and to the account's reconcile view.
- **Storage:** the uploaded file is encrypted with `Crypt::encrypt` on the `upload` disk (as upstream stores attachments), under `ext/statements/<sha>.bin`.

## Acceptance
- The anonymised FNB fixture parses to exactly `transaction-history.expected.json`: 60 lines, chain valid, opening `10019.49`, closing `12480.55`, sum `2461.06`, including `DR` (overdrawn) balances.
- If `ext/tests/fixtures/private/transaction-history.pdf` and `.expected.json` exist, the real PDF parses to the private expectation (the test is skipped when they are missing).
- **Matcher scenarios (unit tests):**
  - reservation 2 days before posting → matched;
  - two identical same-day amounts → one-to-one, deterministic;
  - fuel pre-auth with a different amount → proposal;
  - reservation + reversal not on the statement → `reversed_pair`;
  - re-uploading an overlapping statement → zero new transactions;
  - monthly fee line → missing → created with tag `statement-import`.
- **End to end on a dev stack:**
  1. Create an asset account numbered `62012345678`.
  2. Seed a few email-like transactions.
  3. Upload a PDF made from the anonymised layout (or the private real PDF).
  4. Review → commit → the breakdown difference is 0 (or equals the pending reservations).
  5. Re-upload → nothing new.
