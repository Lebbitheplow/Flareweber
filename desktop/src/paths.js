'use strict';

const path = require('path');
const os = require('os');
const { app } = require('electron');

const isPackaged = app.isPackaged && !process.argv.includes('--dev');

// Read-only bundled payloads shipped inside the installer.
const bundledRoot = isPackaged
  ? process.resourcesPath
  : path.join(__dirname, '..', 'resources');

// Writable working copy of the Microweber app (user content, uploads, DB).
const appDir = isPackaged
  ? path.join(app.getPath('userData'), 'app')
  : path.join(__dirname, '..', '.dev', 'app');

const dataDir = isPackaged
  ? path.join(app.getPath('userData'), 'data')
  : path.join(__dirname, '..', '.dev', 'data');

const databaseFile = path.join(dataDir, 'database.sqlite');

const runtimeDir = process.env.FLAREWEBER_RUNTIME || path.join(bundledRoot, 'php');
const phpBinary = process.platform === 'win32'
  ? path.join(runtimeDir, 'php.exe')
  : path.join(runtimeDir, 'php');

const bundledAppDir = path.join(bundledRoot, 'microweber');

// Read-only Worker template (src + prebuilt dist/worker.mjs). In dev this is the
// repo's worker/ directory; when packaged it ships under resources/worker.
const workerTemplatePath = isPackaged
  ? path.join(bundledRoot, 'worker')
  : path.join(__dirname, '..', '..', 'worker');

const logFile = path.join(dataDir, 'logs', 'php-server.log');
const credentialsFile = path.join(dataDir, 'admin-credentials.json');

function paths() {
  return {
    bundledRoot,
    bundledAppDir,
    workerTemplatePath,
    appDir,
    dataDir,
    databaseFile,
    runtimeDir,
    phpBinary,
    logFile,
    credentialsFile,
    tmpDir: path.join(os.tmpdir(), 'flareweber'),
  };
}

module.exports = { paths, isPackaged };
