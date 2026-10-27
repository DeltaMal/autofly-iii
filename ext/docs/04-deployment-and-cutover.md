# Build, deploy and cut over

## Docker image (Phase 0 deliverable)

- **Location:** `ext/docker/Dockerfile`, build context = the repo root.
- **Mirror the official image:** the official one is built in [firefly-iii/docker](https://github.com/firefly-iii/docker) on `fireflyiii/base` (PHP, web server, entrypoint, PHP extensions, app at `/var/www/html`). Read that repo's Dockerfile and entrypoint first and copy them; don't invent a new runtime.
- **Builder stage:** use the same base so the PHP version matches (upstream needs PHP ≥ 8.5).
  - `composer install --no-dev --prefer-dist --optimize-autoloader`. The ext autoload entries come from `composer.json`.
  - `npm ci && npm run build` for the v3 frontend, exactly as upstream builds it.
- **Runtime stage:**
  - `fireflyiii/base` + the built app.
  - Install `poppler-utils` with the base image's package manager.
  - The entrypoint must still run upstream's migrations and upgrade commands. Our migrations run with them, through `loadMigrationsFrom`.
- **Tags and publishing:** `.github/workflows/ext-image.yml` pushes to GHCR (`ghcr.io/deltamal/autofly-iii`):

  | Trigger | Tags pushed |
  |---|---|
  | Every push to `ext`, whatever paths changed | `ext` (moving) and `ext-<first 12 of the commit sha>` |
  | Tag `ext-v<upstream version>-ext.<n>`, e.g. `ext-v6.7.9-ext.1` | `6.7.9-ext.1` and `latest-ext` |
  | PR into `ext` touching image inputs (Docker files, composer/npm files, assets) | nothing: build only |

  Production pins a release tag (`6.7.9-ext.N`). The `ext` tags are for the dev stack and for trying a merged change before a release is cut.
- **CI:** `.github/workflows/ext-ci.yml` runs on PRs to `ext`:
  - `composer install` (dev);
  - `vendor/bin/phpunit -c ext/phpunit.xml`;
  - `node --test ext/tests/js/`;
  - a grep check that every `CUSTOM(ext)` marker listed in `UPSTREAM_TOUCHPOINTS.md` exists.
  - Upstream's own workflows are **disabled** in the fork's Actions settings, not deleted.

## Dev stack (`ext/docker/compose.dev.yml`, project name `ff3-ext-dev`)

- Services:
  - `firefly`: the built image, port 8090;
  - `db`: MariaDB LTS in a named volume `ff3-ext-dev_db`;
  - `ext-scheduler`: same image, `php artisan schedule:work`, same environment.
- Uses its own `.env` (`ext/docker/.env.dev`, git-ignored; ship an `.env.dev.example`).
- Sets `NGINX_LISTEN_IP_PROTOCOL=ipv4`, because cloud sandboxes have no IPv6 and nginx won't start listening on `[::]` there.
- **Never** points at the production database or volumes.
- Seed for manual testing:
  - an owner user;
  - asset accounts with numbers matching the anonymised fixtures (`62012345678`, and partials such as `100100` from the golden file);
  - a few email-style transactions.

## Merging upstream (`ext/scripts/merge-upstream.sh <tag>`)

1. Run `git fetch upstream --tags` and check that the tag exists.
2. `git checkout ext && git merge --no-ff <tag> -m "Merge upstream <tag>"`.
3. If there are conflicts:
   - they should only be in files listed in `UPSTREAM_TOUCHPOINTS.md`;
   - resolve by taking upstream's version and re-applying our one-line hook;
   - **never** resolve an upstream file by keeping our older copy of the upstream code.
4. Check that every `CUSTOM(ext)` marker is present.
5. Run the ext tests and upstream's unit tests, then build the image.
6. Commit, push and tag `ext-v<tag>-ext.1`.

Run it for every upstream release. **Never merge upstream `develop` or `main` between releases.**

## Production cut-over (owner, local machine; not the cloud agent)

The live instance runs from the Python repo's `docker-compose.yml`:
- compose project `ff3-email-importer`;
- services `firefly` (official `fireflyiii/core:version-6.7`), `firefly-db` (MariaDB, named volume), `importer` (FIDI), `cron`, and `email-importer` (Python, `--profile app`).

1. **Back up.** Run `docker compose exec firefly-db mariadb-dump …` to a file **outside** any repo, and check that it restores into a scratch container. **Never `docker compose down -v`.**
2. **Record the table list:** `SHOW TABLES` → file.
3. **Switch the image:** point `firefly` at `ghcr.io/deltamal/autofly-iii:6.7.9-ext.N`. Add `ext-scheduler` (same image and env, `php artisan schedule:work`). Then `docker compose up -d firefly ext-scheduler`.
4. **Check:** `SHOW TABLES` again. The only new tables must be `ext_*`, plus whatever upstream migrations add between the old 6.7.x and 6.7.9. Compare those with a plain `fireflyiii/core:version-6.7.9` on a scratch DB if unsure. Log in and test the dashboard, budgets and reports.
5. **Configure:**
   - Google client on `/ext/admin/google`.
   - Export the Python config with `uv run python scripts/export_config.py > secrets/ext-import.json` (Python repo; written in this phase).
   - `docker compose exec firefly php artisan ext:import-python-config /path/ext-import.json --user=<owner email>`. Mount the file read-only and delete it afterwards.
   - Mailboxes arrive in **shadow** mode.
6. **Shadow for 3 or more days** with the Python `email-importer` still running. Each day, check `/ext/mail`:
   - every shadow transaction is `shadow_exists` after Python's next run;
   - `shadow_would_create` rows that persist are bugs: compare the external ids;
   - **zero** unexplained rows is the go/no-go.
7. **Go live:**
   - `docker compose --profile app stop email-importer`;
   - switch the mailboxes to **live**;
   - watch one poll cycle.
8. **Retire, after a week clean:**
   - remove the `email-importer` and `importer` (FIDI) services;
   - archive the Python repo, keeping `scripts/export_golden.py` and its history;
   - move the compose file into the fork (`ext/docker/compose.prod.yml`) **keeping `name: ff3-email-importer`**, so the existing DB volume is reused.
9. **Rollback** (any step): set `firefly` back to the **official image at the same upstream version** (`fireflyiii/core:version-6.7.9`, not an older one, because upstream migrations already ran). Then restart the Python importer.
   - `ext_` tables are harmless to upstream, so leave them.
   - Restore the backup only if upstream data was damaged.

## AGPL-3.0
- Keep `LICENSE` and the upstream notices.
- ext files are AGPL-3.0-or-later and carry the upstream header style: "modified/added by … for the ext fork".
- **§13:** users interacting over a network must be offered the modified source. The ext menu partial adds a "Source (modified)" link to `https://github.com/DeltaMal/autofly-iii/tree/ext`. Don't change upstream's footer.
