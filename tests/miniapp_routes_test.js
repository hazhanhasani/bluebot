'use strict';

const assert = require('assert');
const fs = require('fs');

const html = fs.readFileSync('app/index.php', 'utf8');
const version = fs.readFileSync('app/version', 'utf8').trim();
const urls = [...html.matchAll(/(?:src|href)="([^\"]+)"/g)].map(match => match[1]);
assert(urls.length >= 7, 'The startup document must expose its script/style URLs.');

for (const route of ['/app/', '/app/services/user-123', '/app/buy']) {
  for (const value of urls) {
    const url = new URL(value, 'https://bot.example.com' + route);
    assert(url.pathname.startsWith('/app/js/') || url.pathname.startsWith('/app/assets/'),
      `Startup asset ${value} resolves outside the application assets on ${route}`);
    if (url.pathname.startsWith('/app/js/')) {
      assert.strictEqual(url.searchParams.get('v'), version, 'Bootstrap scripts must use the current app build version.');
    }
    if (url.pathname.endsWith('index-C-2a0Dur.js')) {
      assert.strictEqual(url.search, '', 'The compiled module must preserve its canonical URL identity.');
    }
  }
}

console.log('Mini App nested-route bootstrap tests OK.');
