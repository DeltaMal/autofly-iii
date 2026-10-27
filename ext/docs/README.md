# ext spec

These documents describe the "ext" fork of Firefly III: built-in bank integrations, statement auto-recon and custom periods. **Read in order.**

| Doc | What it covers |
|---|---|
| [00 Overview](00-overview.md) | Goals, decisions, **rules for staying mergeable**, touchpoints, layout, phases, shared building blocks, data model, testing |
| [01 Custom periods](01-custom-periods.md) | Phase 1: salary-cycle and manual periods through `ExtNavigation` |
| [02 Statements and auto-recon](02-statements-and-auto-recon.md) | Phase 2: FNB statement parser, matcher, review/commit, reconciliation |
| [03 Email import](03-email-import.md) | Phase 3: PHP port of the Python email importer, with Gmail OAuth inside Firefly |
| [04 Deployment and cut-over](04-deployment-and-cutover.md) | Docker image, CI, upstream merges, the production cut-over runbook, AGPL |
| [05 Upstream code map](05-upstream-firefly-reference.md) | Where things are in upstream v6.7.9 |
| [06 Python importer reference](06-python-importer-reference.md) | Exact behaviour being ported (regexes, ids, pipeline) |

Fixtures and generators: [`../tests/fixtures/README.md`](../tests/fixtures/README.md).

**Status:** spec agreed with the owner on 2026-10-27. Implementation runs in phase order, with one PR per phase into `ext`.
