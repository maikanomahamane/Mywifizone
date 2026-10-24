// Tests du script du portail captif (sans navigateur) : node --test backend-overlay/tests/js
const test = require('node:test');
const assert = require('node:assert');
const { isAllowedLoginUrl, formatMoney, formatDuration } = require('../../resources/portal/portal.js');

test('seules les adresses de réseau local reçoivent le code', () => {
  for (const u of ['http://10.5.50.1/login', 'http://192.168.88.1/login', 'http://172.16.0.1/login', 'http://hotspot.lan/login', 'http://wifi/login', 'http://100.64.1.1:8080/login', 'http://169.254.1.1/x']) {
    assert.ok(isAllowedLoginUrl(u), u);
  }
  for (const u of ['http://evil.com/login', 'https://8.8.8.8/x', 'http://172.32.0.1/x', 'http://10.0.0.1@evil.com/', 'javascript:alert(1)', '', 'http://256.1.1.1/', '//evil.com', 'http://192.168.1.1.evil.com/']) {
    assert.ok(!isAllowedLoginUrl(u), u);
  }
});

test('montants et durées', () => {
  const nb = ' ';
  const norm = (s) => s.replace(/\s/g, ' ');
  assert.strictEqual(norm(formatMoney(12500, 'XOF', 0)), '12 500 XOF');
  assert.strictEqual(norm(formatMoney(1050, 'USD', 2)), '10.50 USD');
  assert.strictEqual(norm(formatMoney(5, 'EUR', 2)), '0.05 EUR');
  const L = { unlimited: 'u', day: 'd', hour: 'h', min: 'm' };
  assert.strictEqual(formatDuration(90, L), '1 h 30 m');
  assert.strictEqual(formatDuration(1440, L), '1 d');
  assert.strictEqual(formatDuration(null, L), 'u');
  assert.ok(nb.length === 1);
});
