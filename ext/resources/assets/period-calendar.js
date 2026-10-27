/*
 * period-calendar.js
 * Copyright (c) 2026 the ext fork authors. Added for the ext fork of Firefly III
 * (https://github.com/DeltaMal/autofly-iii), based on Firefly III by james@firefly-iii.org.
 *
 * This file is part of Firefly III (https://github.com/firefly-iii).
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * Custom period maths for the v3 frontend: a line-for-line port of ext/src/Periods/PeriodCalendar.php.
 * No dependencies. Dates go in and out as local JS Date objects; internally every date is a day
 * number (days since 1970-01-01), so time zones and DST never matter.
 * Must reproduce ext/tests/fixtures/period-vectors.json (ext/tests/js/period-calendar.test.mjs).
 */

const MARGIN_YEARS = 3;

export function days(year, month, day) {
    year -= month <= 2 ? 1 : 0;
    const era = Math.floor((year >= 0 ? year : year - 399) / 400);
    const yoe = year - era * 400;
    const doy = Math.floor((153 * (month + (month > 2 ? -3 : 9)) + 2) / 5) + day - 1;
    const doe = yoe * 365 + Math.floor(yoe / 4) - Math.floor(yoe / 100) + doy;
    return era * 146097 + doe - 719468;
}

export function civil(n) {
    n += 719468;
    const era = Math.floor((n >= 0 ? n : n - 146096) / 146097);
    const doe = n - era * 146097;
    const yoe = Math.floor((doe - Math.floor(doe / 1460) + Math.floor(doe / 36524) - Math.floor(doe / 146096)) / 365);
    const doy = doe - (365 * yoe + Math.floor(yoe / 4) - Math.floor(yoe / 100));
    const mp = Math.floor((5 * doy + 2) / 153);
    const day = doy - Math.floor((153 * mp + 2) / 5) + 1;
    const month = mp + (mp < 10 ? 3 : -9);
    return [yoe + era * 400 + (month <= 2 ? 1 : 0), month, day];
}

export function parseDay(text) {
    return days(Number(text.slice(0, 4)), Number(text.slice(5, 7)), Number(text.slice(8, 10)));
}

export function formatDay(n) {
    const [y, m, d] = civil(n);
    return `${String(y).padStart(4, '0')}-${String(m).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
}

function daysInMonth(year, month) {
    const leap = (year % 4 === 0 && year % 100 !== 0) || year % 400 === 0;
    return [31, leap ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31][month - 1];
}

function clampDay(year, month, day) {
    return days(year, month, Math.min(day, daysInMonth(year, month)));
}

/** Monday = 0 … Sunday = 6 (1970-01-01 was a Thursday). */
function weekday(n) {
    return (((n % 7) + 7 + 3) % 7);
}

function shiftWeekend(n, rule) {
    const step = { previous: -1, next: 1 }[rule] ?? 0;
    while (step !== 0 && weekday(n) >= 5) {
        n += step;
    }
    return n;
}

function uniqueSorted(list) {
    return [...new Set(list)].sort((a, b) => a - b);
}

/** Same leniency as PeriodSettings::fromArray(). */
export function normaliseSettings(data) {
    if (data === null || typeof data !== 'object' || Array.isArray(data)) {
        data = {};
    }
    let anchor = Object.prototype.hasOwnProperty.call(data, 'anchor_day') ? data.anchor_day : 25;
    anchor = anchor === null || anchor === '' ? null : parseInt(anchor, 10);
    if (anchor !== null && (Number.isNaN(anchor) || anchor < 1 || anchor > 31)) {
        anchor = 25;
    }
    const rule = ['none', 'previous', 'next'].includes(data.business_day_rule) ? data.business_day_rule : 'none';
    const label = ['range', 'start_month', 'end_month'].includes(data.label) ? data.label : 'range';
    const starts = [];
    for (const item of Array.isArray(data.starts) ? data.starts : []) {
        const date = item && typeof item === 'object' ? String(item.date ?? '') : '';
        if (!/^\d{4}-\d{2}-\d{2}$/.test(date) || formatDay(parseDay(date)) !== date) {
            continue;
        }
        const name = item.name !== undefined && item.name !== null && String(item.name).trim() !== '' ? String(item.name).trim() : null;
        starts.push(name === null ? { date } : { date, name });
    }
    return { anchor_day: anchor, business_day_rule: rule, label, starts };
}

export class PeriodCalendar {
    constructor(settings) {
        this.settings = normaliseSettings(settings);
        this.names = new Map();
        const explicit = [];
        for (const item of this.settings.starts) {
            const n = parseDay(item.date);
            explicit.push(n);
            if (item.name && !this.names.has(n)) {
                this.names.set(n, item.name);
            }
        }
        this.explicit = uniqueSorted(explicit);
        this.boundaryList = [];
        this.lo = null;
        this.hi = null;
    }

    /** [start, end] of the period containing `date`; start at 00:00:00, end at 23:59:59 (local). */
    periodFor(date) {
        const [start, end] = this.periodDays(dayOf(date));
        const s = toDate(start, date);
        s.setHours(0, 0, 0, 0);
        const e = toDate(end, date);
        e.setHours(23, 59, 59, 0);
        return [s, e];
    }

    startOf(date) {
        return this.periodFor(date)[0];
    }

    endOf(date) {
        return this.periodFor(date)[1];
    }

    add(date, skip = 0) {
        return toDate(this.move(dayOf(date), skip, 1), date);
    }

    subtract(date, skip = 0) {
        return toDate(this.move(dayOf(date), skip, -1), date);
    }

    /** English label, like PeriodCalendar::label() with the default formats. */
    label(start, end) {
        const startDay = dayOf(start);
        if (this.names.has(startDay)) {
            return this.names.get(startDay);
        }
        const abbr = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        const full = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
        if (this.settings.label === 'start_month') {
            return `${full[start.getMonth()]} ${start.getFullYear()}`;
        }
        if (this.settings.label === 'end_month') {
            return `${full[end.getMonth()]} ${end.getFullYear()}`;
        }
        const endText = `${end.getDate()} ${abbr[end.getMonth()]} ${end.getFullYear()}`;
        const startText = start.getFullYear() === end.getFullYear()
            ? `${start.getDate()} ${abbr[start.getMonth()]}`
            : `${start.getDate()} ${abbr[start.getMonth()]} ${start.getFullYear()}`;
        return `${startText} – ${endText}`;
    }

    boundaries(date) {
        this.cover(dayOf(date));
        return this.boundaryList.map(formatDay);
    }

    periodDays(n) {
        this.cover(n);
        const index = this.indexAtOrBefore(n);
        return [this.boundaryList[index], this.boundaryList[index + 1] - 1];
    }

    move(n, skip, direction) {
        const [start] = this.periodDays(n);
        const offset = n - start;
        let target = start;
        for (let i = 0; i < skip + 1; i++) {
            target = direction > 0 ? this.periodDays(target)[1] + 1 : this.periodDays(target - 1)[0];
        }
        return Math.min(target + offset, this.periodDays(target)[1]);
    }

    cover(n) {
        const year = civil(n)[0];
        if (this.lo === null || year - 1 < this.lo || year + 1 > this.hi) {
            this.compute(Math.min(this.lo ?? Infinity, year - MARGIN_YEARS), Math.max(this.hi ?? -Infinity, year + MARGIN_YEARS));
        }
        for (let attempt = 0; attempt < 50; attempt++) {
            const count = this.boundaryList.length;
            if (count > 0 && this.boundaryList[0] <= n && this.boundaryList[count - 1] > n) {
                return;
            }
            this.compute(this.lo - MARGIN_YEARS, this.hi + MARGIN_YEARS);
        }
        throw new Error(`No custom period contains ${formatDay(n)}.`);
    }

    compute(lo, hi) {
        this.lo = lo;
        this.hi = hi;
        this.boundaryList = this.settings.anchor_day !== null || this.explicit.length === 0 ? this.anchored(lo, hi) : this.manual(lo, hi);
    }

    anchored(lo, hi) {
        const anchor = this.settings.anchor_day ?? 1;
        const rule = this.settings.anchor_day === null ? 'none' : this.settings.business_day_rule;
        if (this.explicit.length > 0) {
            lo = Math.min(lo, civil(this.explicit[0])[0] - 1);
            hi = Math.max(hi, civil(this.explicit[this.explicit.length - 1])[0] + 1);
        }
        const computed = [];
        for (let year = lo; year <= hi; year++) {
            for (let month = 1; month <= 12; month++) {
                computed.push(shiftWeekend(clampDay(year, month, anchor), rule));
            }
        }
        const replaced = new Set();
        for (const start of this.explicit) {
            // nearest computed start; a tie goes to the later one
            let nearest = null;
            for (const candidate of computed) {
                if (nearest === null
                    || Math.abs(candidate - start) < Math.abs(nearest - start)
                    || (Math.abs(candidate - start) === Math.abs(nearest - start) && candidate > nearest)) {
                    nearest = candidate;
                }
            }
            replaced.add(nearest);
        }
        return uniqueSorted([...this.explicit, ...computed.filter((candidate) => !replaced.has(candidate))]);
    }

    manual(lo, hi) {
        const first = this.explicit[0];
        const last = this.explicit[this.explicit.length - 1];
        const result = [];
        for (let year = lo; year <= hi; year++) {
            for (let month = 1; month <= 12; month++) {
                const n = days(year, month, 1);
                if (n < first) {
                    result.push(n);
                }
            }
        }
        result.push(...this.explicit);
        if (this.explicit.length >= 2) {
            const length = last - this.explicit[this.explicit.length - 2];
            for (let step = last + length; civil(step)[0] <= hi; step += length) {
                result.push(step);
            }
        } else {
            const [year, month, dayOfMonth] = civil(last);
            for (let k = 1; ; k++) {
                const index = year * 12 + (month - 1) + k;
                const y = Math.floor(index / 12);
                if (y > hi) {
                    break;
                }
                result.push(clampDay(y, (index % 12) + 1, dayOfMonth));
            }
        }
        return uniqueSorted(result);
    }

    indexAtOrBefore(n) {
        let low = 0;
        let high = this.boundaryList.length - 1;
        while (low < high) {
            const mid = Math.floor((low + high + 1) / 2);
            if (this.boundaryList[mid] <= n) {
                low = mid;
            } else {
                high = mid - 1;
            }
        }
        return low;
    }
}

function dayOf(date) {
    return days(date.getFullYear(), date.getMonth() + 1, date.getDate());
}

/** A copy of `template` (time of day kept) on day number `n`. */
function toDate(n, template) {
    const [y, m, d] = civil(n);
    const result = new Date(template.getTime());
    result.setFullYear(y, m - 1, d);
    return result;
}

/** Convenience used by get-viewrange.js: `settings` is the extPeriod preference (or null). */
export function customPeriod(settings, date) {
    return new PeriodCalendar(settings).periodFor(date);
}

/*
 * Adapters for the v3 touchpoints (resources/assets/v3/js/support/get-viewrange.js). They read the
 * extPeriod preference that boot/bootstrap.js loads into window.store, and return { start, end } like
 * upstream's getViewRange / addPeriod / subtractPeriod do.
 */
function storedSettings() {
    return typeof window !== 'undefined' && window.store ? window.store.get('extPeriod') ?? null : null;
}

export function extViewRange(today, settings = storedSettings()) {
    const [start, end] = new PeriodCalendar(settings).periodFor(today);
    return { start, end };
}

export function extAddPeriod(date, settings = storedSettings()) {
    const calendar = new PeriodCalendar(settings);
    const next = new Date(calendar.endOf(date).getTime());
    next.setDate(next.getDate() + 1);
    const [start, end] = calendar.periodFor(next);
    return { start, end };
}

export function extSubtractPeriod(date, settings = storedSettings()) {
    const calendar = new PeriodCalendar(settings);
    const previous = new Date(calendar.startOf(date).getTime());
    previous.setDate(previous.getDate() - 1);
    const [start, end] = calendar.periodFor(previous);
    return { start, end };
}
