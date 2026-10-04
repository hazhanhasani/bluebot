'use strict';

const assert = require('assert');
const fs = require('fs');
const vm = require('vm');

const source = fs.readFileSync('app/assets/index-C-2a0Dur.js', 'utf8');
const providerStart = source.indexOf('function dh({children:b})');
const providerEnd = source.indexOf('var vh=', providerStart);
assert(providerStart >= 0 && providerEnd > providerStart, 'Auth provider must be present in the shipped bundle');
const providerSource = source.slice(providerStart, providerEnd);

function restore(cached, launchUser, rawCache = JSON.stringify(cached)) {
  const data = new Map([['userData', rawCache]]);
  const states = [];
  const effects = [];
  const published = [];
  let stateIndex = 0;
  const window = {
    __BLUEBOT_SESSION_TOKEN__: 'previous-memory-token',
    BlueBotFullStore: { setSessionToken(token) { published.push(token); } },
  };
  const context = {
    window,
    xh: () => [{ user: launchUser }, 'signed-launch-data'],
    B0: {
      useState(initial) {
        const index = stateIndex++;
        states[index] = initial;
        return [initial, value => { states[index] = value; }];
      },
      useEffect(effect) { effects.push(effect); },
    },
    C0: { jsx: (_, props) => props },
    $x: { Provider: {} },
    BBs: {
      getItem: key => data.get(key) || null,
      removeItem: key => data.delete(key),
    },
    e1: value => value,
    rh: async () => ({}),
    setTimeout: () => 1,
    clearTimeout() {},
    console: { error() {} },
  };
  vm.createContext(context);
  const provider = vm.runInContext(providerSource + ';dh({children:null})', context);
  effects.forEach(effect => effect());
  return { data, states, published, window, provider };
}

const validCache = { user: { id: 42 }, token: 'user-42-token', expiresAt: Date.now() + 60000 };
for (const id of [42, '42']) {
  const result = restore(validCache, { id });
  assert.strictEqual(result.states[0].id, 42);
  assert.strictEqual(result.states[1], 'user-42-token');
  assert.deepStrictEqual(result.published, ['user-42-token']);
  result.provider.value.logout();
  assert.strictEqual(result.window.__BLUEBOT_SESSION_TOKEN__, '');
  assert.deepStrictEqual(result.published, ['user-42-token', '']);
}

for (const [cached, launch] of [
  [validCache, { id: 99 }],
  [validCache, undefined],
  [{ ...validCache, expiresAt: Date.now() - 1 }, { id: 42 }],
  [{ ...validCache, expiresAt: undefined }, { id: 42 }],
  [{ ...validCache, user: undefined }, { id: 42 }],
  [{ ...validCache, token: '' }, { id: 42 }],
  [null, { id: 42 }],
]) {
  const result = restore(cached, launch);
  assert.strictEqual(result.states[0], undefined, 'Invalid or other-account cache must not restore a user');
  assert.strictEqual(result.states[1], null, 'Invalid or other-account cache must not restore its token');
  assert.strictEqual(result.data.has('userData'), false);
  assert.strictEqual(result.window.__BLUEBOT_SESSION_TOKEN__, '');
  assert.deepStrictEqual(result.published, ['']);
}

const malformed = restore(null, { id: 42 }, 'invalid-encrypted-session');
assert.strictEqual(malformed.data.has('userData'), false);
assert.strictEqual(malformed.window.__BLUEBOT_SESSION_TOKEN__, '');
assert.deepStrictEqual(malformed.published, ['']);

const interceptorStart = source.indexOf('Ba.interceptors.response.use(');
const interceptorEnd = source.indexOf('Ba.interceptors.request.use(', interceptorStart);
assert(interceptorStart >= 0 && interceptorEnd > interceptorStart);
const interceptorSource = source.slice(interceptorStart, interceptorEnd);

async function check403(message, clearSession) {
  let onError;
  const removed = [];
  const published = [];
  const window = {
    location: { pathname: '/app/buy' },
    __BLUEBOT_SESSION_TOKEN__: 'valid-token',
    BlueBotFullStore: { setSessionToken(token) { published.push(token); } },
  };
  vm.runInNewContext(interceptorSource, {
    Ba: { interceptors: { response: { use: (_, failure) => { onError = failure; } } } },
    BBs: { removeItem: key => removed.push(key) },
    window,
    Promise,
  });
  const error = { response: { status: 403, data: { msg: message } } };
  await assert.rejects(onError(error), value => value === error);
  assert.strictEqual(removed.length, clearSession ? 1 : 0);
  assert.strictEqual(window.__BLUEBOT_SESSION_TOKEN__, clearSession ? '' : 'valid-token');
  assert.strictEqual(window.location.href, clearSession ? '/app/' : undefined);
  assert.deepStrictEqual(published, clearSession ? [''] : []);
}

(async () => {
  await check403('panel not found or unavailable', false);
  await check403('Service unavailable', false);
  await check403('Authentication required', true);
  console.log('Mini App session identity and authorization tests OK.');
})().catch(error => { console.error(error); process.exitCode = 1; });
