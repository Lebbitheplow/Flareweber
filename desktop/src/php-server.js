'use strict';

const { spawn } = require('child_process');
const net = require('net');
const fs = require('fs');
const path = require('path');

/**
 * Supervises the bundled PHP runtime serving the bundled Microweber app
 * with PHP's built-in web server on a free loopback port.
 */
class PhpServer {
  constructor(paths) {
    this.paths = paths;
    this.child = null;
    this.port = null;
    this.url = null;
  }

  async start({ onLog } = {}) {
    this.port = await this.findFreePort();
    this.url = `http://127.0.0.1:${this.port}`;

    fs.mkdirSync(path.dirname(this.paths.logFile), { recursive: true });
    const logStream = fs.createWriteStream(this.paths.logFile, { flags: 'a' });

    const env = {
      ...process.env,
      APP_ENV: 'local',
      APP_DEBUG: 'false',
      APP_URL: this.url,
      DB_CONNECTION: 'sqlite',
      DB_DATABASE: this.paths.databaseFile,
      PHP_CLI_SERVER_WORKERS: '4',
    };

    this.child = spawn(
      this.paths.phpBinary,
      ['-d', 'memory_limit=512M', '-S', `127.0.0.1:${this.port}`, '-t', 'public'],
      { cwd: this.paths.appDir, env, stdio: ['ignore', 'pipe', 'pipe'] }
    );

    this.child.stdout.on('data', (chunk) => this.log(logStream, onLog, chunk));
    this.child.stderr.on('data', (chunk) => this.log(logStream, onLog, chunk));
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

  findFreePort() {
    return new Promise((resolve, reject) => {
      const srv = net.createServer();
      srv.on('error', reject);
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

module.exports = { PhpServer };
