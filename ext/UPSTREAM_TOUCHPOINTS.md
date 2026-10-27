# Upstream touchpoints

This lists every upstream file the ext fork changes. It is the merge checklist: after merging an upstream release, each hook below must still be present and work. Each hook is marked `CUSTOM(ext)` in the file. `ext/scripts/check-touchpoints.sh` checks the markers; `ext/scripts/merge-upstream.sh` and CI run it.

**Rule:** a change to an upstream file must be a single delegating hook. Add it to this table in the same commit.

| File | Hook | Marker | Phase | Status |
|---|---|---|---|---|
| `bootstrap/providers.php` | Register `FireflyIII\Ext\ExtServiceProvider` (after `FireflyServiceProvider`) | `// CUSTOM(ext)` | 0 | added |
| `composer.json` | `autoload.psr-4` `FireflyIII\\Ext\\` → `ext/src/`; `autoload-dev.psr-4` `FireflyIII\\Ext\\Tests\\` → `ext/tests/` | n/a (JSON has no comments; CI checks the keys) | 0 | added |
| `resources/views/components/layout/sidebar.blade.php` | `@includeIf('ext::partials.menu')` before the closing `</ul>` | `{{-- CUSTOM(ext) --}}` | 0 | added |
| `resources/views/preferences/index.blade.php` | `@includeIf('ext::preferences.view-range')` after the last view-range radio | `{{-- CUSTOM(ext) --}}` | 1 | added |
| `resources/assets/v3/js/support/get-viewrange.js` | One `import` of `ext/resources/assets/period-calendar.js` plus one `case "CP": return ext…(…);` line in each of `getViewRange`, `addPeriod`, `subtractPeriod` | `// CUSTOM(ext)` | 1 | added |
| `resources/assets/v3/js/boot/bootstrap.js` | `"extPeriod",` added to the `getVariables([...])` list, which also puts it in `window.store` | `// CUSTOM(ext)` | 1 | added |
| `app/Http/Controllers/Bill/IndexController.php` | In `amountPerPeriod()`: one line adding a `CP` divisor from `ExtNavigation::periodsPerYear()` (the `$division` map has no `CP` key, so the bills page would crash) | `// CUSTOM(ext)` | 1 | added |
| `CLAUDE.md` (new file at the repo root; upstream has none at v6.7.9) | Points coding agents at `ext/docs/` | n/a | 0 | added |

New files placed in upstream folders (no conflict risk; list them so they aren't lost):

| File | Why |
|---|---|
| `.github/workflows/ext-ci.yml`, `.github/workflows/ext-image.yml` | Fork CI |
| `public/ext/**` | Static JS/CSS for ext pages |
