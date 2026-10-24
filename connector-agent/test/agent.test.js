import { test } from 'node:test';
import assert from 'node:assert/strict';
import http from 'node:http';
import { RouterOsClient } from '../src/routeros.js';
import { CloudClient } from '../src/cloud.js';
import { Agent } from '../src/agent.js';
import { parseDuration, formatMinutes } from '../src/duration.js';

const silent = { warn() {}, log() {} };

/** Simulateur minimal de l'API REST RouterOS (hotspot) : état en mémoire. */
function mockRouterOs() {
  const state = {
    profiles: [], users: [], active: [], calls: [], seq: 1,
    resource: { 'board-name': 'hAP ax2', version: '7.15', 'cpu-load': '7', 'free-memory': '1000', 'total-memory': '2000', uptime: '1d2h' },
  };
  const server = http.createServer((req, res) => {
    let body = '';
    req.on('data', (c) => (body += c));
    req.on('end', () => {
      assert.match(req.headers.authorization, /^Basic /);
      const json = body ? JSON.parse(body) : null;
      state.calls.push({ method: req.method, url: req.url, body: json });
      const send = (code, data) => { res.writeHead(code, { 'Content-Type': 'application/json' }); res.end(data === undefined ? '' : JSON.stringify(data)); };
      const [, , ...rest] = req.url.split('/');
      const path = '/' + rest.join('/');
      const m = path.match(/^\/(ip\/hotspot\/(?:user\/profile|user|active))(?:\/(.+))?$/);

      if (path === '/system/resource') return send(200, state.resource);
      if (!m) return send(404, { error: 404 });
      const coll = { 'ip/hotspot/user/profile': state.profiles, 'ip/hotspot/user': state.users, 'ip/hotspot/active': state.active }[m[1]];
      const id = m[2] && decodeURIComponent(m[2]);
      if (req.method === 'GET') return send(200, coll);
      if (req.method === 'PUT') {
        if (coll.some((x) => x.name === json.name)) return send(400, { error: 400, message: 'already have such entry' });
        const item = { '.id': `*${(state.seq++).toString(16).toUpperCase()}`, ...json };
        coll.push(item);
        return send(201, item);
      }
      const idx = coll.findIndex((x) => x['.id'] === id);
      if (idx < 0) return send(404, { error: 404 });
      if (req.method === 'PATCH') { Object.assign(coll[idx], json); return send(200, coll[idx]); }
      if (req.method === 'DELETE') { coll.splice(idx, 1); return send(204); }
      return send(405, {});
    });
  });
  return new Promise((resolve) => server.listen(0, '127.0.0.1', () => resolve({ server, state, url: `http://127.0.0.1:${server.address().port}` })));
}

/** Simulateur du cloud : file de commandes + journal des résultats. */
function mockCloud(commands) {
  const state = { queue: [...commands], results: {}, heartbeats: [], tokens: [] };
  const server = http.createServer((req, res) => {
    let body = '';
    req.on('data', (c) => (body += c));
    req.on('end', () => {
      state.tokens.push(req.headers.authorization);
      const json = body ? JSON.parse(body) : {};
      const ok = (data) => { res.writeHead(200, { 'Content-Type': 'application/json' }); res.end(JSON.stringify({ success: true, data })); };
      if (req.url.endsWith('/agent/poll')) { const out = state.queue.splice(0); return ok(out); }
      if (req.url.endsWith('/agent/heartbeat')) { state.heartbeats.push(json); return ok({}); }
      const m = req.url.match(/commands\/([^/]+)\/result$/);
      if (m) { state.results[m[1]] = json; return ok({ status: 'SUCCEEDED' }); }
      res.writeHead(404); res.end('{}');
    });
  });
  return new Promise((resolve) => server.listen(0, '127.0.0.1', () => resolve({ server, state, url: `http://127.0.0.1:${server.address().port}/api/v1` })));
}

const UUID = '0192f5a0-7b1c-7d3e-8a4f-123456789abc';
async function setup(commands) {
  const ros = await mockRouterOs();
  const cloud = await mockCloud(commands);
  const agent = new Agent({
    cloud: new CloudClient({ baseUrl: cloud.url, token: 'wza_test' }),
    ros: new RouterOsClient({ baseUrl: ros.url, user: 'api', password: 'pw' }),
    log: silent, heartbeatIntervalMs: 0,
  });
  const close = () => { ros.server.close(); cloud.server.close(); };
  return { ros, cloud, agent, close };
}

test('durées RouterOS', () => {
  assert.equal(parseDuration('1w2d3h4m5s'), 604800 + 172800 + 10800 + 240 + 5);
  assert.equal(parseDuration('00:30:00'), 1800);
  assert.equal(parseDuration(''), 0);
  assert.equal(formatMinutes(90), '1h30m');
  assert.equal(formatMinutes(60), '1h');
  assert.equal(formatMinutes(45), '45m');
});

test('profil + création de vouchers : champs envoyés en JSON et résultat conforme au contrat', async () => {
  const { ros, cloud, agent, close } = await setup([
    { id: 'c1', type: 'SYNC_PROFILES', payload: { profiles: [{ name: 'wz-56789abc', rate_limit: '2M/2M', shared_users: 1 }] } },
    { id: 'c2', type: 'CREATE_VOUCHER', payload: { voucher_ids: [UUID], vouchers: [
      { voucher_id: UUID, code: 'ABCDEF23', profile: 'wz-56789abc', limit_uptime_minutes: 90, limit_bytes_total_mb: 100, comment: `wz:${UUID}` },
    ] } },
  ]);
  try {
    await agent.tick();
    assert.equal(ros.state.profiles[0].name, 'wz-56789abc');
    assert.equal(ros.state.profiles[0]['rate-limit'], '2M/2M');
    const u = ros.state.users[0];
    assert.deepEqual([u.name, u.password, u.profile, u['limit-uptime'], u['limit-bytes-total'], u.comment],
      ['ABCDEF23', 'ABCDEF23', 'wz-56789abc', '1h30m', String(100 * 1024 * 1024), `wz:${UUID}`]);
    assert.deepEqual(cloud.state.results.c2, { success: true, result: { created: ['ABCDEF23'], failed: [] } });
  } finally { close(); }
});

test('rejeu de CREATE_VOUCHER : idempotent (aucun doublon, code confirmé)', async () => {
  const cmd = (id) => ({ id, type: 'CREATE_VOUCHER', payload: { vouchers: [{ voucher_id: UUID, code: 'ABCDEF23', profile: 'wz-56789abc' }] } });
  const { ros, cloud, agent, close } = await setup([cmd('c1')]);
  try {
    await agent.tick();
    cloud.state.queue.push(cmd('c2'));
    await agent.tick();
    assert.equal(ros.state.users.length, 1);
    assert.deepEqual(cloud.state.results.c2.result.created, ['ABCDEF23']);
  } finally { close(); }
});

test('INJECTION : valeurs piégées refusées sans aucun appel RouterOS, échec définitif', async () => {
  const { ros, cloud, agent, close } = await setup([
    { id: 'a', type: 'CREATE_VOUCHER', payload: { vouchers: [{ voucher_id: UUID, code: 'ABC; /system reboot', profile: 'wz-56789abc' }] } },
    { id: 'b', type: 'CREATE_VOUCHER', payload: { vouchers: [{ voucher_id: UUID, code: 'ABCDEF23\n', profile: 'wz-56789abc' }] } },
    { id: 'c', type: 'CREATE_VOUCHER', payload: { vouchers: [{ voucher_id: UUID, code: 'ABCDEF23', profile: 'default; /user' }] } },
    { id: 'd', type: 'SYNC_PROFILES', payload: { profiles: [{ name: 'wz-56789abc', rate_limit: '2M; /system reset', shared_users: 1 }] } },
    { id: 'e', type: 'DISCONNECT_USER', payload: { session_id: '*1 ; /x', username: 'X' } },
    { id: 'f', type: 'RUN_SCRIPT', payload: { source: '/system reset-configuration' } },
  ]);
  try {
    await agent.tick();
    for (const id of ['a', 'b', 'c', 'd', 'e', 'f']) {
      assert.equal(cloud.state.results[id].success, false, id);
      assert.equal(cloud.state.results[id].retryable, false, id);
    }
    assert.equal(ros.state.users.length, 0);
    assert.equal(ros.state.profiles.length, 0);
    assert.equal(ros.state.calls.filter((c) => c.method !== 'GET').length, 0);
  } finally { close(); }
});

test('révocation : supprime l\'utilisateur et coupe sa session active', async () => {
  const { ros, cloud, agent, close } = await setup([{ id: 'r', type: 'REVOKE_VOUCHER', payload: { voucher_id: UUID, code: 'ABCDEF23' } }]);
  try {
    ros.state.users.push({ '.id': '*A', name: 'ABCDEF23', comment: `wz:${UUID}` }, { '.id': '*B', name: 'ZZZZZZ22' });
    ros.state.active.push({ '.id': '*10', user: 'ABCDEF23' }, { '.id': '*11', user: 'ZZZZZZ22' });
    await agent.tick();
    assert.deepEqual(ros.state.users.map((u) => u.name), ['ZZZZZZ22']);
    assert.deepEqual(ros.state.active.map((a) => a.user), ['ZZZZZZ22']);
    assert.equal(cloud.state.results.r.success, true);
  } finally { close(); }
});

test('déconnexion : refusée si l\'identifiant désigne un autre utilisateur', async () => {
  const { ros, agent, close } = await setup([{ id: 'd', type: 'DISCONNECT_USER', payload: { session_id: '*10', username: 'AAAAAA22' } }]);
  try {
    ros.state.active.push({ '.id': '*10', user: 'BBBBBB22' });
    await agent.tick();
    assert.equal(ros.state.active.length, 1);
  } finally { close(); }
});

test('heartbeat : santé, sessions et codes épuisés (temps ou volume)', async () => {
  const { ros, cloud, agent, close } = await setup([]);
  try {
    ros.state.active.push({ '.id': '*1A', user: 'ABCDEF23', address: '10.5.50.10', 'mac-address': 'AA:BB:CC:DD:EE:FF', uptime: '5m10s', 'bytes-in': '1000', 'bytes-out': '2000' });
    ros.state.users.push(
      { '.id': '*A', name: 'ABCDEF23', comment: `wz:${UUID}`, 'limit-uptime': '1h', uptime: '5m10s' },
      { '.id': '*B', name: 'TMEUTK22', comment: `wz:${UUID}`, 'limit-uptime': '1h', uptime: '1h' },
      { '.id': '*C', name: 'BYTES222', comment: `wz:${UUID}`, 'limit-bytes-total': '1000', 'bytes-in': '600', 'bytes-out': '500' },
      { '.id': '*D', name: 'MANUAL22', comment: 'créé à la main', 'limit-uptime': '1h', uptime: '2h' }, // pas un voucher WIFIZONE
    );
    await agent.tick();
    const hb = cloud.state.heartbeats[0];
    assert.equal(hb.health.board_name, 'hAP ax2');
    assert.equal(hb.health.cpu_load, 7);
    assert.equal(hb.health.uptime_seconds, 93600);
    assert.deepEqual(hb.sessions, [{ id: '*1A', username: 'ABCDEF23', address: '10.5.50.10', mac: 'AA:BB:CC:DD:EE:FF', uptime_seconds: 310, bytes_in: 1000, bytes_out: 2000 }]);
    assert.deepEqual(hb.finished_codes.sort(), ['BYTES222', 'TMEUTK22']);
  } finally { close(); }
});

test('routeur injoignable : le heartbeat part sans santé ni sessions, la commande échoue en réessayable', async () => {
  const ros = await mockRouterOs();
  const cloud = await mockCloud([{ id: 'h', type: 'FETCH_HEALTH', payload: {} }]);
  const url = ros.url;
  ros.server.close();
  const agent = new Agent({
    cloud: new CloudClient({ baseUrl: cloud.url, token: 'wza_test' }),
    ros: new RouterOsClient({ baseUrl: url, user: 'api', password: 'pw', timeoutMs: 500 }),
    log: silent, heartbeatIntervalMs: 0,
  });
  try {
    await agent.tick();
    assert.equal(cloud.state.results.h.success, false);
    assert.equal(cloud.state.results.h.retryable, true);
    assert.deepEqual(cloud.state.heartbeats[0], {});
  } finally { cloud.server.close(); }
});

test('le jeton est envoyé en Bearer au cloud', async () => {
  const { cloud, agent, close } = await setup([]);
  try {
    await agent.tick();
    assert.ok(cloud.state.tokens.every((t) => t === 'Bearer wza_test'));
  } finally { close(); }
});
