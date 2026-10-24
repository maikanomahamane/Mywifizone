// Client de l'API agent du cloud (voir backend-overlay/docs/openapi.yaml, tag « Agent »).
export class CloudError extends Error {
  constructor(message, status, code) {
    super(message);
    this.status = status;
    this.code = code;
  }
}

export class CloudClient {
  constructor({ baseUrl, token = null, timeoutMs = 15000 }) {
    this.baseUrl = baseUrl.replace(/\/+$/, '');
    this.token = token;
    this.timeoutMs = timeoutMs;
  }

  async #post(path, body) {
    const res = await fetch(`${this.baseUrl}${path}`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', ...(this.token ? { Authorization: `Bearer ${this.token}` } : {}) },
      body: JSON.stringify(body ?? {}),
      signal: AbortSignal.timeout(this.timeoutMs),
    });
    const json = await res.json().catch(() => ({}));
    if (!res.ok || json.success === false) {
      throw new CloudError(json.errors?.[0]?.message ?? `HTTP ${res.status}`, res.status, json.errors?.[0]?.code);
    }
    return json.data;
  }

  enroll(info) { return this.#post('/agent/enroll', info); }
  heartbeat(body) { return this.#post('/agent/heartbeat', body); }
  poll() { return this.#post('/agent/poll'); }
  result(id, body) { return this.#post(`/agent/commands/${encodeURIComponent(id)}/result`, body); }
}
