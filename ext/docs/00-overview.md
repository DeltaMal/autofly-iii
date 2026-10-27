# Firefly III "ext" fork: overview, rules and data model

This fork of [firefly-iii/firefly-iii](https://github.com/firefly-iii/firefly-iii) (AGPL-3.0) adds bank integrations directly into Firefly III:

1. **Statement import with auto-recon** ([02](02-statements-and-auto-recon.md))
   - Upload a bank statement.
   - The fork matches each line to an existing transaction.
   - It imports what the email alerts missed (bank fees, interest, debit orders).
   - It marks matched transactions as reconciled and explains any balance difference that remains.
2. **Built-in email import** ([03](03-email-import.md))
   - A PHP port of the standalone Python importer, `ff3_email_importer` ([06](06-python-importer-reference.md)).
   - It is configured in the Firefly UI instead of a YAML file.
3. **Custom periods** ([01](01-custom-periods.md))
   - Salary-cycle periods, e.g. the 25th to the 24th, plus manual "close period" overrides.
   - They are used wherever Firefly uses a view range.
   - Upstream turned this down as [#10825](https://github.com/firefly-iii/firefly-iii/issues/10825) ("custom periods will not become a feature of Firefly III"), so it has to live in the fork.

The base is upstream release tag **v6.7.9** (2026-10-11): Laravel 13, PHP ≥ 8.5, Blade views, and the `resources/assets/v3` frontend (Vite and Alpine CSP).

## Decisions

| Topic | Decision |
|---|---|
| Branches | `ext` is the default branch, started from tag `v6.7.9`. `main` is an untouched mirror of upstream. Only upstream **release tags** are merged into `ext`. |
| Custom tables | Same database as Firefly, **`ext_` prefix**, migrations in `ext/database/migrations` (loaded by our provider). **Never alter an upstream table.** Extra data about upstream rows goes in `ext_` tables keyed by the upstream id. |
| Naming | Directory `ext/`; namespace `FireflyIII\Ext\`; tables `ext_*`; routes `/ext/*` named `ext.*`; views and translations `ext::`; artisan commands `ext:*`; config `ext/config/ext.php` (key `ext`). |
| Composer | **No new packages.** `composer.lock` changes upstream all the time, and a new package would conflict on every merge. Gmail is called over plain REST with Guzzle, which is already installed. PDF text comes from poppler `pdftotext -layout`, installed in our Docker image. |
| Python importer | Keeps running in production until the PHP port reaches parity. The port first runs in **shadow mode** ([04](04-deployment-and-cutover.md)). Then we cut over and retire Python. |
| Real data | **Never committed.** A fork of a public repo is public. Anything real lives in `ext/tests/fixtures/private/`, which is git-ignored. Committed fixtures are anonymised ([fixtures README](../tests/fixtures/README.md)). |
| Order of work | Phase 0 (scaffolding) → 1 (periods) → 2 (statements and recon) → 3 (email port) → 4 (cut-over, done by the owner locally). Owner decisions on the phase PRs' open questions were applied in follow-up PRs and are recorded in 01–04. |

## Rules for staying mergeable (non-negotiable)

- **All custom code lives under `ext/`.** That includes PHP, views, migrations, translations, tests, Docker files and docs. Static JS and CSS go in `public/ext/`. Upstream never creates these paths.
- **One service provider:** `FireflyIII\Ext\ExtServiceProvider`. It does all of the wiring:
  - rebinds container services, e.g. `'navigation'`;
  - calls `loadRoutesFrom`, `loadViewsFrom('…','ext')`, `loadTranslationsFrom('…','ext')`, `loadMigrationsFrom` and `mergeConfigFrom`;
  - changes config at runtime, e.g. appends to `firefly.valid_view_ranges`;
  - registers commands with `$this->commands([...])`. Upstream only auto-discovers commands under `app/Console/Commands`.
  - registers listeners with `Event::listen`. Upstream discovers listeners in `app/Listeners` only.
  - registers schedules (`$this->callAfterResolving(Schedule::class, …)`);
  - adds middleware to existing groups with `Router::pushMiddlewareToGroup`.
- **Editing an upstream file is a last resort,** and the edit must be a **single delegating hook**, such as one `@includeIf('ext::…')` line or one `case` that calls into `ext/`.
  - Mark it with `CUSTOM(ext)` in a comment: `{{-- CUSTOM(ext) --}}` in Blade, `// CUSTOM(ext)` in PHP and JS.
  - Add it to [`ext/UPSTREAM_TOUCHPOINTS.md`](../UPSTREAM_TOUCHPOINTS.md) in the same commit.
  - The merge script checks every marker.
- **Never** reformat, reorder or "clean up" upstream code. Never change upstream translations in `resources/lang/*`, because Crowdin owns them.
- **Don't delete upstream CI workflows.** Disable them in the fork with `gh workflow disable`. Ours live in `.github/workflows/ext-*.yml`.

### Planned touchpoints (the complete expected list)

| Upstream file | Hook |
|---|---|
| `bootstrap/providers.php` | Append `FireflyIII\Ext\ExtServiceProvider::class`. It must come after `FireflyServiceProvider` so our `navigation` binding wins. |
| `composer.json` | `autoload.psr-4`: `"FireflyIII\\Ext\\": "ext/src/"`. `autoload-dev.psr-4`: `"FireflyIII\\Ext\\Tests\\": "ext/tests/"`. Autoload is not part of composer's lock `content-hash`, so `composer.lock` stays untouched. |
| `resources/views/components/layout/sidebar.blade.php` | `@includeIf('ext::partials.menu')`, placed before the closing `</ul>`. The partial renders `<li class="nav-header">` + `nav-item`s in upstream's style. It adds the "Bank integrations" menu and the AGPL §13 "Source (modified)" link to the fork. |
| `resources/views/preferences/index.blade.php` | `@includeIf('ext::preferences.view-range')`, inside the view-range radio group ([01](01-custom-periods.md)). |
| `resources/views/reports/index.blade.php` | `@includeIf('ext::reports.cycle-links')` after the preset ranges: cycle quick links for `CP` users ([01](01-custom-periods.md)). |
| `resources/assets/v3/js/support/get-viewrange.js` (+ at most one boot file that loads preferences) | `case 'CP'` delegating to `ext/resources/assets/period-calendar.js` ([01](01-custom-periods.md)). |
| Root `CLAUDE.md` (new file; upstream has none at v6.7.9, only `AGENTS.md`) | Points agents at `ext/docs/`. |

Anything else you find you must touch: stop, see whether a provider-level hook can do it instead, and only then add a minimal marked hunk and list it.

## Repo layout

```
ext/
  src/                     FireflyIII\Ext\...
    ExtServiceProvider.php
    Periods/               PeriodCalendar, ExtNavigation, settings value object
    Budgets/               auto-budgets on custom periods (ExtCreateAutoBudgetLimits, CustomPeriodAutoBudgets)
    Banks/                 BankDefinition, BankRegistry, AccountResolver, Fnb/...
    Import/                TransactionWriter, ExternalIdLookup, ReconciliationAdjuster
    Statements/            parsers, PdfTextExtractor, Reconciler, models
    Mail/                  MailProvider, Gmail/, MailImporter, text helpers, models
    Http/Controllers/  Http/Middleware/  Http/Requests/
    Console/               ext:* commands
    Models/                Eloquent models for ext_* tables
  config/ext.php
  routes/web.php
  database/migrations/     YYYY_MM_DD_HHMMSS_ext_*.php
  resources/views/         ext::...
  resources/lang/en_US/    ext::...
  resources/assets/        period-calendar.js (shared with v3 build)
  tests/                   Unit/, Feature/, js/, fixtures/
  phpunit.xml
  docker/                  Dockerfile, compose files
  scripts/                 merge-upstream.sh, anonymise_fnb_history.py
  docs/                    this spec
  UPSTREAM_TOUCHPOINTS.md
public/ext/js/             vanilla JS for ext pages (no build step)
```

## Phases and definition of done

| Phase | Scope | Done when |
|---|---|---|
| 0 | Skeleton, provider, touchpoints file, Docker image, CI, `merge-upstream.sh` | The image builds and Firefly boots with the provider registered. The ext test suite runs in CI. A merge drill passes: build the skeleton on `v6.7.8`, merge `v6.7.9`, and conflicts are only in touchpoint files. |
| 1 | Custom periods ([01](01-custom-periods.md)) | The period vector tests pass in PHP and JS. With view range `CP`, the dashboard, budgets, reports, transactions and prev/next all show salary-cycle periods. Non-`CP` behaviour is identical to upstream. |
| 2 | Bank framework, statement import, auto-recon ([02](02-statements-and-auto-recon.md)) | The anonymised FNB fixture parses exactly. Matcher scenarios pass. End to end on a dev stack: upload, review, commit, and a re-upload adds nothing. |
| 3 | Email import port ([03](03-email-import.md)) | 100% golden parity, external ids included. Shadow mode works against a real Gmail mailbox. The YAML import command works. |
| 4 | Deploy, shadow run, cut-over ([04](04-deployment-and-cutover.md)) | Owner-run, on the live instance. |

Open **one PR per phase** into `ext`. Keep commits focused, and run the ext tests and Pint/PHPStan if upstream's configs apply cleanly to `ext/`.

## Shared building blocks (used by phases 2 and 3)

- **`BankRegistry` / `BankDefinition`:**
  - interface: `id(): string`, `name()`, `country()`, `emailSenders(): list<string>`, `emailTemplates()`, `statementParsers()`;
  - banks are listed in `ext/config/ext.php`;
  - first bank: `Banks\Fnb\FnbBank`;
  - adding a bank = one folder + fixtures + tests.
- **`AccountResolver`:** an exact port of Python `accounts.py` (see [06 §4](06-python-importer-reference.md#4-account-resolution)).
  1. Explicit identifier from `ext_account_identifiers`: partial account numbers **and** card last-4s.
  2. Exactly one active asset or liability account whose digits-only `account_number` starts with (or, in suffix mode, ends with) the partial.
  3. The bank profile's default account, only when the caller allows it.
  4. Otherwise a typed failure: `ambiguous`, `missing_mapping` or `not_found`. **It never guesses.**
- **`TransactionWriter`:** the only code path that creates transactions.
  - Uses `TransactionGroupRepositoryInterface::store()` after `setUser()` and `setUserGroup()`, with `apply_rules: true`, `fire_webhooks: true` and `error_if_duplicate_hash: true`. That fires `CreatedSingleTransactionGroup`, so rules, webhooks and running balances work just as they do for API imports.
  - Before storing, checks **`ExternalIdLookup`**: `journal_meta` where `name = 'external_id'` and `data` = the JSON-encoded id, for journals in the user group, **including soft-deleted ones**. A transaction the user deleted never comes back.
  - Currency comes from the account's currency (Python used a configured `ZAR`).
  - Adds an import tag: `Email import Y-m-d` or `Statement import Y-m-d`.
  - Transfer matrix, from Python ([06 §7](06-python-importer-reference.md#7-how-rows-become-firefly-transactions)):

    | Source | Target | Booked as |
    |---|---|---|
    | asset | asset | transfer |
    | asset | liability | withdrawal, with the liability as destination |
    | not tracked | tracked | deposit on the target |
    | tracked | not tracked | withdrawal named after the counterparty |

## Data model (`ext_` tables)

All tables have `id`, timestamps and `user_group_id` (FK `user_groups`), except the link tables (`ext_mail_message_transactions`, `ext_auto_budget_limits`).
- FKs to upstream tables use `ON DELETE CASCADE`.
- Nullable links to `transaction_groups` and `transaction_journals` use `SET NULL`.
- Money columns are `decimal(32,12)`, like upstream.
- Every query is scoped to the current user's group.

| Table | Columns (besides id, timestamps, user_group_id) |
|---|---|
| `ext_bank_profiles` | `bank`, `account_match` (`prefix`/`suffix`), `default_account_id` (FK accounts, null), `tags` json, `require_authenticated` bool, `enabled` bool. Unique (`user_group_id`, `bank`). |
| `ext_account_identifiers` | `account_id` (FK accounts), `bank`, `kind` (`account_number`/`card`), `value` (digits). Unique (`user_group_id`, `bank`, `kind`, `value`). |
| `ext_mailboxes` | `user_id` (FK users), `name`, `provider` (`gmail`), `email_address`, `enabled`, `mode` (`live`/`shadow`), `query`, `lookback_days`, `label_imported`, `label_unmatched`, `banks` json, `oauth` (encrypted text), `last_polled_at`, `last_error`. |
| `ext_mail_messages` | `mailbox_id` (FK), `provider_message_id`, `received_at`, `sender`, `subject`, `outcome` (`transactions`/`ignored`/`unmatched`), `status` (see [03](03-email-import.md)), `label_synced` bool, `unmatched_text`, `raw` (encrypted, unmatched only), `error`. Unique (`mailbox_id`, `provider_message_id`). |
| `ext_mail_transactions` | `external_id`, `status`, `attempts`, `account_id` (null), `transaction_group_id` (null), `error`, `payload` json (the parsed transaction). Unique (`user_group_id`, `external_id`). |
| `ext_mail_message_transactions` | `mail_message_id`, `mail_transaction_id`. Primary key on both. |
| `ext_statements` | `user_id`, `account_id`, `bank`, `parser`, `file_name`, `file_sha256`, `file_path` (encrypted file on the `upload` disk), `statement_date`, `period_start`, `period_end`, `opening_balance`, `closing_balance`, `available_balance` (null), `chain_valid` bool, `status` (`parsed`/`committed`), `options` json, `summary` json. |
| `ext_statement_lines` | `statement_id` (FK), `posting_index`, `date`, `description`, `amount`, `fee`, `balance`, `card_ref` (null), `line_hash`, `status` (`matched`/`missing`/`created`/`proposal`/`ignored`), `transaction_journal_id` (null), `match_score` (null). Index on `line_hash`. |
| `ext_auto_budget_limits` | Link table, so no `id` or `user_group_id`: `budget_limit_id` (primary key, FK `budget_limits` `ON DELETE CASCADE`), `source` (`store`/`backfill`). Budget limits that upstream's auto-budget creation made ([01](01-custom-periods.md#auto-budgets-on-custom-periods)). |

Settings that are **not** tables:
- **Period settings:** user preference `extPeriod`, through the `Preferences` facade. Its `auto_budgets` key holds the auto-budget job options.
- **Auto-budget job status:** user preference `extAutoBudgetStatus` (when the job last ran for the user and what it changed).
- **Google OAuth client:** `FireflyConfig` keys `ext_google_client_id` and `ext_google_client_secret` (secret encrypted with `Crypt`).

## Testing

- **PHP:** PHPUnit config `ext/phpunit.xml`, suites `ext-unit` and `ext-feature`. Feature tests use Laravel's DB testing on SQLite in memory, if upstream's migrations allow it, otherwise MariaDB in CI.
  - There is no local PHP on the owner's machine. Everything runs in Docker or CI.
- **JS:** `node --test ext/tests/js/` (no dependencies).
- **Fixtures:** see `ext/tests/fixtures/README.md`. Tests that need private fixtures **skip themselves** when `ext/tests/fixtures/private/` is missing.

## Out of scope for now

- A rule action "start a new period when salary arrives".
- An IMAP provider.
- More banks and FNB layouts (credit card, savings, CSV export).
- Fetching password-protected statements from email.
- An `ext` REST API.
