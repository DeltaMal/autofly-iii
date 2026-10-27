# ext test fixtures

**No real financial data is committed here.** This repo is public. Real samples go in `private/`, which is git-ignored, and tests that need them skip themselves when they're missing.

| Path | What it is | How it was made |
|---|---|---|
| `fnb-email/golden.json` | Inputs and expected outputs of the Python FNB email parser, external ids included. The PHP port must match 100%. | `scripts/export_golden.py` in the owner's private Python repo (`ff3_email_importer`). It runs the real parser over its `.eml` fixtures, the test-suite notification strings and extra edge cases. Every `..digits` reference and `Avail` balance is replaced by a stable fake value **before** parsing. It refuses to write if any known real value is left. |
| `fnb-statement/transaction-history.layout.txt` | `pdftotext -layout` (poppler 25.12) output of an FNB "Transaction History" PDF, **anonymised**. | `ext/scripts/anonymise_fnb_history.py <real.txt> <out_dir> <private_dir>`. It keeps the real layout quirks (shifting columns, wrapped descriptions, page headers and footers, non-monotonic dates). Personal names, places, references, the card number and the account number are replaced; amounts are scaled and the balance chain recalculated. |
| `fnb-statement/transaction-history.expected.json` | Expected parse of the layout above: header fields, opening/closing/available balance, sum, chain validity, every line. | Same script, using its reference `parse()`. |
| `period-vectors.json` | Custom-period test vectors (`periods[]`, `moves[]`). The PHP `PeriodCalendar` and JS `period-calendar.js` must both reproduce them. | `python3 ext/scripts/period_vectors.py > ext/tests/fixtures/period-vectors.json` (the reference implementation). |
| `private/` (git-ignored) | The real FNB PDF (`transaction-history.pdf`), its real expectation (`transaction-history.expected.json`), the real `pdftotext` dump. | Kept only on the owner's machine. |

To add a new statement layout: run `pdftotext -layout` on the real PDF inside a container, then run `anonymise_fnb_history.py` (extend it for the new layout). It moves every date by the number of days in `private/anonymise-shift.txt`, a private multiple of 7. Commit only its outputs, never the input.
