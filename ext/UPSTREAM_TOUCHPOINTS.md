# Upstream touchpoints

This lists every upstream file the ext fork changes. It is the merge checklist: after merging an upstream release, each hook below must still be present and work. Each hook is marked `CUSTOM(ext)` in the file. `ext/scripts/check-touchpoints.sh` checks the markers; `ext/scripts/merge-upstream.sh` and CI run it.

**Rule:** a change to an upstream file must be a single delegating hook. Add it to this table in the same commit.

| File | Hook | Marker | Phase | Status |
|---|---|---|---|---|
| `bootstrap/providers.php` | Register `FireflyIII\Ext\ExtServiceProvider` (after `FireflyServiceProvider`) | `// CUSTOM(ext)` | 0 | added |
| `composer.json` | `autoload.psr-4` `FireflyIII\\Ext\\` → `ext/src/`; `autoload-dev.psr-4` `FireflyIII\\Ext\\Tests\\` → `ext/tests/` | n/a (JSON has no comments; CI checks the keys) | 0 | added |
| `resources/views/components/layout/sidebar.blade.php` | `@includeIf('ext::partials.menu')` before the closing `</ul>` | `{{-- CUSTOM(ext) --}}` | 0 | added |
| `resources/views/preferences/index.blade.php` | `@includeIf('ext::preferences.view-range')` inside the view-range radios | `{{-- CUSTOM(ext) --}}` | 1 | planned |
| `resources/assets/v3/js/support/get-viewrange.js` | `case 'CP'` → `period-calendar.js` | `// CUSTOM(ext)` | 1 | planned |
| v3 boot file that loads `viewRange` (exact file found in Phase 1) | Also load the `extPeriod` preference | `// CUSTOM(ext)` | 1 | planned |
| `CLAUDE.md` (new file at the repo root; upstream has none at v6.7.9) | Points coding agents at `ext/docs/` | n/a | 0 | added |

New files placed in upstream folders (no conflict risk; list them so they aren't lost):

| File | Why |
|---|---|
| `.github/workflows/ext-ci.yml`, `.github/workflows/ext-image.yml` | Fork CI |
| `public/ext/**` | Static JS/CSS for ext pages |
| `resources/assets/v3/js/ext/period-calendar.js` (only if Vite can't import from `ext/`) | Custom period maths for the v3 frontend |
