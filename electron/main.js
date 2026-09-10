const { app, BrowserWindow, shell } = require('electron');
const { startServer } = require('./server');

let mainWindow;
let serverInstance;
let serverPort;

// `npm run dev` passes --dev: open DevTools alongside the window.
const isDev = process.argv.includes('--dev');

// Only the local express origin may load inside the app window.
function isLocalUrl(url) {
  try {
    const u = new URL(url);
    return u.protocol === 'http:'
      && (u.hostname === 'localhost' || u.hostname === '127.0.0.1')
      && u.port === String(serverPort);
  } catch (e) {
    return false;
  }
}

// External links (archive.org, help pages, fonts…) go to the system browser;
// anything that is neither local nor https is dropped.
function openExternal(url) {
  if (/^https:\/\//i.test(url)) shell.openExternal(url);
}

async function createWindow() {
  if (!serverInstance) {
    const started = await startServer();
    serverInstance = started.server;
    serverPort = started.port;
  }

  mainWindow = new BrowserWindow({
    width: 1280,
    height: 800,
    minWidth: 800,
    minHeight: 600,
    show: false,
    webPreferences: {
      nodeIntegration: false,
      contextIsolation: true,
      sandbox: true
    }
  });

  // Keep navigation inside the local app.
  mainWindow.webContents.on('will-navigate', (event, url) => {
    if (isLocalUrl(url)) return;
    event.preventDefault();
    openExternal(url);
  });

  // target="_blank" / window.open: local URLs load in this (single) window,
  // external https links open in the system browser, nothing else opens.
  mainWindow.webContents.setWindowOpenHandler(({ url }) => {
    if (isLocalUrl(url)) {
      mainWindow.loadURL(url);
    } else {
      openExternal(url);
    }
    return { action: 'deny' };
  });

  mainWindow.loadURL(`http://localhost:${serverPort}`);

  mainWindow.once('ready-to-show', () => {
    mainWindow.show();
    if (isDev) mainWindow.webContents.openDevTools({ mode: 'detach' });
  });

  mainWindow.on('closed', () => {
    mainWindow = null;
  });
}

function stopServer() {
  if (!serverInstance) return;
  const server = serverInstance;
  serverInstance = null;
  // Drop keep-alive connections so close() doesn't wait on them.
  if (typeof server.closeAllConnections === 'function') server.closeAllConnections();
  server.close();
}

app.whenReady().then(createWindow);

app.on('window-all-closed', () => {
  stopServer();
  app.quit();
});

app.on('before-quit', stopServer);

app.on('activate', () => {
  if (BrowserWindow.getAllWindows().length === 0) {
    createWindow();
  }
});
