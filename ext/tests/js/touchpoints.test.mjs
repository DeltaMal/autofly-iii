// Sanity check that ext/UPSTREAM_TOUCHPOINTS.md lists only files that exist (node --test, no dependencies).
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync, existsSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const root = join(dirname(fileURLToPath(import.meta.url)), '..', '..', '..');

test('every added touchpoint file exists and carries its marker', () => {
    const table = readFileSync(join(root, 'ext/UPSTREAM_TOUCHPOINTS.md'), 'utf8').split('\n').filter((line) => line.startsWith('| `'));
    let checked = 0;
    for (const row of table) {
        const cells = row.split('|').map((cell) => cell.trim());
        const file = cells[1].replace(/`/g, '').split(' ')[0];
        if (cells.length < 6 || cells[5] !== 'added' || !cells[3].includes('CUSTOM(ext)')) {
            continue;
        }
        assert.ok(existsSync(join(root, file)), `${file} exists`);
        assert.match(readFileSync(join(root, file), 'utf8'), /CUSTOM\(ext\)/, `${file} has a CUSTOM(ext) marker`);
        checked++;
    }
    assert.ok(checked >= 2);
});
