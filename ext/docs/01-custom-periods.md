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
- These are upstream mechanisms working through `ExtNavigation`; the follow-up check (`CustomPeriodRangeTest`) found no page that needed a fix. The report page's quick links (years, quarters, months) stay calendar presets; its date fields default to the cycle.

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
- **Switching to `CP` mid-month:** on the first run after the switch, calendar-month limits that upstream made for this auto-budget (cron-generated, or the first limit made when the budget was created, which has the auto-budget amount) and that overlap the current custom period are cut back to end the day before it starts, or removed when they start inside it. Then the custom-period limit is created. Limits the user set by hand are never changed: if one overlaps, no limit is created for that period (logged), so limits never overlap.
- **Switching back** from `CP` to a calendar range: upstream's cron takes over from its next magic day (the 1st). Custom-period limits already made stay as they are.

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
- **Auto-budgets** (`CustomPeriodAutoBudgetsTest`, anchor 25, rule `previous`): reset, rollover and adjusted over 25 Sep – 22 Oct, 23 Oct – 24 Nov (moved off Sunday 25 Oct) and 25 Nov – 24 Dec 2026; a second run creates nothing; no calendar-month limit is made; switching mid-month leaves no overlapping limits; a non-`CP` user gets exactly what upstream's job gives.
