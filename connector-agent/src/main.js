#!/usr/bin/env node
import { readFile, writeFile, chmod } from 'node:fs/promises';
import { CloudClient } from './cloud.js';
import { RouterOsClient } from './routeros.js';
import { Agent } from './agent.js';

const env = (name, fallback) => {
  const v = process.env[name] ?? fallback;
  if (v === undefined) throw new Error(`Variable d'environnement manquante : ${name}`);
  return v;
};

const apiUrl = env('WZ_API_URL');                              // ex. https://api.exemple.com/api/v1
const stateFile = env('WZ_STATE_FILE', './agent-state.json');  // contient le jeton : permissions 0600
const ros = new RouterOsClient({
  baseUrl: env('ROUTEROS_URL'),                                // ex. https://192.168.88.1
  user: env('ROUTEROS_USER'),
  password: env('ROUTEROS_PASSWORD'),
  insecure: env('ROUTEROS_INSECURE', '0') === '1',
});

async function loadToken() {
  try { return JSON.parse(await readFile(stateFile, 'utf8')).token; } catch { return null; }
}

let token = await loadToken();
if (!token) {
  const code = env('WZ_ENROLL_CODE');
  const info = await new CloudClient({ baseUrl: apiUrl }).enroll({ enrollment_code: code, agent_version: '0.1.0' });
  token = info.agent_token;
  await writeFile(stateFile, JSON.stringify({ token, router_id: info.router_id }), { mode: 0o600 });
  await chmod(stateFile, 0o600);
  console.log(`Enrôlé (routeur ${info.router_id}). Le code d'enrôlement n'est plus nécessaire : retirez WZ_ENROLL_CODE.`);
}

const agent = new Agent({ cloud: new CloudClient({ baseUrl: apiUrl, token }), ros });
const pollMs = Number(env('WZ_POLL_INTERVAL_MS', '10000'));
let backoff = pollMs;

for (;;) {
  try {
    await agent.tick();
    backoff = pollMs;
  } catch (e) {
    console.warn(`Cycle en échec : ${e.message}`);
    if (e.status === 401 || e.status === 403) { console.error('Jeton refusé : ré-enrôlez cet agent depuis l\'application.'); process.exit(2); }
    backoff = Math.min(backoff * 2, 300000);
  }
  await new Promise((r) => setTimeout(r, backoff));
}
