'use strict';

const fs = require('fs');
const path = require('path');
const { spawnSync } = require('child_process');
const crypto = require('crypto');

/**
 * First-run setup: copy the bundled Microweber app into the user's
 * writable directory and run its CLI installer with SQLite, so no
 * external database or web server is needed on the desktop.
 */
class FirstRun {
  constructor(paths) {
    this.paths = paths;
  }

  ensureAppCopied(onLog = () => {}) {
    const marker = path.join(this.paths.appDir, '.flareweber-fingerprint');
    const fingerprint = this.readFingerprint();

    fs.mkdirSync(this.paths.dataDir, { recursive: true });

    const existing = fs.existsSync(marker) ? fs.readFileSync(marker, 'utf8') : null;

    if (existing === fingerprint) return;

    onLog('Installing bundled Microweber app...\n');
    this.copyFresh();
    fs.writeFileSync(marker, fingerprint);
  }

  /**
   * Overwrite everything except user data (uploads, database, cache, env).
   */
  copyFresh() {
    fs.mkdirSync(this.paths.appDir, { recursive: true });

    this.copyTree(this.paths.bundledAppDir, this.paths.appDir, [
      'userfiles',
      'storage',
      'bootstrap/cache',
      '.env',
      '.flareweber-install.json',
    ]);
  }

  ensureInstalled(onLog = () => {}) {
    const marker = path.join(this.paths.appDir, '.flareweber-install.json');
    if (fs.existsSync(marker)) return;

    const adminPassword = crypto.randomBytes(12).toString('base64url');
    const adminEmail = process.env.FLAREWEBER_ADMIN_EMAIL || 'admin@localhost.local';

    onLog('Running Microweber installer (SQLite)...\n');
    const result = spawnSync(
      this.paths.phpBinary,
      [
        'artisan', 'microweber:install',
        `--db-driver=sqlite`,
        `--db-name=${this.paths.databaseFile}`,
        `--email=${adminEmail}`,
        `--username=admin`,
        `--password=${adminPassword}`,
        `--template=${process.env.FLAREWEBER_TEMPLATE || 'liteness'}`,
      ],
      {
        cwd: this.paths.appDir,
        env: { ...process.env, APP_ENV: 'local', APP_DEBUG: 'true' },
        encoding: 'utf8',
        timeout: 600000,
      }
    );

    if (result.status !== 0) {
      fs.appendFileSync(this.paths.logFile, `\n${result.stdout || ''}\n${result.stderr || ''}\n`);
      throw new Error(
        `Microweber install failed (exit ${result.status}). See ${this.paths.logFile}`
      );
    }

    fs.writeFileSync(
      this.paths.credentialsFile,
      JSON.stringify({ url: 'http://127.0.0.1', adminEmail, adminPassword }, null, 2)
    );
    fs.writeFileSync(marker, JSON.stringify({ installedAt: new Date().toISOString() }));

    onLog('Microweber installed. Admin credentials written to admin-credentials.json\n');
  }

  readFingerprint() {
    const pkg = path.join(this.paths.bundledAppDir, 'composer.json');
    try {
      const stat = fs.statSync(pkg);
      return `${stat.size}-${stat.mtimeMs}`;
    } catch {
      return 'unknown';
    }
  }

  copyTree(src, dest, skip = []) {
    const skipSet = new Set(skip);
    const srcRoot = path.resolve(src);

    const rel = (p) => path.relative(srcRoot, p).split(path.sep).join('/');

    const walk = (from, to) => {
      fs.mkdirSync(to, { recursive: true });
      for (const entry of fs.readdirSync(from, { withFileTypes: true })) {
        const fromPath = path.join(from, entry.name);
        if (skipSet.has(rel(fromPath))) continue;

        const toPath = path.join(to, entry.name);
        if (entry.isDirectory()) {
          walk(fromPath, toPath);
        } else if (entry.isFile()) {
          fs.copyFileSync(fromPath, toPath);
        }
      }
    };

    walk(src, dest);
  }
}

module.exports = { FirstRun };
