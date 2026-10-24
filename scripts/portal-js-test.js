// Test sans dépendance de la logique pure du portail : node scripts/portal-js-test.js
const assert = require('node:assert');
const p = require('../backend-overlay/resources/portal/portal.js');

const allowed = ['http://192.168.88.1/login', 'http://10.5.50.1:8080/login?x=1', 'http://172.16.0.1/login', 'http://172.31.255.1/', 'https://login/x', 'http://hotspot.lan/login', 'http://100.64.0.1/'];
const refused = ['http://172.32.0.1/login', 'http://evil.com/login', 'http://192.168.1.1.evil.com/login', 'http://user@192.168.1.1/', 'javascript:alert(1)', 'http://256.1.1.1/', '', 'http://8.8.8.8/', '//192.168.1.1/', 'http://evil.com#@192.168.1.1/', 'http://192.168.1.1@evil.com/'];
for (const u of allowed) assert.strictEqual(p.isAllowedLoginUrl(u), true, u);
for (const u of refused) assert.strictEqual(p.isAllowedLoginUrl(u), false, u);

assert.strictEqual(p.formatMoney(12500, 'FCFA', 0), '12 500 FCFA');
assert.strictEqual(p.formatMoney(1050, 'USD', 2), '10.50 USD');
assert.strictEqual(p.formatMoney(5, 'USD', 2), '0.05 USD');
const L = { unlimited: 'U', day: 'd', hour: 'h', min: 'min' };
assert.strictEqual(p.formatDuration(90, L), '1 h 30 min');
assert.strictEqual(p.formatDuration(1440, L), '1 d');
assert.strictEqual(p.formatDuration(null, L), 'U');
console.log('portal.js : OK');
