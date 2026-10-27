"""Reference-parse an FNB "Transaction History" pdftotext -layout dump and build an anonymised twin.

Usage: python3 ext/scripts/anonymise_fnb_history.py <real.txt> <out_dir> <private_dir>
Writes <out_dir>/transaction-history.layout.txt + .expected.json (anonymised, committable) and
<private_dir>/transaction-history.expected.json (real values, never committed).

All dates move by the number of days in <private_dir>/anonymise-shift.txt: a multiple of 7, so
weekdays stay the same. Keep that number private; it is what keeps the real dates unknown.
"""

from __future__ import annotations

import hashlib
import json
import re
import sys
from datetime import date, timedelta
from decimal import Decimal
from pathlib import Path

ROW = re.compile(
    r"^(?P<date>\d{2} [A-Z][a-z]{2} \d{4})(?P<g1> +)(?P<desc>\S.*?)(?P<g2> +)"
    r"(?P<fee>-?\d[\d,]*\.\d{2})(?P<g3> +)(?P<amount>-?\d[\d,]*\.\d{2} (?:DR|CR))(?P<g4> +)"
    r"(?P<balance>-?\d[\d,]*\.\d{2} (?:CR|DR))\s*$"
)
CARD = re.compile(r"\d{6}\*{6}\d{4}")
FOOTER = re.compile(r"^(Printed |info@|Address:|Tel:)")
HEADER = re.compile(r"^\f?Date\s+Description\s+Service Fee\s+Amount\s+Balance")
FIELD = re.compile(r"^(?P<label>Selected Account|Date|Available Balance|Current Balance):(?P<gap> +)(?P<value>.+?)\s*$")
PRINTED = re.compile(r"^Printed (?P<date>\d{4}-\d{2}-\d{2})")
NICKNAME = re.compile(r"^(?P<label>Nickname: +)(?P<value>.+?)\s*$")
MONTHS = {m: i for i, m in enumerate("Jan Feb Mar Apr May Jun Jul Aug Sep Oct Nov Dec".split(), 1)}
MONTH_NAMES = {i: m for m, i in MONTHS.items()}

KEEP = {
    "PURCH", "FNB", "APP", "TRANSFER", "PAYMENT", "FROM", "INTERNET", "TRF", "#MONTHLY", "ACCOUNT",
    "FEE", "PnP", "PICK", "N", "PAY", "SASOL", "ENGEN", "Vodacom", "Bundles", "Checkers", "Dischem",
    "Dis-Chem", "CLICKS", "MCD", "Yoco", "Total", "CREDITCARDSETTLE", "Current",
    "Account",
}
SYLLABLES = "ka lo mi ne ru sa te vo bi da fe go hu ji ko lu ma no pi re si tu wa ye zo".split()


def money(text: str) -> Decimal:
    value = Decimal(text.split()[0].replace(",", ""))
    return -abs(value) if text.endswith("DR") else abs(value)


def fmt(value: Decimal, balance: bool) -> str:
    suffix = "CR" if value >= 0 else "DR"
    body = f"{abs(value):,.2f}"
    return f"{body} {suffix}" if balance else f"{'-' if value < 0 else ''}{body} {suffix}"


def iso(date: str) -> str:
    day, month, year = date.split()
    return f"{year}-{MONTHS[month]:02d}-{int(day):02d}"


def shift(text: str, days: int) -> str:
    """Move a "26 Oct 2026" date by `days`, keeping the format (and so the column widths)."""

    day, month, year = text.split()
    moved = date(int(year), MONTHS[month], int(day)) + timedelta(days=days)
    return f"{moved.day:02d} {MONTH_NAMES[moved.month]} {moved.year}"


def parse(text: str) -> dict:
    fields: dict[str, str] = {}
    rows: list[dict] = []
    current: dict | None = None
    for line in text.splitlines():
        if field := FIELD.match(line):
            fields.setdefault(field["label"], field["value"])
            continue
        if row := ROW.match(line):
            current = {"match": row, "continuation": []}
            rows.append(current)
        elif current and line.strip() and line.startswith(" ") and not FOOTER.match(line.strip()):
            current["continuation"].append(line.strip())
        elif not line.strip() or FOOTER.match(line) or HEADER.match(line):
            current = None
    lines = []
    for index, row in enumerate(rows):
        match = row["match"]
        description = " ".join([match["desc"].strip(), *row["continuation"]])
        card = CARD.search(description)
        lines.append({
            "posting_index": index,
            "date": iso(match["date"]),
            "description": description,
            "fee": str(Decimal(match["fee"].replace(",", ""))),
            "amount": str(money(match["amount"])),
            "balance": str(money(match["balance"])),
            "card_ref": card.group()[-4:] if card else None,
        })
    amounts = [Decimal(line["amount"]) for line in lines]
    balances = [Decimal(line["balance"]) for line in lines]
    chain = all(balances[i] == balances[i + 1] + amounts[i] for i in range(len(lines) - 1))
    return {
        "account_number": fields["Selected Account"],
        "statement_date": iso(fields["Date"]),
        "available_balance": str(money(fields["Available Balance"])),
        "closing_balance": str(balances[0]),
        "opening_balance": str(balances[-1] - amounts[-1]),
        "sum_of_amounts": str(sum(amounts)),
        "line_count": len(lines),
        "chain_valid": chain,
        "lines": lines,
    }


def fake_token(token: str) -> str:
    if token in KEEP or CARD.fullmatch(token):
        return "400000******1234" if CARD.fullmatch(token) else token
    seed = int(hashlib.sha256(token.encode()).hexdigest(), 16)
    letters = "".join(SYLLABLES[(seed >> (5 * i)) % len(SYLLABLES)] for i in range(len(token)))
    out, li = [], 0
    for char in token:
        if char.isalpha():
            new = letters[li]
            li += 1
            out.append(new.upper() if char.isupper() else new)
        elif char.isdigit():
            out.append(str((int(char) + seed) % 10))
        else:
            out.append(char)
    return "".join(out)


def fake_text(text: str) -> str:
    return re.sub(r"\S+", lambda m: fake_token(m.group()), text)


def place(line: str, start: int, end: int, new: str) -> str:
    """Put `new` right-aligned so it ends where the old token ended."""

    new_start = max(end - len(new), 0)
    left = line[:min(start, new_start)].rstrip(" ")
    gap = max(new_start - len(left), 1)
    return f"{left}{' ' * gap}{new}{line[end:]}"


def anonymise(text: str, closing: Decimal, pending: Decimal, days: int) -> str:
    real = parse(text)
    factors = []
    for line in real["lines"]:
        seed = int(hashlib.sha256(line["description"].encode()).hexdigest(), 16)
        factor = 55 + seed % 91
        factors.append(Decimal(101 if factor == 100 else factor) / 100)  # never keep a real amount
    amounts = [(Decimal(line["amount"]) * f).quantize(Decimal("0.01")) for line, f in zip(real["lines"], factors)]
    balances = [closing]
    for amount in amounts[:-1]:
        balances.append(balances[-1] - amount)

    out, index = [], 0
    for line in text.splitlines():
        if field := FIELD.match(line):
            label, value = field["label"], field["value"]
            new = shift(value, days) if label == "Date" else {
                "Selected Account": "62012345678",
                "Available Balance": fmt(closing - pending, True),
                "Current Balance": fmt(closing, True),
            }[label]
            out.append(line[: field.start("value")] + new)
            continue
        if nickname := NICKNAME.match(line):
            out.append(nickname["label"] + "FNB")
            continue
        if printed := PRINTED.match(line):
            moved = date.fromisoformat(printed["date"]) + timedelta(days=days)
            out.append(f"Printed {moved.isoformat()}{line[printed.end():]}")
            continue
        if row := ROW.match(line):
            line = shift(row["date"], days) + line[row.end("date"):]
            row = ROW.match(line)
            desc = row["desc"]
            fake_desc = fake_text(desc)
            line = line[: row.start("desc")] + fake_desc + line[row.end("desc"):]
            # amount and balance keep their right edges
            row2 = ROW.match(line)
            line = place(line, row2.start("amount"), row2.end("amount"), fmt(amounts[index], False))
            row3 = ROW.match(line)
            line = place(line, row3.start("balance"), row3.end("balance"), fmt(balances[index], True))
            index += 1
            out.append(line)
            continue
        if line.startswith(" ") and line.strip() and not FOOTER.match(line.strip()):
            indent = len(line) - len(line.lstrip(" "))
            out.append(" " * indent + fake_text(line.strip()))
            continue
        out.append(line)
    return "\n".join(out) + "\n"


def main() -> None:
    real_path, out_dir, private_dir = (Path(arg) for arg in sys.argv[1:4])
    text = real_path.read_text(encoding="utf-8")
    text = text[text.index(".\n") :] if not text.startswith(".") else text  # drop tool banner
    real = parse(text)
    private_dir.mkdir(parents=True, exist_ok=True)
    (private_dir / "transaction-history.expected.json").write_text(json.dumps(real, indent=2) + "\n", encoding="utf-8", newline="\n")
    print("real:", {k: real[k] for k in ("line_count", "chain_valid", "opening_balance", "closing_balance", "sum_of_amounts", "available_balance")})

    days = int((private_dir / "anonymise-shift.txt").read_text(encoding="utf-8"))
    if days % 7:
        sys.exit("anonymise-shift.txt must hold a multiple of 7")
    fake_text_out = anonymise(text, Decimal("12480.55"), Decimal("512.40"), days)
    fake = parse(fake_text_out)
    out_dir.mkdir(parents=True, exist_ok=True)
    (out_dir / "transaction-history.layout.txt").write_text(fake_text_out, encoding="utf-8", newline="\n")
    (out_dir / "transaction-history.expected.json").write_text(json.dumps(fake, indent=2) + "\n", encoding="utf-8", newline="\n")
    print("fake:", {k: fake[k] for k in ("line_count", "chain_valid", "opening_balance", "closing_balance", "sum_of_amounts", "available_balance")})


if __name__ == "__main__":
    main()
