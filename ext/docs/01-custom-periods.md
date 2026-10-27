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
  - has a **"Close current period"** form: date (default today) and optional name. It appends to `starts`, then clears session `start`/`end` so the new period takes effect at once.

### v3 frontend
- `ext/resources/assets/period-calendar.js`: an ES module, a line-for-line port of `PeriodCalendar` with no dependencies.
- Touchpoint `get-viewrange.js`: one `case 'CP':` in `getViewRange`, `addPeriod` and `subtractPeriod`, delegating to the module.
- The JS needs `extPeriod`. Load it next to where the boot code loads `viewRange` (likely `GET /api/v1/preferences/viewRange` in `js/boot/bootstrap.js`), via `GET /api/v1/preferences/extPeriod`, and store it the same way. That is the second touchpoint; keep it to a few lines.
- If Vite won't import from outside `resources/assets/v3`, put the module at `resources/assets/v3/js/ext/period-calendar.js`. That is a new file inside upstream's tree, but nobody else edits it. Have the JS tests import it from there.
- **JS tests:** `ext/tests/js/period-calendar.test.mjs` runs the same `period-vectors.json` with `node --test`.

## Known limitations (v1)
- **Auto-budgets** (`app/Support/Cronjobs/AutoBudgetCronjob` and friends) still create limits for **calendar** periods. Follow-up: an `ext` cron job that, for `CP` users, creates or adjusts auto-budget limits on custom boundaries.
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
