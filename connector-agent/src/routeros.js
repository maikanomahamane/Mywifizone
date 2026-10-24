// Client de l'API REST RouterOS 7 (https://help.mikrotik.com/docs/display/ROS/REST+API).
// NON VALIDÉ sur un routeur réel dans ce dépôt : tests uniquement contre un simulateur (test/). À essayer sur un routeur
// de test avant toute mise en production. Les chemins ci-dessous suivent la documentation MikroTik (PUT = créer,
// PATCH = modifier, DELETE = supprimer, GET = lire) ; les noms de champs sont ceux des menus /ip hotspot.
import http from 'node:http';
import https from 'node:https';

export class RouterOsError extends Error {
  constructor(message, status) {
    super(message);
    this.status = status;
  }
}

export class RouterOsClient {
  constructor({ baseUrl, user, password, insecure = false, timeoutMs = 10000 }) {
    this.url = new URL(baseUrl);
    this.auth = 'Basic ' + Buffer.from(`${user}:${password}`).toString('base64');
    this.timeoutMs = timeoutMs;
    // Routeur à certificat auto-signé : la vérification n'est désactivée QUE pour cette connexion (réseau local de confiance),
    // jamais globalement — la connexion vers le cloud reste vérifiée.
    this.agent = this.url.protocol === 'https:' ? new https.Agent({ rejectUnauthorized: !insecure }) : undefined;
  }

  #call(method, path, body) {
    const payload = body === undefined ? null : JSON.stringify(body);
    const lib = this.url.protocol === 'https:' ? https : http;

    return new Promise((resolve, reject) => {
      const req = lib.request({
        protocol: this.url.protocol, hostname: this.url.hostname, port: this.url.port || undefined,
        path: `${this.url.pathname.replace(/\/+$/, '')}/rest${path}`, method, agent: this.agent, timeout: this.timeoutMs,
        headers: { Authorization: this.auth, 'Content-Type': 'application/json', ...(payload ? { 'Content-Length': Buffer.byteLength(payload) } : {}) },
      }, (res) => {
        const chunks = [];
        res.on('data', (c) => chunks.push(c));
        res.on('end', () => {
          const text = Buffer.concat(chunks).toString('utf8');
          if (res.statusCode < 200 || res.statusCode >= 300) return reject(new RouterOsError(`RouterOS ${method} ${path} -> ${res.statusCode} ${text.slice(0, 200)}`, res.statusCode));
          try { resolve(text ? JSON.parse(text) : null); } catch (e) { reject(new RouterOsError(`Réponse RouterOS illisible : ${e.message}`)); }
        });
      });
      req.on('timeout', () => req.destroy(new RouterOsError(`RouterOS ${method} ${path} : délai dépassé`)));
      req.on('error', reject);
      if (payload) req.write(payload);
      req.end();
    });
  }

  resource() { return this.#call('GET', '/system/resource'); }
  activeSessions() { return this.#call('GET', '/ip/hotspot/active'); }
  hotspotUsers() { return this.#call('GET', '/ip/hotspot/user'); }
  profiles() { return this.#call('GET', '/ip/hotspot/user/profile'); }

  createProfile(fields) { return this.#call('PUT', '/ip/hotspot/user/profile', fields); }
  updateProfile(id, fields) { return this.#call('PATCH', `/ip/hotspot/user/profile/${encodeURIComponent(id)}`, fields); }
  createUser(fields) { return this.#call('PUT', '/ip/hotspot/user', fields); }
  updateUser(id, fields) { return this.#call('PATCH', `/ip/hotspot/user/${encodeURIComponent(id)}`, fields); }
  removeUser(id) { return this.#call('DELETE', `/ip/hotspot/user/${encodeURIComponent(id)}`); }
  removeActive(id) { return this.#call('DELETE', `/ip/hotspot/active/${encodeURIComponent(id)}`); }
}
