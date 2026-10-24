import { CODE_RE, PROFILE_RE, RATE_RE, UUID_RE, SESSION_ID_RE, assert } from './validate.js';
import { parseDuration, formatMinutes } from './duration.js';

const MB = 1024 * 1024;

/** Exécute une commande du cloud et retourne son `result` (ou lève une erreur). */
export async function execute(ros, command) {
  const handler = HANDLERS[command.type];
  assert(handler, `Type de commande inconnu : ${command.type}`);
  return handler(ros, command.payload ?? {});
}

const HANDLERS = {
  async SYNC_PROFILES(ros, { profiles }) {
    assert(Array.isArray(profiles) && profiles.length <= 200, 'profiles invalide');
    const existing = new Map((await ros.profiles()).map((p) => [p.name, p]));
    for (const p of profiles) {
      assert(PROFILE_RE.test(p.name), 'nom de profil invalide');
      assert(p.rate_limit == null || RATE_RE.test(p.rate_limit), 'débit invalide');
      assert(Number.isInteger(p.shared_users) && p.shared_users >= 1 && p.shared_users <= 20, 'shared-users invalide');
      const fields = { 'shared-users': String(p.shared_users) };
      if (p.rate_limit) fields['rate-limit'] = p.rate_limit;
      const found = existing.get(p.name);
      if (found) await ros.updateProfile(found['.id'], p.rate_limit ? fields : { ...fields, 'rate-limit': '' });
      else await ros.createProfile({ name: p.name, ...fields });
    }
    return {};
  },

  async CREATE_VOUCHER(ros, { vouchers }) {
    assert(Array.isArray(vouchers) && vouchers.length >= 1 && vouchers.length <= 50, 'vouchers invalide');
    const present = new Set((await ros.hotspotUsers()).map((u) => u.name));
    const created = [];
    const failed = [];
    for (const v of vouchers) {
      assert(CODE_RE.test(v.code), 'code invalide');
      assert(PROFILE_RE.test(v.profile), 'profil invalide');
      assert(UUID_RE.test(v.voucher_id), 'voucher_id invalide');
      if (present.has(v.code)) { created.push(v.code); continue; } // idempotent : rejeu après un résultat perdu
      const fields = { name: v.code, password: v.code, profile: v.profile, comment: `wz:${v.voucher_id}` };
      if (v.limit_uptime_minutes) fields['limit-uptime'] = formatMinutes(v.limit_uptime_minutes);
      if (v.limit_bytes_total_mb) fields['limit-bytes-total'] = String(v.limit_bytes_total_mb * MB);
      try {
        await ros.createUser(fields);
        created.push(v.code);
      } catch (e) {
        failed.push({ code: v.code, error: String(e.message).slice(0, 200) });
      }
    }
    return { created, failed };
  },

  async DISABLE_VOUCHER(ros, { code }) {
    assert(CODE_RE.test(code), 'code invalide');
    const user = (await ros.hotspotUsers()).find((u) => u.name === code);
    if (user) await ros.updateUser(user['.id'], { disabled: 'true' });
    await dropActive(ros, code);
    return {};
  },

  async REVOKE_VOUCHER(ros, { code }) {
    assert(CODE_RE.test(code), 'code invalide');
    const user = (await ros.hotspotUsers()).find((u) => u.name === code);
    if (user) await ros.removeUser(user['.id']);
    await dropActive(ros, code);
    return {};
  },

  async DISCONNECT_USER(ros, { session_id, username }) {
    assert(SESSION_ID_RE.test(session_id), 'session_id invalide');
    // On vérifie que l'identifiant désigne bien cet utilisateur (les identifiants RouterOS sont réutilisés).
    const active = (await ros.activeSessions()).find((s) => s['.id'] === session_id);
    if (active && (!username || active.user === username)) await ros.removeActive(session_id);
    return {};
  },

  async FETCH_HEALTH(ros) {
    return { health: health(await ros.resource()) };
  },

  async FETCH_SESSIONS(ros) {
    return collectSessions(ros);
  },
};

async function dropActive(ros, code) {
  for (const s of await ros.activeSessions()) if (s.user === code) await ros.removeActive(s['.id']);
}

export function health(r) {
  const out = {
    board_name: r['board-name'],
    version: r.version,
    cpu_load: r['cpu-load'] != null ? Math.min(100, Number(r['cpu-load'])) : undefined,
    free_memory: r['free-memory'] != null ? Number(r['free-memory']) : undefined,
    total_memory: r['total-memory'] != null ? Number(r['total-memory']) : undefined,
    uptime_seconds: r.uptime ? parseDuration(r.uptime) : undefined,
  };
  return Object.fromEntries(Object.entries(out).filter(([, v]) => v !== undefined && !Number.isNaN(v)));
}

/** Sessions actives + codes « épuisés » (temps ou volume atteint) parmi les utilisateurs créés par WIFIZONE. */
export async function collectSessions(ros) {
  const [active, users] = await Promise.all([ros.activeSessions(), ros.hotspotUsers()]);

  const sessions = active.map((s) => ({
    id: s['.id'],
    username: s.user,
    ...(s.address ? { address: s.address } : {}),
    ...(s['mac-address'] ? { mac: s['mac-address'] } : {}),
    uptime_seconds: parseDuration(s.uptime),
    bytes_in: Number(s['bytes-in'] ?? 0),
    bytes_out: Number(s['bytes-out'] ?? 0),
  }));

  const finished = [];
  for (const u of users) {
    if (!String(u.comment ?? '').startsWith('wz:') || !CODE_RE.test(u.name)) continue;
    const timeLimit = parseDuration(u['limit-uptime']);
    const byteLimit = Number(u['limit-bytes-total'] ?? 0);
    const usedBytes = Number(u['bytes-in'] ?? 0) + Number(u['bytes-out'] ?? 0);
    if ((timeLimit > 0 && parseDuration(u.uptime) >= timeLimit) || (byteLimit > 0 && usedBytes >= byteLimit)) finished.push(u.name);
  }

  return { sessions, finished_codes: finished.slice(0, 500) };
}
