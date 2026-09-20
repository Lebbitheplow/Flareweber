'use strict';

const { app, BrowserWindow, Menu, shell, ipcMain } = require('electron');
const fs = require('fs');
const path = require('path');
const { paths, isPackaged } = require('./paths');
const { PhpServer } = require('./php-server');
const { FirstRun } = require('./first-run');

let window = null;
let server = null;
let status = { state: 'starting', message: '', url: null };

function setStatus(next) {
  status = { ...status, ...next };
  window?.webContents.send('flareweber:status', status);
}

function logTo(statusLine) {
  const last = statusLine.trim().split('\n').pop();
  if (last) setStatus({ message: last.slice(0, 160) });
}

async function boot() {
  const p = paths();
  const firstRun = new FirstRun(p);
  server = new PhpServer(p);

  try {
    if (!fs.existsSync(p.phpBinary)) {
      throw new Error(
        `Bundled PHP runtime not found at ${p.phpBinary}. ` +
        'Run "npm run fetch:runtime" when developing from a checkout.'
      );
    }
    if (!fs.existsSync(p.bundledAppDir)) {
      throw new Error(
        `Bundled Microweber app not found at ${p.bundledAppDir}. ` +
        'Run "npm run prepare:app" when developing from a checkout.'
      );
    }

    firstRun.ensureAppCopied(logTo);
    firstRun.ensureInstalled(logTo);

    const url = await server.start({ onLog: logTo });
    setStatus({ state: 'starting', message: 'Starting local site...', url });
    await server.waitUntilReady();

    setStatus({ state: 'ready', message: '', url });
    window?.loadURL(`${url}/admin`);
  } catch (err) {
    setStatus({ state: 'error', message: err.message });
    if (window) window.loadFile(path.join(__dirname, 'error.html'));
  }
}

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

  window.once('ready-to-show', () => window.show());
  window.webContents.setWindowOpenHandler(({ url }) => {
    shell.openExternal(url);
    return { action: 'deny' };
  });
  window.on('closed', () => {
    window = null;
  });

  window.loadFile(path.join(__dirname, 'loading.html'));
}

function buildMenu() {
  const template = [
    {
      label: app.name,
      submenu: [
        {
          label: 'Open Admin',
          click: () => status.url && window?.loadURL(`${status.url}/admin`),
        },
        {
          label: 'Open Site',
          click: () => status.url && window?.loadURL(status.url),
        },
        {
          label: 'Show Logs',
          click: () => shell.openPath(path.dirname(paths().logFile)),
        },
        {
          label: 'Show Data Folder',
          click: () => shell.openPath(paths().dataDir),
        },
        { role: 'quit' },
      ],
    },
    { role: 'viewMenu' },
  ];

  Menu.setApplicationMenu(Menu.buildFromTemplate(template));
}

const gotLock = app.requestSingleInstanceLock();
if (!gotLock) {
  app.quit();
} else {
  app.on('second-instance', () => window?.focus());

  app.whenReady().then(() => {
    buildMenu();
    createWindow();
    boot();

    app.on('activate', () => {
      if (BrowserWindow.getAllWindows().length === 0) createWindow();
    });
  });

  ipcMain.handle('flareweber:status', () => status);
  ipcMain.handle('flareweber:credentials-file', () => paths().credentialsFile);

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
