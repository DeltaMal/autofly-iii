# CLAUDE.md: DeltaMal/autofly-iii (ext fork)

This repo is a fork of Firefly III. The default branch `ext` adds bank integrations, statement auto-recon and custom periods. **The spec is in [`ext/docs/`](ext/docs/README.md). Read `00-overview.md` before changing anything.**

Hard rules:
- **All custom work goes in `ext/`.** That covers PHP (namespace `FireflyIII\Ext\`), views, migrations, translations, tests, Docker files and docs. It is wired in through `FireflyIII\Ext\ExtServiceProvider`.
- **Upstream files:** only the single-line hooks listed in [`ext/UPSTREAM_TOUCHPOINTS.md`](ext/UPSTREAM_TOUCHPOINTS.md), marked `CUSTOM(ext)`. A new hook must be added to that file in the same commit.
  - Never reformat or refactor upstream code.
  - Never edit `resources/lang/*` (Crowdin owns it).
  - Never add Composer packages (`composer.lock` must stay identical to upstream).
- **Database:** only new `ext_*` tables, in `ext/database/migrations`. Never alter upstream tables.
- **Upstream merges:** only release tags, through `ext/scripts/merge-upstream.sh <tag>`.
- **No real financial data in commits:** this repo is public. Real samples live in the git-ignored `ext/tests/fixtures/private/`. Every value in a committed fixture or test must be synthetic: real samples go through `ext/scripts/anonymise_fnb_history.py` (dates shifted by the private offset in `private/anonymise-shift.txt`) or the owner's `export_golden.py`, and amounts, dates, times, merchants and references are never copied from `private/` or from real emails.

Running things:
- **PHP ≥ 8.5 and Composer** run in Docker or CI; the owner has no local PHP.
- **Tests:** `vendor/bin/phpunit -c ext/phpunit.xml` and `node --test ext/tests/js/`.
- **Dev stack:** `ext/docker/compose.dev.yml` (project `ff3-ext-dev`). Never touch the owner's production stack (compose project `ff3-email-importer`).
- **Git:** one PR per phase into `ext`. Commit messages are plain, with no attribution trailers.
