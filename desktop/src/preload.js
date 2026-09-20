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
});
