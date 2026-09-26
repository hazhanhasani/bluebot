<!DOCTYPE html>
<html lang="fa" dir="rtl">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
    <meta name="theme-color" content="#111111" />
    <meta name="color-scheme" content="dark light" />
    <title>BlueBot Web App</title>

    <link rel="preload" href="./js/telegram-web-app.js?v=0.1.4" as="script" />
    <link rel="preload" href="./js/telegram-bootstrap.js?v=0.1.4" as="script" />
    <link rel="modulepreload" crossorigin href="./assets/index-C-2a0Dur.js?v=0.1.4" />
    <link rel="modulepreload" crossorigin href="./assets/vendor-CIGJ9g2q.js" />
    <link rel="stylesheet" crossorigin href="./assets/index-BoHBsj0Z.css" />

    <style>
      html,body,#root{min-height:100%;margin:0}
      body{background:#111;color:#fff;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
      .bluebot-boot{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;box-sizing:border-box}
      .bluebot-boot-card{display:flex;align-items:center;gap:12px;padding:14px 18px;border:1px solid rgba(255,255,255,.10);border-radius:16px;background:rgba(255,255,255,.04);font-size:14px}
      .bluebot-boot-spinner{width:20px;height:20px;border:2px solid rgba(255,255,255,.22);border-top-color:#fff;border-radius:50%;animation:bluebot-spin .75s linear infinite}
      @keyframes bluebot-spin{to{transform:rotate(360deg)}}
    </style>

    <script defer src="./js/telegram-web-app.js?v=0.1.4"></script>
    <script defer src="./js/telegram-bootstrap.js?v=0.1.4"></script>
    <script type="module" crossorigin src="./assets/index-C-2a0Dur.js?v=0.1.4"></script>
  </head>
  <body>
    <div id="root">
      <div class="bluebot-boot" aria-live="polite">
        <div class="bluebot-boot-card">
          <span class="bluebot-boot-spinner" aria-hidden="true"></span>
          <span>در حال باز کردن پنل کاربری…</span>
        </div>
      </div>
    </div>
  </body>
</html>
