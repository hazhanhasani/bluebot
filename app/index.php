<!DOCTYPE html>
<html lang="fa" dir="rtl">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
    <meta name="theme-color" content="#111111" />
    <meta name="color-scheme" content="dark light" />
    <title>BlueBot Web App</title>

    <link rel="preload" href="./js/telegram-web-app.js?v=0.1.5" as="script" />
    <link rel="preload" href="./js/telegram-bootstrap.js?v=0.1.5" as="script" />
    <link rel="modulepreload" crossorigin href="./assets/index-C-2a0Dur.js?v=0.1.5" />
    <link rel="modulepreload" crossorigin href="./assets/vendor-CIGJ9g2q.js" />
    <link rel="stylesheet" crossorigin href="./assets/index-BoHBsj0Z.css" />

    <style>
      html,body,#root{min-height:100%;margin:0}
      body{background:#111;color:#fff;font-family:system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
      .bluebot-boot{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;box-sizing:border-box;background:radial-gradient(circle at 50% 35%,rgba(33,150,243,.14),transparent 38%)}
      .bluebot-boot-card{display:flex;align-items:center;gap:12px;padding:16px 20px;border:1px solid rgba(89,180,255,.30);border-radius:18px;background:rgba(22,32,48,.92);box-shadow:0 16px 50px rgba(0,0,0,.28);font-size:14px;color:#f5fbff}
      .bluebot-boot-error{max-width:360px;flex-direction:column;text-align:center;line-height:1.8}
      .bluebot-boot-error small{direction:ltr;max-width:100%;overflow-wrap:anywhere;color:#9dc9e9}
      .bluebot-boot-error button{border:0;border-radius:12px;padding:10px 16px;background:#2aa8ff;color:#fff;font-weight:700;cursor:pointer}
      .bluebot-boot-spinner{width:22px;height:22px;border:2px solid rgba(255,255,255,.22);border-top-color:#53c9ff;border-radius:50%;animation:bluebot-spin .75s linear infinite}
      @keyframes bluebot-spin{to{transform:rotate(360deg)}}
    </style>

    <script defer src="./js/telegram-web-app.js?v=0.1.5"></script>
    <script defer src="./js/telegram-bootstrap.js?v=0.1.5"></script>
    <script defer src="./js/app-loader.js?v=0.1.5"></script>
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
