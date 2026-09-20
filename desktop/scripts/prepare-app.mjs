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

// Ship a prebuilt Worker bundle so deploys need no Node runtime at install time.
const WORKER_SRC = path.join(REPO_ROOT, 'worker');
const WORKER_DEST = path.join(ROOT, 'resources', 'worker');

rmSync(WORKER_DEST, { recursive: true, force: true });
mkdirSync(WORKER_DEST, { recursive: true });
for (const entry of ['src', 'package.json', 'tsconfig.json', 'schema.sql']) {
  cpSync(path.join(WORKER_SRC, entry), path.join(WORKER_DEST, entry), { recursive: true });
}

const npm = process.platform === 'win32' ? 'npm.cmd' : 'npm';
const npx = process.platform === 'win32' ? 'npx.cmd' : 'npx';

console.log('Installing Worker runtime deps (esbuild bundling)...');
execFileSync(npm, ['install', '--omit=dev', '--no-audit', '--no-fund'], {
  stdio: 'inherit',
  cwd: WORKER_DEST,
});

console.log('Bundling Worker -> resources/worker/dist/worker.mjs...');
execFileSync(
  npx,
  [
    '--yes',
    'esbuild@0.25',
    'src/index.ts',
    '--bundle',
    '--format=esm',
    '--platform=neutral',
    '--outfile=dist/worker.mjs',
    '--minify',
    '--log-level=error',
  ],
  { stdio: 'inherit', cwd: WORKER_DEST }
);

if (!existsSync(path.join(WORKER_DEST, 'dist', 'worker.mjs'))) {
  throw new Error('Worker bundle missing after bundling: resources/worker/dist/worker.mjs');
}

// Only the prebuilt bundle (and its source for optional re-bundling) is needed
// at runtime; drop node_modules to keep the installer lean.
rmSync(path.join(WORKER_DEST, 'node_modules'), { recursive: true, force: true });

console.log('Done. resources/microweber and resources/worker are ready to bundle.');
