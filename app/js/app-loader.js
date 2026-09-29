(function () {
  'use strict';

  var bootKey = '__BLUEBOT_MINIAPP_BOOT__';
  var previousBoot = window[bootKey];

  // Keep exactly one canonical module URL for the Vite entry. App chunks import
  // ./index-C-2a0Dur.js without a query string; loading the entry with ?v=...
  // creates a second module identity and can mount React Router twice.
  // Bootstrap is also single-flight for Telegram WebView retries.
  if (previousBoot && previousBoot.started) {
    console.warn('BlueBot Mini App duplicate bootstrap ignored', previousBoot);
    return;
  }

  var bootState = {
    started: true,
    loaded: false,
    failed: false,
    version: '0.1.7',
    startedAt: Date.now()
  };
  window[bootKey] = bootState;

  var finished = false;
  var failureShown = false;

  function escapeHtml(value) {
    return String(value).replace(/[&<>"]/g, function (ch) {
      return {
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;'
      }[ch];
    });
  }

  function rootLooksUninitialized() {
    var root = document.getElementById('root');
    if (!root) return true;
    return root.children.length === 0 || !!root.querySelector('.bluebot-boot');
  }

  function showLoadError(reason) {
    if (failureShown || (finished && !rootLooksUninitialized())) return;
    failureShown = true;

    var root = document.getElementById('root');
    if (!root) return;

    var diagnostic = reason && reason.message
      ? String(reason.message).slice(0, 180)
      : '';

    bootState.failed = true;
    bootState.error = diagnostic || 'Unknown startup error';

    root.innerHTML =
      '<div class="bluebot-boot">' +
        '<div class="bluebot-boot-card bluebot-boot-error">' +
          '<strong>پنل کاربری کامل بارگذاری نشد</strong>' +
          '<span>اتصال به فایل‌های Mini App یا محیط WebView تلگرام با خطا روبه‌رو شد.</span>' +
          (diagnostic ? '<small>' + escapeHtml(diagnostic) + '</small>' : '') +
          '<button type="button" id="bluebot-retry">تلاش مجدد</button>' +
        '</div>' +
      '</div>';

    var retry = document.getElementById('bluebot-retry');
    if (retry) {
      retry.addEventListener('click', function () {
        window.location.reload();
      });
    }
  }

  window.addEventListener('error', function (event) {
    if (rootLooksUninitialized()) {
      showLoadError(event.error || new Error(event.message || 'JavaScript load error'));
    }
  });

  window.addEventListener('unhandledrejection', function (event) {
    if (rootLooksUninitialized()) {
      showLoadError(event.reason instanceof Error ? event.reason : new Error(String(event.reason || 'Promise rejected')));
    }
  });

  // If React clears the server boot surface and then crashes, never leave the
  // user staring at a permanent black page.
  window.setTimeout(function () {
    if (rootLooksUninitialized()) {
      showLoadError(new Error('Startup timeout'));
    }
  }, 10000);

  import('../assets/index-C-2a0Dur.js')
    .then(function () {
      finished = true;
      bootState.loaded = true;
      bootState.failed = false;
      bootState.finishedAt = Date.now();
    })
    .catch(function (error) {
      console.error('BlueBot Mini App bundle failed to load', error);
      showLoadError(error);
    });
})();
