import { contextBridge, ipcRenderer } from 'electron'

contextBridge.exposeInMainWorld('electronAPI', {
  getVersion: () => ipcRenderer.invoke('app:version'),
  checkUpdate: () => ipcRenderer.invoke('app:check-update'),
  installUpdate: () => ipcRenderer.invoke('app:install-update'),
  quit: () => ipcRenderer.invoke('app:quit'),

  // Updater event listeners
  onUpdaterChecking: (cb: () => void) => ipcRenderer.on('updater:checking', cb),
  onUpdaterAvailable: (cb: (_e: unknown, info: unknown) => void) => ipcRenderer.on('updater:available', cb),
  onUpdaterNotAvailable: (cb: () => void) => ipcRenderer.on('updater:not-available', cb),
  onUpdaterProgress: (cb: (_e: unknown, progress: unknown) => void) => ipcRenderer.on('updater:progress', cb),
  onUpdaterDownloaded: (cb: (_e: unknown, info: unknown) => void) => ipcRenderer.on('updater:downloaded', cb),
  onUpdaterError: (cb: (_e: unknown, msg: string) => void) => ipcRenderer.on('updater:error', cb),
})
