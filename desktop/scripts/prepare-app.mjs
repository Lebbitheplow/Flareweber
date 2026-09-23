#!/usr/bin/env node
/**
 * Prepares the bundled Microweber app shipped inside the desktop installer:
 * composer create-project into resources/microweber, then copies the
 * FlareWeber module into its userfiles/modules directory, then bundles the
 * Worker template into resources/worker.
 *
 * Requires php + composer + node on PATH (desktop CI workflow sets these up).
 * The PHP that runs composer here must have ext-intl and ext-sodium loaded:
 * microweber/microweber 2.0.20 declares both in composer.json, so composer
 * refuses to resolve the project without them. The bundled runtime needs
 * them too (static-php-cli list in .github/workflows/desktop.yml, php.ini
 * written by scripts/fetch-runtime.mjs on Windows).
 */

import { cpSync, existsSync, mkdirSync, readFileSync, rmSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

// Pinned release. Bump deliberately: the compiler, the desktop copy rules and
// the install command are verified against this exact version.
const MICROWEBER_VERSION = 'v2.0.20';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const REPO_ROOT = path.resolve(ROOT, '..');
const TARGET = path.join(ROOT, 'resources', 'microweber');
const MODULE_SRC = path.join(REPO_ROOT, 'module', 'flareweber');

function assertPhpExtensions() {
  const required = ['intl', 'sodium', 'gd', 'zip', 'mbstring', 'openssl'];
  const check = required.map((e) => `'${e}'`).join(',');
  let out = '';
  try {
    out = execFileSync(
      'php',
      ['-r', `echo implode(',',array_filter([${check}],fn($e)=>!extension_loaded($e)));`],
      { encoding: 'utf8' }
    ).trim();
  } catch (err) {
    throw new Error(`php on PATH is required to prepare the bundled app: ${err.message}`);
  }
  if (out) {
    throw new Error(
      `The php on PATH is missing extensions required by Microweber ${MICROWEBER_VERSION}: ${out}`
    );
  }
}

function bundledVersion() {
  try {
    return readFileSync(path.join(TARGET, 'version.txt'), 'utf8').trim();
  } catch {
    return null;
  }
}

assertPhpExtensions();

if (!existsSync(path.join(TARGET, 'artisan'))) {
  mkdirSync(path.dirname(TARGET), { recursive: true });
  console.log(`Composer create-project microweber/microweber ${MICROWEBER_VERSION}...`);
  execFileSync(
    'composer',
    [
      'create-project',
      'microweber/microweber',
      TARGET,
      MICROWEBER_VERSION,
      '--no-interaction',
      '--no-progress',
      '--prefer-dist',
    ],
    { stdio: 'inherit', cwd: ROOT }
  );
} else {
  const present = bundledVersion();
  if (present && `v${present}` !== MICROWEBER_VERSION) {
    throw new Error(
      `resources/microweber holds Microweber ${present} but ${MICROWEBER_VERSION} is pinned. ` +
        'Delete desktop/resources/microweber and rerun.'
    );
  }
  console.log('Bundled Microweber already present, refreshing FlareWeber module only.');
}

const moduleDest = path.join(TARGET, 'userfiles', 'modules', 'flareweber');
rmSync(moduleDest, { recursive: true, force: true });
mkdirSync(path.dirname(moduleDest), { recursive: true });
cpSync(MODULE_SRC, moduleDest, {
  recursive: true,
  filter: (src) => !src.split(path.sep).includes('node_modules'),
});
console.log(`Copied FlareWeber module -> ${moduleDest}`);

// Ship a prebuilt Worker bundle so deploys need no Node runtime at install time.
const WORKER_SRC = path.join(REPO_ROOT, 'worker');
const WORKER_DEST = path.join(ROOT, 'resources', 'worker');

rmSync(WORKER_DEST, { recursive: true, force: true });
mkdirSync(WORKER_DEST, { recursive: true });
for (const entry of ['src', 'package.json', 'tsconfig.json', 'schema.sql']) {
  cpSync(path.join(WORKER_SRC, entry), path.join(WORKER_DEST, entry), { recursive: true });
}
if (existsSync(path.join(WORKER_SRC, 'package-lock.json'))) {
  cpSync(path.join(WORKER_SRC, 'package-lock.json'), path.join(WORKER_DEST, 'package-lock.json'));
}

const npm = process.platform === 'win32' ? 'npm.cmd' : 'npm';
const npx = process.platform === 'win32' ? 'npx.cmd' : 'npx';
const shell = process.platform === 'win32';

console.log('Installing Worker runtime deps (esbuild bundling)...');
execFileSync(npm, ['install', '--omit=dev', '--no-audit', '--no-fund'], {
  stdio: 'inherit',
  cwd: WORKER_DEST,
  shell,
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
  { stdio: 'inherit', cwd: WORKER_DEST, shell }
);

if (!existsSync(path.join(WORKER_DEST, 'dist', 'worker.mjs'))) {
  throw new Error('Worker bundle missing after bundling: resources/worker/dist/worker.mjs');
}

// Only the prebuilt bundle (and its source for optional re-bundling) is needed
// at runtime; drop node_modules to keep the installer lean.
rmSync(path.join(WORKER_DEST, 'node_modules'), { recursive: true, force: true });

console.log('Done. resources/microweber and resources/worker are ready to bundle.');
