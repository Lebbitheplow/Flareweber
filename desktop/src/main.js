'use strict';

const { app, BrowserWindow, Menu, shell, ipcMain, clipboard, dialog } = require('electron');
const crypto = require('crypto');
const fs = require('fs');
const path = require('path');
const { paths } = require('./paths');
const { PhpServer } = require('./php-server');
const { FirstRun } = require('./first-run');
const deepLink = require('./deep-link');
const nav = require('./navigation');

// Custom scheme the OS hands OAuth callbacks back to us with, e.g.
// flareweber://cloudflare/callback?code=...&state=...
const PROTOCOL = deepLink.PROTOCOL;

// Per-launch secret handed to PHP as FLAREWEBER_DESKTOP_TOKEN; the session
// route swaps it for an admin login so the window never shows a login form.
const desktopToken = crypto.randomBytes(32).toString('hex');

let window = null;
let server = null;
let status = { state: 'starting', message: '', notice: '', url: null };
let pendingDeepLink = null;

function setStatus(next) {
  status = { ...status, ...next };
  if (window && !window.isDestroyed()) window.webContents.send('flareweber:status', status);
}

/** Boot output feeds the loading screen; once ready it only goes to the log. */
function logTo(line) {
  const last = String(line).trim().split('\n').pop();
  if (!last) return;
  if (status.state === 'starting') setStatus({ message: last.slice(0, 160) });
}

/** Lines that are not PHP output (already logged by PhpServer). */
function note(line) {
  logTo(line);
  fs.appendFile(paths().logFile, `${String(line).trim()}\n`, () => {});
}

function sessionUrl() {
  return `${status.url}/flareweber/desktop/session?token=${desktopToken}`;
}

function adminUrl() {
  return `${status.url}/flareweber/admin`;
}

function stripeRedirectUri() {
  return server?.url ? `${server.url}/flareweber/sites/{site}/stripe/callback` : null;
}

/**
 * Replay the callback against the local PHP app, then wake the admin window so
 * it can pick the result up from the handoff poll.
 */
function handleDeepLink(url) {
  if (!url) return;
  pendingDeepLink = url;
  deliverDeepLink();
}

function deliverDeepLink() {
  if (!pendingDeepLink) return;
  if (status.state !== 'ready' || !status.url) return;
  if (!window || window.isDestroyed()) return;
  if (!nav.isLocalUrl(window.webContents.getURL(), status.url)) return;

  const url = pendingDeepLink;
  pendingDeepLink = null;

  deepLink
    .deliver(url, status.url, note)
    .catch((err) => note(`[deep-link] ${err.message}`))
    .then(() => {
      if (window && !window.isDestroyed()) {
        window.show();
        window.focus();
        window.webContents.send('flareweber:oauth-link', url);
      }
    });
}

function checkPayload(p) {
  if (!fs.existsSync(p.phpBinary)) {
    throw new Error(
      `Bundled PHP runtime not found at ${p.phpBinary}. ` +
        'Run "npm run fetch:runtime" when developing from a checkout.'
    );
  }
  if (!fs.existsSync(p.routerFile)) {
    throw new Error(`Server router not found at ${p.routerFile}.`);
  }
  if (!fs.existsSync(path.join(p.bundledAppDir, 'artisan'))) {
    throw new Error(
      `Bundled Microweber app not found at ${p.bundledAppDir}. ` +
        'Run "npm run prepare:app" when developing from a checkout.'
    );
  }
}

async function warnPortFallback() {
  const wanted = server.preferredPort;
  setStatus({ notice: `Port ${wanted} is in use; using ${server.port} for this session.` });

  const options = {
    type: 'warning',
    title: 'Local port in use',
    message: `Port ${wanted} is already in use on this computer.`,
    detail:
      `FlareWeber will run on port ${server.port} for this session instead. ` +
      'Stripe Connect only accepts the exact loopback redirect URI you registered, so ' +
      `connecting Stripe will fail until port ${wanted} is free again or you register ` +
      `${stripeRedirectUri()} in your Stripe Connect settings ` +
      '(App menu > Copy Stripe Redirect URI). Cloudflare login is not affected.',
    buttons: ['Continue'],
    noLink: true,
  };
  await (window && !window.isDestroyed()
    ? dialog.showMessageBox(window, options)
    : dialog.showMessageBox(options));
}

async function boot(windowShown) {
  const p = paths();
  const firstRun = new FirstRun(p, { bundleVersion: app.getVersion() });
  server = new PhpServer(p);

  try {
    checkPayload(p);
    // Let the loading screen paint before the (long) copy starts.
    await windowShown;
    await firstRun.ensureAppCopied(logTo);

    const envPort = Number(process.env.FLAREWEBER_PORT);
    const url = await server.choosePort(Number.isInteger(envPort) && envPort > 0 ? envPort : undefined);
    setStatus({ url });
    if (server.portWasFallback) await warnPortFallback();

    await firstRun.ensureInstalled({ onLog: logTo, url });

    setStatus({ message: 'Starting local site...' });
    await server.start({ onLog: logTo, desktopToken });
    await server.waitUntilReady();

    // Keep message and notice: the ready state must not wipe a port warning.
    setStatus({ state: 'ready' });
    if (window && !window.isDestroyed()) window.loadURL(sessionUrl());
    deliverDeepLink();
  } catch (err) {
    setStatus({ state: 'error', message: err.message });
    if (window && !window.isDestroyed()) window.loadFile(path.join(__dirname, 'error.html'));
  }
}

/** Creates the window; resolves once it is visible so boot can start copying. */
function createWindow() {
  window = new BrowserWindow({
    width: 1280,
    height: 800,
    minWidth: 900,
    minHeight: 600,
    title: 'FlareWeber',
    show: false,
    webPreferences: {
      preload: path.join(__dirname, 'preload.js'),
      contextIsolation: true,
      nodeIntegration: false,
    },
  });

  const shown = new Promise((resolve) => {
    let done = false;
    const show = () => {
      if (done) return;
      done = true;
      if (window && !window.isDestroyed()) window.show();
      resolve();
    };
    window.once('ready-to-show', show);
    setTimeout(show, 3000);
  });

  window.webContents.on('did-finish-load', () => {
    if (!window || !pendingDeepLink) return;
    // Wait for the admin app itself, not the boot screen that precedes it.
    if (!nav.isLocalUrl(window.webContents.getURL(), status.url)) return;
    deliverDeepLink();
  });

  // window.open / target=_blank: keep local pages (edit content, live edit,
  // admin) in this window so the PHP session travels with them; everything
  // http(s) goes to the system browser; anything else is dropped.
  window.webContents.setWindowOpenHandler(({ url }) => {
    const kind = nav.classifyNavigation(url, status.url);
    if (kind === 'local' && window && !window.isDestroyed()) window.loadURL(url);
    else if (kind === 'external') shell.openExternal(url);
    return { action: 'deny' };
  });

  window.webContents.on('will-navigate', (event, url) => {
    const kind = nav.classifyNavigation(url, status.url);
    if (kind === 'local' || kind === 'allow') return;
    event.preventDefault();
    if (kind === 'external') shell.openExternal(url);
  });

  window.on('closed', () => {
    window = null;
  });

  window.loadFile(path.join(__dirname, 'loading.html'));
  return shown;
}

function registerProtocol() {
  try {
    if (process.defaultApp && process.argv.length >= 2) {
      app.setAsDefaultProtocolClient(PROTOCOL, process.execPath, [path.resolve(process.argv[1])]);
    } else {
      app.setAsDefaultProtocolClient(PROTOCOL);
    }
  } catch {
    // Best-effort: OAuth still works through the handoff poll, the OS just
    // shows its own "no app handles this link" page after authorisation.
  }
}

function copyToClipboard(text) {
  if (text) clipboard.writeText(text);
}

function showCredentials() {
  const file = paths().credentialsFile;
  if (fs.existsSync(file)) {
    shell.openPath(file);
    return;
  }
  dialog.showMessageBox({
    type: 'info',
    title: 'Admin credentials',
    message: 'No credentials file yet.',
    detail: `It is written during the first install to ${file}.`,
  });
}

function loadLocal(pathname) {
  if (status.state === 'ready' && status.url && window && !window.isDestroyed()) {
    window.loadURL(`${status.url}${pathname}`);
  }
}

function buildMenu() {
  const template = [
    {
      label: app.name,
      submenu: [
        { label: 'Open FlareWeber Admin', click: () => loadLocal('/flareweber/admin') },
        { label: 'Open Microweber Admin', click: () => loadLocal('/admin') },
        { label: 'Open Site', click: () => loadLocal('/') },
        { type: 'separator' },
        { label: 'Show Admin Credentials', click: showCredentials },
        {
          label: 'Copy Cloudflare Redirect URI',
          click: () =>
            copyToClipboard(
              process.env.CLOUDFLARE_OAUTH_REDIRECT_URI || `${PROTOCOL}://cloudflare/callback`
            ),
        },
        { label: 'Copy Stripe Redirect URI', click: () => copyToClipboard(stripeRedirectUri()) },
        { type: 'separator' },
        { label: 'Show Logs', click: () => shell.openPath(path.dirname(paths().logFile)) },
        { label: 'Show Data Folder', click: () => shell.openPath(paths().dataDir) },
        { role: 'quit' },
      ],
    },
    { role: 'editMenu' },
    { role: 'viewMenu' },
  ];

  Menu.setApplicationMenu(Menu.buildFromTemplate(template));
}

const gotLock = app.requestSingleInstanceLock();
if (!gotLock) {
  app.quit();
} else {
  // macOS delivers the callback through open-url; Windows and Linux relaunch
  // the app, so the URL arrives in the second instance's argv instead.
  app.on('open-url', (event, url) => {
    event.preventDefault();
    handleDeepLink(url);
  });

  app.on('second-instance', (_event, argv) => {
    if (window && !window.isDestroyed()) window.focus();
    handleDeepLink(deepLink.fromArgv(argv));
  });

  app.whenReady().then(() => {
    registerProtocol();
    buildMenu();
    const shown = createWindow();
    boot(shown);

    const launchLink = deepLink.fromArgv(process.argv);
    if (launchLink) handleDeepLink(launchLink);

    app.on('activate', () => {
      if (BrowserWindow.getAllWindows().length === 0) {
        createWindow();
        // The session cookie survives in Electron's session, so the admin
        // opens without spending another login token.
        loadLocal('/flareweber/admin');
      }
    });
  });

  ipcMain.handle('flareweber:status', () => status);
  ipcMain.handle('flareweber:credentials-file', () => paths().credentialsFile);
  ipcMain.handle('flareweber:stripe-redirect-uri', () => stripeRedirectUri());

  app.on('window-all-closed', () => {
    if (process.platform !== 'darwin') app.quit();
  });

  app.on('before-quit', async (event) => {
    if (server?.child) {
      event.preventDefault();
      await server.stop();
      app.exit(0);
    }
  });
}
