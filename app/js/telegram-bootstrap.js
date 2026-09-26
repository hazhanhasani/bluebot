(function () {
  'use strict';

  function queryParam(source, key) {
    if (!source) return '';
    var value = new URLSearchParams(source.replace(/^[?#]/, '')).get(key);
    return typeof value === 'string' ? value : '';
  }

  function locationInitData() {
    return queryParam(window.location.search, 'tgWebAppData')
      || queryParam(window.location.hash, 'tgWebAppData')
      || '';
  }

  function parseInitData(raw) {
    var parsed = {};
    if (!raw) return parsed;

    var params = new URLSearchParams(raw);
    params.forEach(function (value, key) {
      parsed[key] = value;
    });

    ['user', 'receiver', 'chat'].forEach(function (key) {
      if (!parsed[key] || typeof parsed[key] !== 'string') return;
      try {
        parsed[key] = JSON.parse(parsed[key]);
      } catch (_) {
        // Keep the raw value; the backend remains the authority.
      }
    });

    return parsed;
  }

  var telegram = window.Telegram && window.Telegram.WebApp
    ? window.Telegram.WebApp
    : null;

  if (!telegram) {
    return;
  }

  try {
    telegram.ready();
  } catch (_) {}

  try {
    telegram.expand();
  } catch (_) {}

  var nativeInitData = typeof telegram.initData === 'string'
    ? telegram.initData
    : '';

  if (nativeInitData) {
    return;
  }

  var rawInitData = locationInitData();
  if (!rawInitData) {
    return;
  }

  // Some older embedded SDK builds only inspect the URL hash. Newer Telegram
  // clients can expose launch parameters through other URL forms. Wrap the
  // WebApp object without mutating its non-configurable native properties.
  var compatibleWebApp = Object.create(telegram);

  Object.defineProperty(compatibleWebApp, 'initData', {
    value: rawInitData,
    enumerable: true,
  });

  Object.defineProperty(compatibleWebApp, 'initDataUnsafe', {
    value: parseInitData(rawInitData),
    enumerable: true,
  });

  window.Telegram.WebApp = compatibleWebApp;
})();
