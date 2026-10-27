# Reference: upstream Firefly III code map (v6.7.9)

Research notes from `main` at v6.7.9 (released 2026-10-11). **Re-check paths in the actual checkout before relying on them**; upstream moves quickly.

## Stack and layout
- **Stack:** Laravel ^13, PHP ≥ 8.5. `composer.json` autoloads `FireflyIII\\` → `app/` (and `Domain\\` → `domain/`, a folder that doesn't exist).
- **UI:** since v6.7.1 (2026-09-15) there is **one UI**:
  - Blade views in `resources/views/**` (Twig was removed);
  - AdminLTE 4, Bootstrap 5, Bootstrap Icons;
  - JS in `resources/assets/v3` (npm workspace: Vite, Alpine `@alpinejs/csp`, i18next, chart.js).
  - The old v1/v2 layouts are gone.
- **Providers:** `bootstrap/providers.php` (Laravel 11+ style). `config/app.php` only holds aliases.
- **`bootstrap/app.php`:**
  - middleware groups (`user-full-auth`, `admin`, `api`, …);
  - `->withEvents(discover: [app/Listeners])`, so listeners are auto-discovered by `handle()` type-hint (`EventServiceProvider` is effectively empty);
  - console commands are auto-registered from `app/Console/Commands/**`.
- **Routes:**
  - `routes/web.php`: `Route::group` blocks with `namespace`, `prefix`, `as` and middleware `user-full-auth` or `admin`.
  - `routes/api.php`: ~47 groups under `v1/...`.
  - `routes/console.php` is empty.
- **Migrations:** `database/migrations/` (62 files). Recent upstream migrations are monthly roll-ups, e.g. `2026_10_09_062600_migrations_10_2026.php`.
- **Composer:** `extra.laravel.dont-discover: []`, so package auto-discovery is on.
- **There is no plugin system.**

## Periods
See [01](01-custom-periods.md#how-upstream-handles-periods-today-v679):
- `app/Support/Navigation.php`, bound in `FireflyServiceProvider`;
- `app/Http/Middleware/Range.php`;
- `GetConfigurationData::getDateRangeConfig()`;
- `PreferencesController`;
- `resources/views/preferences/index.blade.php`;
- `resources/assets/v3/js/support/get-viewrange.js`;
- `FiscalHelper`;
- `config/firefly.php` keys `valid_view_ranges`, `dynamic_date_ranges` and `range_to_repeat_freq`.

## Reconciliation (upstream)
- **Routes:** `routes/web.php` ~229–246: `accounts/reconcile/{account}/index|submit|overview|transactions/{start?}/{end?}`.
- **`app/Http/Controllers/Account/ReconcileController.php`:**
  - `reconcile()` (asset accounts only): defaults to the session range. Start balance comes from `Steam::accountsBalancesOptimized(..., inclusive:false)`; the end balance is calculated too.
  - `submit()` (`ReconciliationStoreRequest`: `start`, `end`, `startBalance`, `endBalance`, `difference`, `journals[]`, `reconcile` = `create|nothing`):
    - `JournalRepository::reconcileById()` per journal sets `transactions.reconciled = true`;
    - with `create`, the private `createReconciliation()` builds a `reconciliation` group through `TransactionGroupFactory` directly (no events, no rules): amount = difference, dated at the end date, between the account and its reconciliation account, marked reconciled.
- **`app/Http/Controllers/Json/ReconcileController.php`:** `overview()` computes `difference = start − end + cleared + selected`.
- The statement balances the user types in are **not saved** upstream.

## Creating transactions server-side
- **`app/Repositories/TransactionGroup/TransactionGroupRepository::store(array $data)`:** set the user and user group first. It:
  - calls `TransactionGroupFactory::create()`;
  - fires `CreatedSingleTransactionGroup($flags, $objects)` and `WebhookMessagesRequestSending`;
  - takes flags from `apply_rules` (default true), `fire_webhooks` (default true) and `batch_submission` (`app/Events/Model/TransactionGroup/TransactionGroupEventFlags.php`).
- **Listener `app/Listeners/Model/TransactionGroup/ProcessesNewTransactionGroup.php`:** runs rules (`store-journal`), recalculates credit, creates webhook messages and recalculates running balances.
  - With `enable_batch_processing` on, a batch submission is skipped until `POST /api/v1/batch/finish` fires `UserRequestedBatchProcessing`.
- **Payload shape:** the same array the API `StoreRequest` builds:
  - group: `group_title`, `apply_rules`, `fire_webhooks`, `error_if_duplicate_hash`;
  - `transactions[]`: `type`, `date`, `amount`, `description`, `source_id|source_name`, `destination_id|destination_name`, `currency_code|currency_id`, `tags[]`, `notes`, `external_id`, `internal_reference`, …
- **Duplicate hash:**
  - `TransactionGroupFactory` → `journalFactory->setErrorOnHash($data['error_if_duplicate_hash'] ?? false)`.
  - `app/Factory/TransactionJournalFactory.php::hashArray()` = sha256 of the row's JSON, minus `import_hash_v2`, `original_source` and `batch_submission`. It is stored as journal meta `import_hash_v2`.
  - `errorIfDuplicate()` looks it up for the same user, **including soft-deleted rows**, and throws `DuplicateTransactionException`.
- **`external_id`:** plain journal meta, max 255 characters. **Upstream never enforces uniqueness**, which is why we have `ExternalIdLookup`.

## Databases
- Supported: MySQL/MariaDB (default), PostgreSQL and SQLite (`DB_CONNECTION`).
- `config/database.php` has one connection per driver. PostgreSQL `search_path` comes from `PGSQL_SCHEMA`.
- No code uses more than one connection.
- Driver-specific code: `RepairsPostgresSequences`, `ForcesDecimalSize` (accepts only those three), and About/Debug info.

## Cron
- **Command:** `app/Console/Commands/Tools/Cron.php`, `firefly-iii:cron` (`--force`, `--date`, `--create-recurring`, `--create-auto-budgets`, `--send-subscription-warnings`, `--check-version`, `--send-webhook-messages`, …).
- **HTTP:** `GET /api/v1/cron/{cliToken}` (`app/Api/V1/Controllers/System/CronController.php` + `CronRunner` trait). It accepts a user's CLI token or the 32-character `STATIC_CRON_TOKEN` (runs for all users). Since v6.7.1, cron runs per user.
- **Jobs:** `app/Support/Cronjobs/*` extend `AbstractCronjob` (`fire()`, `setUsers`, `setForce`, `setDate`) and throttle themselves through `AppConfiguration` keys.
- **No Laravel scheduler** (`withSchedule` isn't used), so ours doesn't clash.

## Rules
Rules run on every group stored through the repository when `apply_rules` is true. Calling `TransactionGroupFactory` directly skips them. `app/Console/Commands/Tools/ApplyRules.php` exists for applying rules by hand.

## Extension points we rely on
- **Rebinding:** `'navigation'` and every repository interface are plain `bind`s, so they can be rebound from our provider.
- **Loading:** `loadRoutesFrom`, `loadViewsFrom`, `loadTranslationsFrom`, `loadMigrationsFrom`, `mergeConfigFrom`.
- **Registering:** `$this->commands([...])` and `Event::listen` (our classes are outside the discovery paths).
- **Middleware:** `Router::pushMiddlewareToGroup()` adds middleware to `user-full-auth`/`admin`.
- **Blade:** `@includeIf` hooks in upstream views. These are the only view edits.
