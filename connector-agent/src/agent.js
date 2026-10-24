import { execute, health, collectSessions } from './handlers.js';
import { PermanentError } from './validate.js';

/** Un cycle de l'agent : exécute les commandes reçues puis, si l'échéance est passée, envoie le heartbeat. */
export class Agent {
  constructor({ cloud, ros, version = '0.1.0', heartbeatIntervalMs = 30000, log = console }) {
    this.cloud = cloud;
    this.ros = ros;
    this.version = version;
    this.heartbeatIntervalMs = heartbeatIntervalMs;
    this.log = log;
    this.lastHeartbeat = 0;
  }

  async tick(now = Date.now()) {
    const commands = await this.cloud.poll();
    for (const command of commands) await this.#run(command);

    if (now - this.lastHeartbeat >= this.heartbeatIntervalMs) {
      await this.heartbeat();
      this.lastHeartbeat = now;
    }
    return commands.length;
  }

  async heartbeat() {
    const body = {};
    try {
      body.health = { ...health(await this.ros.resource()), agent_version: this.version };
      Object.assign(body, await collectSessions(this.ros));
    } catch (e) {
      // Routeur injoignable : on signale seulement que l'agent vit (le cloud verra l'absence de santé/sessions).
      this.log.warn?.(`RouterOS injoignable : ${e.message}`);
      delete body.health;
    }
    await this.cloud.heartbeat(body);
  }

  async #run(command) {
    try {
      const result = await execute(this.ros, command);
      await this.cloud.result(command.id, { success: true, result });
    } catch (e) {
      const retryable = !(e instanceof PermanentError);
      this.log.warn?.(`Commande ${command.type} ${command.id} en échec (${retryable ? 'réessayable' : 'définitif'}) : ${e.message}`);
      await this.cloud.result(command.id, { success: false, error: String(e.message).slice(0, 500), retryable }).catch(() => {});
    }
  }
}
