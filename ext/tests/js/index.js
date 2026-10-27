// Entry point for `node --test ext/tests/js/`: Node 22 resolves a directory argument to this
// index.js, which loads every *.test.mjs file next to it into the same test run.
const { readdirSync } = require('node:fs');
const { join } = require('node:path');
const { pathToFileURL } = require('node:url');

for (const file of readdirSync(__dirname).filter((name) => name.endsWith('.test.mjs')).sort()) {
    import(pathToFileURL(join(__dirname, file)).href);
}
