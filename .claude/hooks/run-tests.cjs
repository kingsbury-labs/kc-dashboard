#!/usr/bin/env node
/**
 * Run every hook test suite in this directory.
 *
 * WHY THIS EXISTS RATHER THAN A DOCUMENTED `node --test` LINE. The obvious
 * command is not portable in either direction that matters here:
 *
 *   `node --test .claude/hooks/`   Node 24 resolves a bare directory argument
 *                                  as a module and dies with MODULE_NOT_FOUND
 *                                  before running anything. Earlier versions
 *                                  recursed. The documented command therefore
 *                                  depends on which Node is installed.
 *   `node --test .claude/hooks/*.test.cjs`
 *                                  Relies on the SHELL expanding the glob.
 *                                  Git Bash does. PowerShell does not expand
 *                                  globs for native commands, so it passes the
 *                                  literal asterisk and Node finds no tests -
 *                                  and reports that as a pass.
 *
 * The second failure is the dangerous one: zero tests run, exit code 0, and
 * whoever typed it believes the gates are proven. So the file list is computed
 * here, and this script fails loudly if it finds none.
 *
 * Usage: node .claude/hooks/run-tests.cjs
 */

const fs = require('fs');
const path = require('path');
const { spawnSync } = require('child_process');

const files = fs.readdirSync(__dirname)
    .filter((f) => f.endsWith('.test.cjs'))
    .sort()
    .map((f) => path.join(__dirname, f));

if (files.length === 0) {
    console.error('run-tests: no *.test.cjs files found in this directory. Nothing was verified.');
    process.exit(1);
}

const result = spawnSync(process.execPath, ['--test', ...files], { windowsHide: true, stdio: 'inherit' });

if (result.error) {
    console.error(`run-tests: could not start node (${result.error.message})`);
    process.exit(1);
}

process.exit(result.status === null ? 1 : result.status);
