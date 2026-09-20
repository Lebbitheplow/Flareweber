#!/usr/bin/env node
/**
 * Downloads a self-contained PHP CLI runtime into resources/php so the
 * desktop app bundles its own PHP with no system dependency.
 *
 * Windows: official php.net thread-safe zip (works with php -S, no VCRUN
 * needed thanks to vs17 redistributable bundling by electron-builder later;
 * VC runtime ships with Windows 10+).
 * Linux/macOS: pass --url pointing at a prebuilt static PHP
 * (e.g. from static-php-cli CI builds) or set FLAREWEBER_PHP_URL.
 *
 * Usage: node scripts/fetch-runtime.mjs [--url <zip|tar.gz>] [--dir resources/php]
 */

import { mkdirSync, existsSync, createWriteStream, chmodSync, rmSync, renameSync, statSync, readdirSync } from 'node:fs';
import { createGunzip } from 'node:zlib';
import { pipeline } from 'node:stream/promises';
import { execFileSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');

const WINDOWS_LATEST = 'https://windows.php.net/downloads/releases/latest/php-8.3-nts-Win32-vs16-x64-latest.zip';

function arg(name, fallback) {
  const i = process.argv.indexOf(`--${name}`);
  return i !== -1 && process.argv[i + 1] ? process.argv[i + 1] : fallback;
}

async function download(url, dest) {
  const res = await fetch(url, { redirect: 'follow' });
  if (!res.ok || !res.body) throw new Error(`download failed: ${res.status} ${url}`);
  await pipeline(res.body, createWriteStream(dest));
}

function findFile(dir, name, minSize = 0) {
  for (const entry of readdirSync(dir, { withFileTypes: true })) {
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) {
      const found = findFile(full, name, minSize);
      if (found) return found;
    } else if (entry.name === name && statSync(full).size > minSize) {
      return full;
    }
  }
  return null;
}

async function main() {
  const outDir = path.resolve(ROOT, arg('dir', 'resources/php'));
  const binary = process.platform === 'win32' ? 'php.exe' : 'php';

  if (existsSync(path.join(outDir, binary))) {
    console.log(`PHP runtime already present in ${outDir}`);
    return;
  }

  const url = arg('url', process.env.FLAREWEBER_PHP_URL || (process.platform === 'win32' ? WINDOWS_LATEST : null));
  if (!url) {
    console.error(
      'No PHP runtime URL for this platform. Provide --url or FLAREWEBER_PHP_URL ' +
      'pointing to a self-contained PHP tarball (see .github/workflows/desktop.yml ' +
      'for the static-php-cli build used in CI).'
    );
    process.exit(1);
  }

  mkdirSync(outDir, { recursive: true });
  const tmp = path.join(outDir, path.basename(new URL(url).pathname) || 'php-runtime');

  console.log(`Downloading PHP runtime from ${url}...`);
  await download(url, tmp);

  if (tmp.endsWith('.zip')) {
    execFileSync('tar', ['-xf', tmp, '-C', outDir], { stdio: 'inherit' });
    rmSync(tmp);
    if (!existsSync(path.join(outDir, binary))) {
      const found = findFile(outDir, binary, 1_000_000);
      if (!found) throw new Error(`php binary not found inside archive`);
      renameSync(found, path.join(outDir, binary));
    }
  } else if (tmp.endsWith('.gz')) {
    const { ReadStream } = await import('node:fs');
    await pipeline(ReadStream(tmp), createGunzip(), createWriteStream(path.join(outDir, binary)));
    rmSync(tmp);
  } else {
    renameSync(tmp, path.join(outDir, binary));
  }

  const phpPath = path.join(outDir, binary);
  if (process.platform !== 'win32') chmodSync(phpPath, 0o755);

  const version = execFileSync(phpPath, ['-r', 'echo PHP_VERSION;'], { encoding: 'utf8' }).trim();
  console.log(`Bundled PHP ${version} at ${phpPath}`);
}

main().catch((err) => {
  console.error(err.message);
  process.exit(1);
});
