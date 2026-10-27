"""Reference implementation of ext custom periods; regenerates ext/tests/fixtures/period-vectors.json.

The PHP `PeriodCalendar` and the JS `period-calendar.js` must reproduce every vector.
Spec: ext/docs/01-custom-periods.md. Usage: python3 ext/scripts/period_vectors.py > ext/tests/fixtures/period-vectors.json
"""

from __future__ import annotations

import calendar
import json
from datetime import date, timedelta

MONTH_ABBR = calendar.month_abbr  # English; the PHP/JS label uses the user's locale via translations.
MONTH_NAME = calendar.month_name


def add_months(year: int, month: int, delta: int) -> tuple[int, int]:
    index = year * 12 + (month - 1) + delta
    return index // 12, index % 12 + 1


def clamp_day(year: int, month: int, day: int) -> date:
    return date(year, month, min(day, calendar.monthrange(year, month)[1]))


def shift_weekend(day: date, rule: str) -> date:
    step = {"previous": -1, "next": 1}.get(rule)
    while step and day.weekday() >= 5:
        day += timedelta(days=step)
    return day


def boundaries(settings: dict, around: date) -> list[date]:
    """All period starts within ±3 years of `around`, sorted."""

    explicit = sorted(date.fromisoformat(item["date"]) for item in settings.get("starts", []))
    lo, hi = around.year - 3, around.year + 3
    anchor = settings.get("anchor_day")
    if anchor is None and not explicit:
        anchor, settings = 1, {**settings, "business_day_rule": "none"}

    if anchor is not None:
        computed = [
            shift_weekend(clamp_day(y, m, anchor), settings.get("business_day_rule", "none"))
            for y in range(lo, hi + 1)
            for m in range(1, 13)
        ]
        replaced = set()
        for start in explicit:
            # nearest computed start; ties go to the later one
            nearest = min(computed, key=lambda c: (abs((c - start).days), -c.toordinal()))
            replaced.add(nearest)
        return sorted({*(c for c in computed if c not in replaced), *explicit})

    # manual mode: calendar months before the first explicit start, projection after the last
    first, last = explicit[0], explicit[-1]
    result = {date(y, m, 1) for y in range(lo, hi + 1) for m in range(1, 13) if date(y, m, 1) < first}
    result.update(explicit)
    if len(explicit) >= 2:
        length = (last - explicit[-2]).days
        step = last + timedelta(days=length)
        while step.year <= hi:
            result.add(step)
            step += timedelta(days=length)
    else:
        k = 1
        while True:
            y, m = add_months(last.year, last.month, k)
            if y > hi:
                break
            result.add(clamp_day(y, m, last.day))
            k += 1
    return sorted(result)


def period_for(settings: dict, day: date) -> tuple[date, date]:
    starts = boundaries(settings, day)
    start = max(s for s in starts if s <= day)
    nxt = min(s for s in starts if s > day)
    return start, nxt - timedelta(days=1)


def label(settings: dict, start: date, end: date) -> str:
    for item in settings.get("starts", []):
        if item.get("name") and date.fromisoformat(item["date"]) == start:
            return item["name"]
    style = settings.get("label", "range")
    if style == "start_month":
        return f"{MONTH_NAME[start.month]} {start.year}"
    if style == "end_month":
        return f"{MONTH_NAME[end.month]} {end.year}"
    if start.year == end.year:
        return f"{start.day} {MONTH_ABBR[start.month]} – {end.day} {MONTH_ABBR[end.month]} {end.year}"
    return (
        f"{start.day} {MONTH_ABBR[start.month]} {start.year} – "
        f"{end.day} {MONTH_ABBR[end.month]} {end.year}"
    )


def add_period(settings: dict, day: date, skip: int, direction: int) -> date:
    """Move `skip + 1` periods forward (direction 1) or back (-1), keeping the day offset (clamped)."""

    start, _ = period_for(settings, day)
    offset = (day - start).days
    target = start
    for _ in range(skip + 1):
        if direction > 0:
            target = period_for(settings, target)[1] + timedelta(days=1)
        else:
            target = period_for(settings, target - timedelta(days=1))[0]
    target_end = period_for(settings, target)[1]
    return min(target + timedelta(days=offset), target_end)


SALARY = {"anchor_day": 25, "business_day_rule": "none", "label": "range", "starts": []}
SALARY_PREV = {**SALARY, "business_day_rule": "previous"}
SALARY_NEXT = {**SALARY, "business_day_rule": "next"}
MONTH_END = {"anchor_day": 31, "business_day_rule": "none", "label": "end_month", "starts": []}
EARLY = {**SALARY, "starts": [{"date": "2026-10-23", "name": "Oct salary (early)"}]}
SPLIT = {**SALARY, "starts": [{"date": "2026-10-20"}, {"date": "2026-10-28"}]}
MANUAL = {
    "anchor_day": None,
    "business_day_rule": "none",
    "label": "range",
    "starts": [{"date": "2026-01-10"}, {"date": "2026-02-20"}, {"date": "2026-03-15"}],
}
MANUAL_ONE = {**MANUAL, "starts": [{"date": "2026-01-31"}]}
EMPTY_MANUAL = {"anchor_day": None, "business_day_rule": "none", "label": "start_month", "starts": []}

CASES = [
    ("salary 25th, after anchor", SALARY, "2026-10-27"),
    ("salary 25th, day before anchor", SALARY, "2026-10-24"),
    ("salary 25th, on anchor", SALARY, "2026-10-25"),
    ("salary 25th, across year end", SALARY, "2027-01-03"),
    ("salary 25th, previous business day", SALARY_PREV, "2026-10-24"),
    ("salary 25th, previous business day (2027-09)", SALARY_PREV, "2027-09-24"),
    ("salary 25th, next business day", SALARY_NEXT, "2026-10-25"),
    ("month end anchor 31 in February", MONTH_END, "2027-02-15"),
    ("month end anchor 31 in leap February", MONTH_END, "2028-02-29"),
    ("explicit early start replaces computed 25th", EARLY, "2026-10-23"),
    ("period before the early start", EARLY, "2026-10-22"),
    ("two explicit starts near one computed start split", SPLIT, "2026-10-25"),
    ("two explicit starts, first part", SPLIT, "2026-10-21"),
    ("manual: before first start uses calendar month", MANUAL, "2026-01-05"),
    ("manual: between explicit starts", MANUAL, "2026-02-25"),
    ("manual: projected after last start", MANUAL, "2026-04-01"),
    ("manual: projected twice", MANUAL, "2026-04-10"),
    ("manual single start: monthly anniversaries (clamped)", MANUAL_ONE, "2026-03-05"),
    ("manual with no starts: calendar months", EMPTY_MANUAL, "2026-10-27"),
]
MOVES = [
    ("next from a period start", SALARY, "2026-10-25", 0, 1),
    ("next keeps day offset", SALARY, "2026-10-30", 0, 1),
    ("skip one period", SALARY, "2026-10-25", 1, 1),
    ("previous from a period start", SALARY, "2026-10-25", 0, -1),
    ("previous across year end", SALARY, "2027-01-03", 0, -1),
    ("next clamps offset to a shorter period", EARLY, "2026-09-24", 0, 1),
]


def main() -> None:
    def iso(value: date) -> str:
        return value.isoformat()

    periods = []
    for name, settings, day in CASES:
        start, end = period_for(settings, date.fromisoformat(day))
        prev_start, prev_end = period_for(settings, start - timedelta(days=1))
        next_start, next_end = period_for(settings, end + timedelta(days=1))
        periods.append(
            {
                "name": name,
                "settings": settings,
                "date": day,
                "start": iso(start),
                "end": iso(end),
                "label": label(settings, start, end),
                "previous": [iso(prev_start), iso(prev_end)],
                "next": [iso(next_start), iso(next_end)],
            }
        )
    moves = [
        {
            "name": name,
            "settings": settings,
            "date": day,
            "skip": skip,
            "direction": "add" if direction > 0 else "subtract",
            "expected": iso(add_period(settings, date.fromisoformat(day), skip, direction)),
        }
        for name, settings, day, skip, direction in MOVES
    ]
    print(json.dumps({"periods": periods, "moves": moves}, indent=2, ensure_ascii=False))


if __name__ == "__main__":
    main()
