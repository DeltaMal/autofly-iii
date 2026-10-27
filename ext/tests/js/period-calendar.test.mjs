/*
 * period-calendar.test.mjs
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

// Runs ext/tests/fixtures/period-vectors.json against the JS PeriodCalendar (node --test, no dependencies).
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import { PeriodCalendar, formatDay, days, civil, parseDay } from '../../resources/assets/period-calendar.js';

const here = dirname(fileURLToPath(import.meta.url));
const vectors = JSON.parse(readFileSync(join(here, '..', 'fixtures', 'period-vectors.json'), 'utf8'));

function local(text) {
    return new Date(Number(text.slice(0, 4)), Number(text.slice(5, 7)) - 1, Number(text.slice(8, 10)), 12, 0, 0);
}

function iso(date) {
    return formatDay(days(date.getFullYear(), date.getMonth() + 1, date.getDate()));
}

for (const vector of vectors.periods) {
    test(`period: ${vector.name}`, () => {
        const calendar = new PeriodCalendar(vector.settings);
        const [start, end] = calendar.periodFor(local(vector.date));
        assert.deepEqual([iso(start), iso(end)], [vector.start, vector.end]);
        assert.equal(start.getHours(), 0);
        assert.equal(end.getHours(), 23);
        assert.equal(end.getMinutes(), 59);
        assert.equal(calendar.label(start, end), vector.label);

        const previous = calendar.periodFor(new Date(start.getFullYear(), start.getMonth(), start.getDate() - 1, 12));
        assert.deepEqual(previous.map(iso), vector.previous);
        const next = calendar.periodFor(new Date(end.getFullYear(), end.getMonth(), end.getDate() + 1, 12));
        assert.deepEqual(next.map(iso), vector.next);
    });
}

for (const vector of vectors.moves) {
    test(`move: ${vector.name}`, () => {
        const calendar = new PeriodCalendar(vector.settings);
        const date = local(vector.date);
        const moved = vector.direction === 'add' ? calendar.add(date, vector.skip) : calendar.subtract(date, vector.skip);
        assert.equal(iso(moved), vector.expected);
        assert.equal(moved.getHours(), 12, 'time of day is kept');
    });
}

test('day numbers round-trip', () => {
    for (let n = -800000; n <= 800000; n += 997) {
        const [y, m, d] = civil(n);
        assert.equal(days(y, m, d), n);
    }
    assert.equal(parseDay('1970-01-01'), 0);
    assert.equal(formatDay(parseDay('2028-02-29')), '2028-02-29');
});

test('periods are contiguous over ten years', () => {
    const calendar = new PeriodCalendar({ anchor_day: 31, business_day_rule: 'previous', label: 'range', starts: [{ date: '2026-10-23' }] });
    let cursor = parseDay('2022-01-01');
    while (cursor < parseDay('2032-01-01')) {
        const [start, end] = calendar.periodDays(cursor);
        assert.ok(start <= cursor && cursor <= end);
        cursor = end + 1;
        assert.equal(calendar.periodDays(cursor)[0], cursor);
    }
});

test('v3 adapters read extPeriod from window.store', async () => {
    const { extViewRange, extAddPeriod, extSubtractPeriod } = await import('../../resources/assets/period-calendar.js');
    const settings = { anchor_day: 25, business_day_rule: 'previous', label: 'range', starts: [] };
    globalThis.window = { store: { get: (key) => (key === 'extPeriod' ? settings : undefined) } };
    try {
        const current = extViewRange(local('2026-10-27'));
        assert.deepEqual([iso(current.start), iso(current.end)], ['2026-10-23', '2026-11-24']);
        const next = extAddPeriod(current.start);
        assert.deepEqual([iso(next.start), iso(next.end)], ['2026-11-25', '2026-12-24']);
        const previous = extSubtractPeriod(current.start);
        assert.deepEqual([iso(previous.start), iso(previous.end)], ['2026-09-25', '2026-10-22']);
    } finally {
        delete globalThis.window;
    }
    // no store at all: defaults (25th, no business-day rule)
    const fallback = extViewRange(local('2026-10-27'));
    assert.deepEqual([iso(fallback.start), iso(fallback.end)], ['2026-10-25', '2026-11-24']);
});
