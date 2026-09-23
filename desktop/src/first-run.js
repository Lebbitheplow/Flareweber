'use strict';

const fs = require('fs');
const fsp = require('fs/promises');
const path = require('path');
const { spawn } = require('child_process');
const crypto = require('crypto');
const rules = require('./copy-rules');
const { phpIniArgs } = require('./php-server');

const INSTALL_TIMEOUT_MS = 600000;
const MARKER = '.flareweber-install.json';

/**
 * First-run setup: copy the bundled Microweber app into the user's writable
 * directory and run its CLI installer with SQLite, so no external database
 * or web server is needed on the desktop. Everything is async so the loading
 * window keeps painting while the copy and the installer run.
 */
class FirstRun {
  constructor(paths, { bundleVersion = 'dev' } = {}) {
    this.paths = paths;
    this.bundleVersion = bundleVersion;
    this.markerFile = path.join(paths.appDir, MARKER);
  }

  readMarker() {
    try {
      return JSON.parse(fs.readFileSync(this.markerFile, 'utf8'));
    } catch {
      return null;
    }
  }

  writeMarker(patch) {
    const next = { ...(this.readMarker() || {}), ...patch };
    fs.writeFileSync(this.markerFile, JSON.stringify(next, null, 2));
    return next;
  }

  /** Identifies the shipped payload so a new installer re-copies the code. */
  bundleId() {
    let microweber = 'unknown';
    try {
      microweber = fs.readFileSync(path.join(this.paths.bundledAppDir, 'version.txt'), 'utf8').trim();
    } catch {
      // older payloads without version.txt fall back to the fingerprint only
    }
    return `${this.bundleVersion}:${microweber}:${this.readFingerprint()}`;
  }

  async ensureAppCopied(onLog = () => {}) {
    await fsp.mkdir(this.paths.dataDir, { recursive: true });
    await fsp.mkdir(this.paths.appDir, { recursive: true });

    const appExists = fs.existsSync(path.join(this.paths.appDir, 'artisan'));
    const bundleId = this.bundleId();
    const plan = rules.copyPlan(this.readMarker(), bundleId, appExists);
    if (!plan.copy) return false;

    onLog(
      plan.firstCopy
        ? 'Installing bundled Microweber app (first run, this takes a minute)...\n'
        : 'Upgrading bundled Microweber app...\n'
    );

    if (!plan.firstCopy) {
      for (const rel of rules.UPGRADE_REFRESH) {
        await fsp.rm(path.join(this.paths.appDir, ...rel.split('/')), { recursive: true, force: true });
      }
    }

    const skipList = rules.skipListFor(plan);
    const srcRoot = path.resolve(this.paths.bundledAppDir);

    await fsp.cp(srcRoot, this.paths.appDir, {
      recursive: true,
      force: true,
      dereference: true,
      filter: (src) => !rules.shouldSkip(path.relative(srcRoot, src), skipList),
    });

    this.writeMarker({ copied: bundleId, copiedAt: new Date().toISOString() });
    onLog('App files ready.\n');
    return true;
  }

  /**
   * Run microweber:install once. `url` is the loopback URL the server will
   * use, so APP_URL and the credentials file agree with it.
   */
  async ensureInstalled({ onLog = () => {}, url } = {}) {
    const marker = this.readMarker();
    if (marker && marker.installedAt) return false;

    const adminPassword = crypto.randomBytes(12).toString('base64url');
    const adminEmail = process.env.FLAREWEBER_ADMIN_EMAIL || 'admin@localhost.local';
    // Microweber 2.0.20 ships the "default" and "big" templates only.
    const template = process.env.FLAREWEBER_TEMPLATE || 'default';

    await fsp.mkdir(path.dirname(this.paths.databaseFile), { recursive: true });

    const args = [
      ...phpIniArgs(this.paths),
      '-d', 'memory_limit=1024M',
      '-d', 'max_execution_time=0',
      'artisan', 'microweber:install',
      '--db-driver=sqlite',
      `--db-name=${this.paths.databaseFile}`,
      `--email=${adminEmail}`,
      '--username=admin',
      `--password=${adminPassword}`,
      `--template=${template}`,
      '--default-content=1',
    ];

    onLog('Running Microweber installer (SQLite)...\n');
    await this.runInstaller(args, { onLog, url });

    fs.writeFileSync(
      this.paths.credentialsFile,
      JSON.stringify(
        { url: url || 'http://127.0.0.1', adminUsername: 'admin', adminEmail, adminPassword },
        null,
        2
      )
    );
    this.writeMarker({ installedAt: new Date().toISOString(), template });

    onLog('Microweber installed. Admin credentials written to admin-credentials.json\n');
    return true;
  }

  runInstaller(args, { onLog, url }) {
    return new Promise((resolve, reject) => {
      fs.mkdirSync(path.dirname(this.paths.logFile), { recursive: true });
      const logStream = fs.createWriteStream(this.paths.logFile, { flags: 'a' });
      let timedOut = false;

      const child = spawn(this.paths.phpBinary, args, {
        cwd: this.paths.appDir,
        env: {
          ...process.env,
          APP_ENV: 'local',
          APP_DEBUG: 'false',
          ...(url ? { APP_URL: url } : {}),
        },
        stdio: ['ignore', 'pipe', 'pipe'],
      });

      const timer = setTimeout(() => {
        timedOut = true;
        child.kill('SIGKILL');
      }, INSTALL_TIMEOUT_MS);

      const relay = (chunk) => {
        const text = chunk.toString();
        logStream.write(text);
        onLog(text);
      };
      child.stdout.on('data', relay);
      child.stderr.on('data', relay);

      child.on('error', (err) => {
        clearTimeout(timer);
        logStream.end();
        reject(new Error(`Could not start the Microweber installer: ${err.message}`));
      });

      child.on('exit', (code) => {
        clearTimeout(timer);
        logStream.end();
        if (timedOut) {
          reject(new Error(`Microweber install timed out after ${INSTALL_TIMEOUT_MS / 1000}s. See ${this.paths.logFile}`));
        } else if (code !== 0) {
          reject(new Error(`Microweber install failed (exit ${code}). See ${this.paths.logFile}`));
        } else {
          resolve();
        }
      });
    });
  }

  readFingerprint() {
    const pkg = path.join(this.paths.bundledAppDir, 'composer.json');
    try {
      const stat = fs.statSync(pkg);
      return `${stat.size}-${Math.round(stat.mtimeMs)}`;
    } catch {
      return 'unknown';
    }
  }
}

module.exports = { FirstRun, INSTALL_TIMEOUT_MS, MARKER };
