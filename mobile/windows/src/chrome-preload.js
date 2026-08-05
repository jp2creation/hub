const { contextBridge, ipcRenderer } = require('electron');

contextBridge.exposeInMainWorld('MartinSolsWindowChrome', {
  openMenu(menuId, point = {}) {
    return ipcRenderer.invoke('martin-sols:show-window-menu', String(menuId || ''), {
      x: Number(point.x) || 0,
      y: Number(point.y) || 34,
    });
  },
});
