'use strict';

const fs = require('fs');
const vm = require('vm');

const source = fs.readFileSync('app/js/telegram-bootstrap.js', 'utf8');

function run(search, hash, nativeInitData = '') {
  let readyCalls = 0;
  let expandCalls = 0;

  const nativeWebApp = {
    initData: nativeInitData,
    initDataUnsafe: nativeInitData ? { user: { id: 1 } } : {},
    ready() { readyCalls += 1; },
    expand() { expandCalls += 1; },
  };

  const window = {
    location: { search, hash },
    Telegram: { WebApp: nativeWebApp },
  };

  vm.runInNewContext(source, {
    window,
    URLSearchParams,
    Object,
    JSON,
  });

  return {
    webApp: window.Telegram.WebApp,
    nativeWebApp,
    readyCalls,
    expandCalls,
  };
}

const raw = 'auth_date=1700000000&user=%7B%22id%22%3A42%2C%22first_name%22%3A%22Blue%22%7D&hash=abc';
const encoded = encodeURIComponent(raw);

const queryCase = run('?tgWebAppData=' + encoded, '');
if (queryCase.webApp.initData !== raw) {
  throw new Error('Query-string tgWebAppData fallback failed.');
}
if (!queryCase.webApp.initDataUnsafe.user || queryCase.webApp.initDataUnsafe.user.id !== 42) {
  throw new Error('Query-string user parsing failed.');
}

const hashCase = run('', '#/app?tgWebAppData=' + encoded + '&tgWebAppVersion=10.1');
if (hashCase.webApp.initData !== raw) {
  throw new Error('Path-style hash tgWebAppData fallback failed.');
}

const nativeCase = run('?tgWebAppData=' + encoded, '', 'native-data');
if (nativeCase.webApp !== nativeCase.nativeWebApp || nativeCase.webApp.initData !== 'native-data') {
  throw new Error('Native Telegram initData must remain authoritative.');
}

for (const result of [queryCase, hashCase, nativeCase]) {
  if (result.readyCalls !== 1 || result.expandCalls !== 1) {
    throw new Error('Telegram WebApp lifecycle initialization failed.');
  }
}

console.log('Telegram Mini App bootstrap tests OK.');
