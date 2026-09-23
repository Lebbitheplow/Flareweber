'use strict';

const { spawn } = require('child_process');
const net = require('net');
const fs = require('fs');
const path = require('path');

const DEFAULT_PORT = 8471;

// Limits the built-in server runs with (publishing and media uploads need
// more than the CLI defaults).
const PHP_INI_OVERRIDES = [
  'max_execution_time=0',
  'memory_limit=1024M',
  'upload_max_filesize=64M',
  'post_max_size=64M',
];

/**
 * `-c php.ini` for the bundled Windows runtime: scripts/fetch-runtime.mjs
 * generates the ini next to php.exe so the extensions (intl, sodium, ...)
 * load. Static Linux/macOS builds compile them in and need nothing.
 */
function phpIniArgs(paths) {
  if (process.platform !== 'win32' || !paths.phpIniFile) return [];
  return fs.existsSync(paths.phpIniFile) ? ['-c', paths.phpIniFile] : [];
}

function readPersistedPort(file) {
  try {
    const port = Number(JSON.parse(fs.readFileSync(file, 'utf8')).port);
    return Number.isInteger(port) && port > 0 && port < 65536 ? port : null;
  } catch {
    return null;
  }
}

function persistPort(file, port) {
  fs.mkdirSync(path.dirname(file), { recursive: true });
  fs.writeFileSync(file, JSON.stringify({ port }, null, 2));
}

/**
 * Supervises the bundled PHP runtime serving the bundled Microweber app
 * with PHP's built-in web server on a loopback port. Microweber 2.0.20 is
 * served from the app root (index.php and userfiles/ live there).
 */
class PhpServer {
  constructor(paths) {
    this.paths = paths;
    this.child = null;
    this.port = null;
    this.preferredPort = null;
    this.url = null;
    this.portWasFallback = false;
  }

  /**
   * Pick the loopback port before anything else runs, so the installer's
   * APP_URL and the credentials file agree with the server. The preferred
   * port is fixed (env, then port.json, then 8471) because Stripe only
   * accepts an exact http loopback redirect URI. When it is busy we fall
   * back to a free port for this session without persisting it, so the next
   * launch tries the registered one again.
   */
  async choosePort(preferredPort) {
    const preferred = preferredPort || readPersistedPort(this.paths.portFile) || DEFAULT_PORT;
    this.preferredPort = preferred;

    if (await this.isFree(preferred)) {
      this.port = preferred;
      this.portWasFallback = false;
      persistPort(this.paths.portFile, preferred);
    } else {
      this.port = await this.findFreePort();
      this.portWasFallback = true;
    }

    this.url = `http://127.0.0.1:${this.port}`;
    return this.url;
  }

  async start({ onLog, desktopToken } = {}) {
    if (!this.port) await this.choosePort();

    fs.mkdirSync(path.dirname(this.paths.logFile), { recursive: true });
    const logStream = fs.createWriteStream(this.paths.logFile, { flags: 'a' });

    const env = {
      ...process.env,
      APP_ENV: 'local',
      APP_DEBUG: 'false',
      APP_URL: this.url,
      DB_CONNECTION: 'sqlite',
      DB_DATABASE: this.paths.databaseFile,
      WORKER_TEMPLATE_PATH: this.paths.workerTemplatePath,
      PHP_CLI_SERVER_WORKERS: '4',
      // OAuth round-trips leave this window for the system browser, so the
      // app picks the result up through a handoff token instead of a cookie.
      CLOUDFLARE_OAUTH_REDIRECT_URI:
        process.env.CLOUDFLARE_OAUTH_REDIRECT_URI || 'flareweber://cloudflare/callback',
      // Stripe rejects custom URI schemes, so pin the loopback callback to the
      // port the user registered in their Stripe Connect settings.
      STRIPE_REDIRECT_URI:
        process.env.STRIPE_REDIRECT_URI ||
        `${this.url}/flareweber/sites/{site}/stripe/callback`,
    };
    // Per-launch secret for GET /flareweber/desktop/session?token=... which
    // logs the window in as the first admin without a login form.
    if (desktopToken) env.FLAREWEBER_DESKTOP_TOKEN = desktopToken;

    // router.php denies .env, vendor/, storage/ and friends, which php -S
    // would otherwise happily serve from the app root.
    const args = [
      ...phpIniArgs(this.paths),
      ...PHP_INI_OVERRIDES.flatMap((setting) => ['-d', setting]),
      '-S', `127.0.0.1:${this.port}`,
      '-t', this.paths.appDir,
      this.paths.routerFile,
    ];

    this.child = spawn(this.paths.phpBinary, args, {
      cwd: this.paths.appDir,
      env,
      stdio: ['ignore', 'pipe', 'pipe'],
    });

    this.child.stdout.on('data', (chunk) => this.log(logStream, onLog, chunk));
    this.child.stderr.on('data', (chunk) => this.log(logStream, onLog, chunk));
    this.child.on('error', (err) => this.log(logStream, onLog, `\n[php-server] ${err.message}\n`));
    this.child.on('exit', (code) => {
      this.log(logStream, onLog, `\n[php-server] exited with code ${code}\n`);
      this.child = null;
    });

    return this.url;
  }

  log(stream, onLog, chunk) {
    const text = chunk.toString();
    stream.write(text);
    if (onLog) onLog(text);
  }

  isFree(port) {
    return new Promise((resolve) => {
      const srv = net.createServer();
      srv.once('error', () => resolve(false));
      srv.listen(port, '127.0.0.1', () => srv.close(() => resolve(true)));
    });
  }

  findFreePort() {
    return new Promise((resolve, reject) => {
      const srv = net.createServer();
      srv.once('error', reject);
      srv.listen(0, '127.0.0.1', () => {
        const { port } = srv.address();
        srv.close(() => resolve(port));
      });
    });
  }

  async waitUntilReady({ timeoutMs = 180000 } = {}) {
    const deadline = Date.now() + timeoutMs;

    while (Date.now() < deadline) {
      if (this.child === null) {
        throw new Error('PHP server exited before becoming ready; see logs');
      }
      try {
        const res = await fetch(`${this.url}/`, { redirect: 'manual' });
        if (res.status < 500) return true;
      } catch {
        // not listening yet
      }
      await new Promise((r) => setTimeout(r, 500));
    }

    throw new Error('Timed out waiting for the local site to start');
  }

  async stop() {
    if (!this.child) return;
    const child = this.child;
    child.kill('SIGTERM');
    await new Promise((resolve) => {
      const timer = setTimeout(() => child.kill('SIGKILL'), 5000);
      child.on('exit', () => {
        clearTimeout(timer);
        resolve();
      });
    });
    this.child = null;
  }
}

module.exports = { PhpServer, phpIniArgs, DEFAULT_PORT, PHP_INI_OVERRIDES };
