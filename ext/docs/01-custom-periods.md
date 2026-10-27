# Phase 1: custom periods

**Goal:** a user can choose a **custom period** as their view range. An example is the salary cycle: 25th to 24th, moved off weekends, with a one-off "salary came early, start the new period today". Firefly then uses those periods everywhere it uses the view range:
- dashboard
- prev/next navigation
- budgets
- reports
- transaction lists
- charts

**Why it is fork-only:** upstream closed [#10825](https://github.com/firefly-iii/firefly-iii/issues/10825) as "not planned". Related upstream issues: #1230, #4643 and #10757 (14/28-day budget periods).

## How upstream handles periods today (v6.7.9)

The view range is the user preference `viewRange`.
- **Valid values:** `1D 1W 1M 3M 6M 1Y` (`config/firefly.php` `valid_view_ranges`), plus the dynamic ranges `last7 last30 last90 last365 MTD QTD YTD`. `custom` is used internally by the date picker and is never stored.
- **`app/Http/Middleware/Range.php`** (groups `user-full-auth` and `admin`): when the session has no `start`/`end`, it calls `Navigation::updateStartDate($viewRange, today)` / `updateEndDate()` and stores the result in the session.
- **`app/Support/Navigation.php`** (not final) holds all the period maths: `addPeriod`, `subtractPeriod`, `startOfPeriod`, `endOfPeriod`, `endOfX`, `updateStartDate`, `updateEndDate`, `periodShow`, `getViewRange`, `blockPeriods`, `listOfPeriods`, …
  - It is bound **only** in `app/Providers/FireflyServiceProvider.php` as `$this->app->bind('navigation', fn () => new Navigation())`.
  - The facade is `app/Support/Facades/Navigation.php`.
- **Prev/next links:** `app/Support/Http/Controllers/GetConfigurationData.php::getDateRangeConfig()`.
- **Date picker:** `HomeController::dateRange()`.
- **`1Y` with a fiscal year:** `app/Helpers/Fiscal/FiscalHelper.php`, using the `customFiscalYear` and `fiscalYearStart` preferences. This is the closest existing example of an offset start day.
- **Other `viewRange` readers:**
  - `PeriodOverview`
  - `AvailableBudgetCalculator`
  - Budget, Bill and Transaction index controllers
  - `Chart/CategoryController`
  - `Account/ReconcileController`
  - `Api/V1/Controllers/Summary/BasicController`
- **Saving:** `PreferencesController::postIndex()` saves `viewRange` **without a whitelist** and clears session `start`/`end`/`range`. The view is `resources/views/preferences/index.blade.php`, with one hard-coded radio button per range.
- **The v3 JS computes periods too:** `resources/assets/v3/js/support/get-viewrange.js` (`getViewRange`, `addPeriod`, `subtractPeriod`, using date-fns), used by `js/boot/bootstrap.js` and `js/pages/shared/dates.js`.

## Design

### View range key `CP`
- `ExtServiceProvider` appends `CP` to `config('firefly.valid_view_ranges')` at runtime.
- It sets `config('firefly.range_to_repeat_freq.CP') = 'CP'`, so code that turns ranges into frequencies sends `CP` back into Navigation.
- If an audited consumer can't handle `'CP'` as a frequency, choose the smallest fix: either map that one consumer, or handle it in `ExtNavigation`.

### Settings: user preference `extPeriod` (JSON, via `Preferences::set/get`)

```json
{
  "anchor_day": 25,
  "business_day_rule": "previous",
  "label": "range",
  "starts": [{"date": "2026-10-23", "name": "Oct salary (early)"}]
}
```

| Key | Values | Default |
|---|---|---|
| `anchor_day` | `null` (manual mode) or `1`–`31`. 31 = "last day of the month"; larger days are clamped to the month's length. | 25 |
| `business_day_rule` | `none`, `previous` or `next`: what happens when a computed start falls on Sat/Sun | `none` |
| `label` | `range` ("25 Oct – 24 Nov 2026"), `start_month` ("October 2026"), `end_month` ("November 2026") | `range` |
| `starts` | Explicit period starts, `{date, name?}`, added by "Close current period" | `[]` |
| `auto_budgets` | The auto-budget job options, see [Auto-budget job options](#auto-budget-job-options). Missing or broken values fall back to the defaults. | all defaults |

### Algorithm (`ext/src/Periods/PeriodCalendar.php`, a pure value class)

The reference implementation is [`ext/scripts/period_vectors.py`](../scripts/period_vectors.py). The PHP and JS versions must reproduce [`ext/tests/fixtures/period-vectors.json`](../tests/fixtures/period-vectors.json) exactly.

1. **Anchored mode** (`anchor_day` set):
   - For each month, the computed start is `min(anchor_day, days in month)`. Apply `business_day_rule`: step −1 or +1 day until Mon–Fri.
   - For each explicit start, find the computed start **nearest** to it (smallest distance in days; a tie goes to the later one) and remove it.
   - Boundaries = remaining computed starts ∪ explicit starts.
   - Two explicit starts close to the same computed start both stay, which splits that period.
2. **Manual mode** (`anchor_day` null):
   - Boundaries = explicit starts, plus the 1st of every month before the first explicit start.
   - After the last explicit start, boundaries repeat the length of the last period (last − second-last, in days).
   - With exactly one explicit start, they repeat monthly on its day of month, clamped.
   - No explicit starts at all means calendar months.
3. **Building a period:** the period containing date `x` runs from `max{b ≤ x}` at 00:00:00 to `min{b > x} − 1 day` at 23:59:59, in the user's timezone (Firefly's `Carbon` with app TZ).
4. **Label:** an explicit start with a `name` uses that name. Otherwise use the `label` style. The range format is `j M – j M Y`, or `j M Y – j M Y` across years. Translate month names with `isoFormat` and `ext::periods.*` strings.
5. **Moving by periods** (`add`/`subtract` with `skip`):
   - Move `skip + 1` whole periods forward or back.
   - Keep the day offset from the period start.
   - Clamp to the target period's end.
   - See the `moves` vectors.

Cache the boundary list per request: compute ±3 years around the dates asked for, and extend lazily.

### `ExtNavigation` (`ext/src/Periods/ExtNavigation.php`)
- `class ExtNavigation extends \FireflyIII\Support\Navigation`.
- `ExtServiceProvider::register()` binds `'navigation'` to it. The provider is registered after `FireflyServiceProvider`, so this binding wins.
- In every public method that branches on a range or frequency string:
  - if the key is `'CP'`, answer through `PeriodCalendar`, using the authenticated user's `extPeriod`;
  - otherwise `return parent::method(...)`.
- These public methods take a range or frequency string at v6.7.9 (checked in the checkout): `addPeriod`, `subtractPeriod`, `startOfPeriod`, `endOfPeriod`, `endOfX`, `updateStartDate`, `updateEndDate`, `periodShow`, `blockPeriods`, `diffInPeriods`, `preferredCarbonFormatByPeriod`, and `getViewRange` (which returns the preference). Override every one.
- `listOfPeriods` and the `preferred*Format(start, end)` / `preferredEndOfPeriod` helpers work from the distance between dates. Check whether they need `CP` handling.
- `nextDateByInterval` takes a `Periodicity` enum, not a range, so leave it alone.
- **Test:** for every upstream key, `ExtNavigation` returns exactly what `Navigation` returns. Use a data provider over all keys and several dates.

### How CP behaves for the user

In the owner's words: "it should basically work like a custom range but instead of us manually entering it every time it defaults to the custom cycle."
- With view range `CP`, every page that takes a date range (dashboard, budgets, transaction lists, subscriptions, categories, accounts, tags, the report form) starts on the **current cycle**, because `Range` puts the cycle in the session.
- The date picker's previous/next entries, and the period overviews on account, category and tag pages, are **whole cycles**.
- The user can still pick any **custom range** in the date picker. It holds for that session only; the `viewRange` and `extPeriod` preferences are never changed by it. A new session starts on the cycle again.
- These are upstream mechanisms working through `ExtNavigation`; the follow-up check (`CustomPeriodRangeTest`) found no page that needed a fix. The report page's date fields default to the cycle.

### Cycle quick links on the reports page (decided)
- Upstream's preset ranges on the reports index (years, quarters, months) stay as they are.
- For users with view range `CP`, a row "Custom periods" follows them: every cycle from the one containing the first transaction up to the current one, grouped by the year the cycle starts in, oldest first like upstream's months, each labelled like the date picker (`ExtNavigation::periodShow`).
- Each link opens upstream's default report (`reports.report.default`) for all the accounts upstream's quick links use, from the cycle's start to its end.
- Wiring: one `@includeIf('ext::reports.cycle-links')` line in `resources/views/reports/index.blade.php`, marked `CUSTOM(ext)`; the partial uses `Periods\ReportCycleLinks`. Non-`CP` users get nothing from the partial, so their page is unchanged.

### Bills on CP (decided)
The bills page shows a "per period" amount. For `CP` it divides the yearly amount by the **number of custom periods in the last 365 days** (12 for a monthly salary cycle), through the one-line hook in `Bill\IndexController::amountPerPeriod()`. The owner confirmed this divisor.

### Audit of other `viewRange` readers (required deliverable)
- For each reader listed in [How upstream handles periods today](#how-upstream-handles-periods-today-v679), record in the PR description whether it goes through Navigation (fine) or switches on the range itself.
- For the second kind, add a minimal `CUSTOM(ext)` hunk that delegates to `ExtNavigation`, and list it in `UPSTREAM_TOUCHPOINTS.md`.
- `AvailableBudgetCalculator` and the budget index create budget limits for the session `start`/`end`. Budget limits accept any date range, so custom-period budgets should work. Verify it end to end.

### Preferences UI
- **The `ext::preferences.view-range` partial:** included with one `@includeIf` line inside upstream's view-range radio group. It renders:
  - a radio `viewRange=CP`, labelled "Custom period (e.g. salary cycle)";
  - an anchor-day select (Manual, 1–31, with 31 shown as "Last day");
  - a business-day rule select;
  - a label style select.
  - Field names are `ext_period[anchor_day]` etc.
- **Saving:** `ext/src/Http/Middleware/SaveExtPeriodPreference`.
  - Added with `$router->pushMiddlewareToGroup('user-full-auth', …)`.
  - Does nothing unless the route name is `preferences.update` and the method is POST.
  - Validates `ext_period[...]` and saves `extPeriod`, keeping `starts`. Upstream's controller saves `viewRange` itself.
  - **Controllers stay unedited.**
- **Page `/ext/periods`** (route `ext.periods.index`):
  - shows the current period and the next six;
  - lists the explicit starts, each with a delete button;
  - has a **"Close current period"** form: date (default today) and optional name. It appends to `starts`, then clears session `start`/`end` so the new period takes effect at once;
  - in **manual mode with no explicit starts**, shows a warning (`ext::periods.manual_no_starts`) that periods fall back to calendar months until a start date is added.

### v3 frontend
- `ext/resources/assets/period-calendar.js`: an ES module, a line-for-line port of `PeriodCalendar` with no dependencies.
- Touchpoint `get-viewrange.js`: one `case 'CP':` in `getViewRange`, `addPeriod` and `subtractPeriod`, delegating to the module.
- The JS needs `extPeriod`. Load it next to where the boot code loads `viewRange` (likely `GET /api/v1/preferences/viewRange` in `js/boot/bootstrap.js`), via `GET /api/v1/preferences/extPeriod`, and store it the same way. That is the second touchpoint; keep it to a few lines.
- If Vite won't import from outside `resources/assets/v3`, put the module at `resources/assets/v3/js/ext/period-calendar.js`. That is a new file inside upstream's tree, but nobody else edits it. Have the JS tests import it from there.
- **JS tests:** `ext/tests/js/period-calendar.test.mjs` runs the same `period-vectors.json` with `node --test`.

## Auto-budgets on custom periods

Upstream's `AutoBudgetCronjob` resolves its job with `app(CreateAutoBudgetLimits::class, [$date])`. `ExtServiceProvider` binds that class to `ext/src/Budgets/ExtCreateAutoBudgetLimits` (a subclass), so **no upstream file changes**.

- **Users whose `viewRange` is not `CP`:** `parent::handle()`, exactly upstream.
- **`CP` users:**
  - **monthly** auto-budgets of active budgets go through `Budgets\CustomPeriodAutoBudgets`: the limit is made for the custom period containing the cron date (`PeriodCalendar` boundaries from the user's own `extPeriod`). Upstream's job never sees them, so it never makes calendar-month limits for them;
  - every other auto-budget (daily, weekly, quarterly, half-year, yearly, and deleted or inactive budgets) goes through upstream's own `handleAutoBudget()`, unchanged.
- **Amounts** are upstream's, with "month" read as "custom period":
  - **reset:** the auto-budget amount;
  - **rollover:** the amount, plus what was left of the limit on exactly the previous custom period when that was positive;
  - **adjusted:** previous limit + amount + spent in the previous period (spent is negative); at least `1` when overspending ate it all;
  - with no limit on exactly the previous custom period, rollover and adjusted use the plain amount (upstream does the same with no previous month).
- **When it runs:** on every cron run, not only on a period's first day (upstream only acts on the 1st). A missed run is caught up on the next one; the previous period is complete by then anyway.
- **Idempotent:** a limit on exactly the current custom period (any currency, like upstream's lookup) means nothing is done. A second run creates nothing.
- **Only auto-budget-made limits are ever changed (decided).** In the owner's words: "only limits introduced via auto budget gets updated". A limit is **auto-budget-made** when it is `generated` (upstream's cron or the ext job made it) **or** recorded in `ext_auto_budget_limits` (upstream's first limit of a new budget, see below). Any other limit is the user's and is never cut, moved, adjusted or removed, even when it is a calendar month with exactly the auto-budget amount. If such a limit overlaps a custom period, no limit is created for that period (logged), so limits never overlap.
- **Upstream's first limit is recorded (decided).** Upstream's `BudgetRepository::store()` makes a first limit (the auto-budget's period containing today, with the auto-budget amount) for a budget created with an auto-budget, through the web form and the API, and does not mark it `generated`.
  - ext records its id in `ext_auto_budget_limits` (FK `budget_limits.id`, `ON DELETE CASCADE`) for **every** user, whatever the view range and options, so a later switch to `CP` takes it over like the cron's limits.
  - Wiring: `Budgets\InitialAutoBudgetLimit`, registered in `ExtServiceProvider`. An Eloquent `created` listener on `AutoBudget` remembers the budget when the budget itself was just created (the store path); the `CreatedBudgetLimit` event that follows identifies the limit: same budget, not `generated`, the auto-budget's currency and amount, and exactly the auto-budget period containing today. No upstream file changes.
  - Limits that existed before ext recorded them can be marked once with `php artisan ext:auto-budgets:backfill [--dry-run]`, an optional cut-over step ([04](04-deployment-and-cutover.md)). It marks a limit only when there is no doubt: the budget has a monthly auto-budget created together with it (within a minute), and the limit is not `generated`, is exactly the calendar month the budget was created in, was stored together with the auto-budget (within a minute), and has the auto-budget's amount and currency. It prints what it marked and is idempotent.

### Auto-budget job options

Every behaviour of the job is an option on `/ext/periods` (section "Auto-budget job"), so users can see what will happen to their budget limits. In the owner's words: "they should be configurable options on the integration job so that users are aware of this behaviour".
- Each option has a label and a one- or two-sentence explanation (`ext::periods.ab_*`).
- They are stored per user in `extPeriod.auto_budgets`, validated by `PeriodSettings::autoBudgetRules()` and read leniently by `AutoBudgetOptions::fromArray()` (missing means the default).
- The daily job, the listeners and the preferences middleware all respect them. Saving upstream's preferences form, or closing a period, keeps them.

| Option | Default | On | Off |
|---|---|---|---|
| `enabled`: "Align monthly auto-budgets with my custom period" | on | Everything in this section, for a user on `CP` and when leaving it. | Upstream for this user, even on `CP`: the job runs `parent::handle()` only (calendar months), and the listeners and the middleware change no limit. Upstream's first limit is still recorded. |
| `take_over`: "Take over calendar-month limits when I switch to custom periods" | on | When the job (or a new budget, or an edit) makes a cycle limit, auto-budget-made limits of that budget (same currency) that overlap the cycle are cut back to end the day before it, or removed when they start inside it. | They stay. A cycle they overlap gets no limit (logged); the first cycle clear of them gets one. |
| `new_budget_immediate`: "Give a new budget its custom period limit at once" | on | A budget created with a monthly auto-budget on `CP` gets the current cycle's limit at once: upstream's first limit is changed in place to the cycle, with the cron's amount rules, and marked `generated`. If a user's limit overlaps, it stays as upstream made it. | Upstream's calendar limit stays (recorded); the next daily run takes it over when `take_over` is on. |
| `edit_immediate`: "Apply auto-budget changes to the current period at once" | on | When a budget gains an auto-budget, or its amount, type, period or currency changes, on `CP`: if the current cycle has an auto-budget-made limit in the auto-budget's currency, its amount is recalculated with the cron's rules; if the cycle has no limit, one is made as the job would (with `take_over`). A user's limit on the cycle stays. | Nothing happens until the next daily run, which only creates a limit for a cycle that has none. |
| `cut_on_leave`: "Cut the current period short when I switch back to calendar months" | on | When the view range changes from `CP` to anything else, the current cycle's auto-budget-made limit is cut to end on the last day of the current calendar month (see "When each behaviour runs"). | The cycle keeps its end; upstream's calendar limit from the next 1st overlaps it until the cycle ends. |
| `gap_fill`: "After switching back, fill the days up to the 1st" | `pro_rata` | `pro_rata`: a `generated` limit of the auto-budget amount × days left ÷ days in the month (bcmath, rounded half up to the currency's decimals). `full`: the full auto-budget amount. | `none`: those days have no limit until the 1st. |

**`gap_fill` in detail:**
- After leaving `CP`, a custom period limit that ends inside the current calendar month, before its last day, leaves the days from the next day to the end of the month without a limit.
- Example: the cycle ended on 22 Oct and the user switches on 23 Oct, so 23–31 Oct are uncovered. `pro_rata` of 100 gives 100 × 9 ÷ 31 = 29.03.
- Only monthly auto-budgets of active budgets (the ones upstream makes monthly limits for), and only after a `generated` custom period limit.
- If a limit already covers some of those days, only the days after the last of them are filled, so limits never overlap.
- It runs when the preferences form leaves `CP` and in every daily run for non-`CP` users. It is idempotent: a filled gap is covered.

**Status:** the section also shows when the job last ran for the user and what it changed (limits created, adjusted, cut short, removed), and the last change made outside the daily run (switching view range, a new budget, an auto-budget change). It is stored in the user preference `extAutoBudgetStatus`; there is no table.

### When each behaviour runs

- **Switching to `CP` mid-month** (`take_over`): on the first run after the switch, auto-budget-made limits of this budget (same currency) that overlap the current custom period are cut back to end the day before it starts, or removed when they start inside it. Then the custom-period limit is created.
- **New budget while on `CP`** (`new_budget_immediate`, decided): see the table. Other auto-budget-made limits that overlap are taken over first (`take_over`).
- **Updating a budget** (`edit_immediate`): upstream's `BudgetRepository::update()` / `updateAutoBudget()` create no limit at v6.7.9. ext listens to Eloquent `created` (a budget that already existed gains an auto-budget) and `updated` (amount, type, period or currency changed) on `AutoBudget`. A change of period away from monthly changes nothing; upstream then makes limits for the new period on its own schedule.
- **Switching back from `CP` to a calendar range (decided):** in the owner's words, "handle it by cutting the cycle".
  - When the preferences form changes the view range from `CP` to anything else (`SaveExtPeriodPreference` sees `preferences.update`), the current cycle's auto-budget-made limit of each monthly auto-budget is cut to end on the **last day of the current calendar month** (`cut_on_leave`). Then the gap after a cycle that ended earlier is filled (`gap_fill`). Upstream's run on the next 1st creates a calendar limit that overlaps nothing.
  - Limits of past cycles stay as they are. Custom-period limits that end inside the current month are not changed.
  - **The daily job** (for every non-`CP` user) fixes leftovers idempotently, which covers a change made through the API:
    - with `cut_on_leave`, before upstream's job it cuts an auto-budget-made limit that contains the run date and runs past this month to the month's end;
    - with `cut_on_leave`, after upstream's job a `generated` non-calendar limit that runs into a calendar-month limit of the same budget is cut to end the day before that month;
    - then it fills the gap (`gap_fill`).
  - Users who never used `CP` have no such limits, so for them the job is exactly upstream's.

## Known limitations
- **Bills, piggy banks and recurring transactions** keep their own frequencies. This is deliberate.

## Acceptance
- The period vectors pass in PHP and JS.
- `ExtNavigation` behaves exactly like upstream for every non-`CP` key.
- **On a dev stack with anchor 25 and rule `previous`:**
  - the dashboard header shows "23 Oct – 24 Nov 2026" for 2026-10-27, because 25 Oct 2026 is a Sunday;
  - prev/next steps through 25 Sep – 22 Oct and 25 Nov – 24 Dec;
  - the budget page creates and reads limits for exactly those ranges;
  - reports and transaction lists use the same range.
- "Close current period" on a chosen date moves the boundary right away.
- **Auto-budgets** (`CustomPeriodAutoBudgetsTest`, anchor 25, rule `previous`): reset, rollover and adjusted over 25 Sep – 22 Oct, 23 Oct – 24 Nov (moved off Sunday 25 Oct) and 25 Nov – 24 Dec 2026; a second run creates nothing; no calendar-month limit is made; switching mid-month leaves no overlapping limits, takes over upstream's recorded first limit and never touches a limit the user set; a budget created on `CP` (web form and API) gets the cycle limit at once; changing an auto-budget on `CP` adjusts the cycle limit at once; switching back on 27 Oct cuts the cycle to 31 Oct and upstream's 1 Nov run overlaps nothing, also after a switch through the API; a non-`CP` user gets exactly what upstream's job gives.
- **Reports page** (`ReportCycleLinksTest`): a `CP` user sees cycle links grouped by year that open the default report for each cycle; a non-`CP` user sees no ext markup.
- **Auto-budget job options** (`AutoBudgetOptionsTest`): the `/ext/periods` section explains and saves every option, and other saves keep them; each option off behaves as in the table; `gap_fill` `pro_rata`, `full` and `none` after switching on 23 Oct, idempotent, also from the daily job after an API switch; pro rata rounds to the currency's decimals; upstream's first limit is recorded for every user and nothing else is; the backfill marks only limits beyond doubt, supports `--dry-run` and is idempotent; the page shows what the job last changed.
