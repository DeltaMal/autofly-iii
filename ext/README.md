# ext: bank integrations for Firefly III

This branch (`ext`) is a fork of [Firefly III](https://github.com/firefly-iii/firefly-iii) that adds:

- **Statement import with auto-recon:** upload a bank statement; transactions the email alerts missed (fees, interest, debit orders) are imported, matched transactions are reconciled, and remaining differences are explained.
- **Built-in email import:** bank alert emails (Gmail) become transactions. It is configured in the UI.
- **Custom periods:** salary-cycle view ranges (e.g. the 25th to the 24th) with manual period closes.

Everything custom lives in this `ext/` folder and hooks into upstream through a single service provider. The few upstream files we touch are listed in [UPSTREAM_TOUCHPOINTS.md](UPSTREAM_TOUCHPOINTS.md). Upstream releases are merged in with `ext/scripts/merge-upstream.sh <tag>`.

Start with the spec: [docs/README.md](docs/README.md).

Licence: AGPL-3.0-or-later, the same as upstream. Upstream copyright notices are kept.
