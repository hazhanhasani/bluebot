'use strict';

const assert = require('assert');
const fs = require('fs');
const vm = require('vm');
const source = fs.readFileSync('app/js/full-store.js', 'utf8');

function createStore({ localDenied = false, sessionDenied = false, localToken = '', sessionToken = '', memoryToken, initData = 'signed-data' } = {}) {
  const elements = new Map();
  const requests = [];
  function element() {
    return {
      id: '', innerHTML: '',
      classList: { add() {}, remove() {}, toggle() {} },
      setAttribute() {},
      querySelector() { return {}; },
      querySelectorAll() { return []; },
    };
  }
  const document = {
    getElementById: id => elements.get(id) || null,
    createElement: element,
    body: { appendChild: el => elements.set(el.id, el) },
  };
  const window = {
    Telegram: { WebApp: { initData } },
    dispatchEvent() {},
    addEventListener() {},
    removeEventListener() {},
  };
  if (memoryToken !== undefined) window.__BLUEBOT_SESSION_TOKEN__ = memoryToken;
  for (const [name, denied, value] of [
    ['localStorage', localDenied, localToken],
    ['sessionStorage', sessionDenied, sessionToken],
  ]) {
    Object.defineProperty(window, name, {
      get() {
        if (denied) throw new Error('SecurityError: storage access denied');
        return { getItem: () => value };
      },
    });
  }
  vm.runInNewContext(source, {
    window, document, URLSearchParams, setTimeout, clearTimeout,
    CustomEvent: class {},
    fetch: async (url, options) => {
      requests.push({ url, options });
      return { ok: true, text: async () => JSON.stringify({ status: true, obj: { products: [] } }) };
    },
  });
  return { window, requests, elements };
}

async function open(store) {
  store.window.BlueBotFullStore.open();
  // Catalog fetch and rendering are asynchronous; inspect the real open flow.
  for (let i = 0; i < 5; i++) await new Promise(resolve => setImmediate(resolve));
  assert.strictEqual(store.requests.length, 1, 'Store must reach catalog fetch');
  assert(!store.elements.get('bluebot-full-store').innerHTML.includes('SecurityError'));
  return store.requests[0].options.headers;
}

(async () => {
  const denied = await open(createStore({ localDenied: true, sessionDenied: true }));
  assert.strictEqual(denied['X-Telegram-Init-Data'], 'signed-data');
  assert.strictEqual(denied.Authorization, undefined);

  const session = await open(createStore({ localDenied: true, sessionToken: 'session-token' }));
  assert.strictEqual(session.Authorization, 'Bearer session-token');

  const memory = await open(createStore({ localToken: 'stale-token', memoryToken: 'active-token' }));
  assert.strictEqual(memory.Authorization, 'Bearer active-token');

  const loggedOut = createStore({ localToken: 'stale-token' });
  loggedOut.window.BlueBotFullStore.setSessionToken('');
  const cleared = await open(loggedOut);
  assert.strictEqual(cleared.Authorization, undefined, 'Logout must clear storefront token despite stale storage');
  assert.strictEqual(cleared['X-Telegram-Init-Data'], 'signed-data');

  const nested = createStore();
  nested.window.location = { href: 'https://bluebot.example/app/services/example-user' };
  await open(nested);
  assert.strictEqual(new URL(nested.requests[0].url, nested.window.location.href).pathname, '/api/miniapp.php', 'Nested service routes must use the same storefront API');
  console.log('Mini App storefront authentication tests OK.');
})().catch(error => { console.error(error); process.exitCode = 1; });
