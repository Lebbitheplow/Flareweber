#!/usr/bin/env node
/**
 * Prepares the bundled Microweber app shipped inside the desktop installer:
 * composer create-project into resources/microweber, then copies the
 * FlareWeber module into its userfiles/modules directory.
 *
 * Requires php + composer on PATH (desktop CI workflow sets these up).
 */

import { cpSync, existsSync, mkdirSync, rmSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const REPO_ROOT = path.resolve(ROOT, '..');
const TARGET = path.join(ROOT, 'resources', 'microweber');
const MODULE_SRC = path.join(REPO_ROOT, 'module', 'flareweber');

if (!existsSync(path.join(TARGET, 'artisan'))) {
  mkdirSync(path.dirname(TARGET), { recursive: true });
  console.log('Composer create-project microweber/microweber...');
  execFileSync(
    'composer',
    ['create-project', 'microweber/microweber', TARGET, '--no-interaction', '--no-progress', '--prefer-dist'],
    { stdio: 'inherit', cwd: ROOT }
  );
} else {
  console.log('Bundled Microweber already present, refreshing FlareWeber module only.');
}

const moduleDest = path.join(TARGET, 'userfiles', 'modules', 'flareweber');
rmSync(moduleDest, { recursive: true, force: true });
mkdirSync(path.dirname(moduleDest), { recursive: true });
cpSync(MODULE_SRC, moduleDest, { recursive: true });
console.log(`Copied FlareWeber module -> ${moduleDest}`);

console.log('Done. resources/microweber is ready to bundle.');
