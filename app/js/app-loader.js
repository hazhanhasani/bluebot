(function () {
  'use strict';

  function showLoadError() {
    var root = document.getElementById('root');
    if (!root) return;

    root.innerHTML =
      '<div class="bluebot-boot">' +
        '<div class="bluebot-boot-card" style="flex-direction:column;text-align:center">' +
          '<strong>بارگذاری پنل کامل نشد</strong>' +
          '<span style="opacity:.75">اتصال CDN یا اینترنت را بررسی کرده و دوباره تلاش کنید.</span>' +
          '<button type="button" id="bluebot-retry" style="border:0;border-radius:10px;padding:9px 14px;cursor:pointer">تلاش مجدد</button>' +
        '</div>' +
      '</div>';

    var retry = document.getElementById('bluebot-retry');
    if (retry) {
      retry.addEventListener('click', function () {
        window.location.reload();
      });
    }
  }

  import('../assets/index-C-2a0Dur.js?v=0.1.4').catch(function (error) {
    console.error('BlueBot Mini App bundle failed to load', error);
    showLoadError();
  });
})();
