'use strict';

const { contextBridge, ipcRenderer } = require('electron');

contextBridge.exposeInMainWorld('flareweber', {
  getStatus: () => ipcRenderer.invoke('flareweber:status'),
  onStatus: (callback) => {
    const listener = (_event, status) => callback(status);
    ipcRenderer.on('flareweber:status', listener);
    return () => ipcRenderer.removeListener('flareweber:status', listener);
  },
  credentialsFile: () => ipcRenderer.invoke('flareweber:credentials-file'),
  stripeRedirectUri: () => ipcRenderer.invoke('flareweber:stripe-redirect-uri'),
  // Emitted when the OS hands back an OAuth callback (flareweber://...).
  onOauthLink: (callback) => {
    const listener = (_event, url) => callback(url);
    ipcRenderer.on('flareweber:oauth-link', listener);
    return () => ipcRenderer.removeListener('flareweber:oauth-link', listener);
  },
});
