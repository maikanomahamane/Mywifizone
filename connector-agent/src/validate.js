// Seconde barrière contre l'injection : l'agent revalide TOUT ce que le cloud lui envoie (le cloud peut être compromis,
// ou la file corrompue). Les valeurs ne sont jamais interpolées dans une commande : elles partent en corps JSON.
export const CODE_RE = /^[ABCDEFGHJKMNPQRSTUVWXYZ23456789]{6,12}$/;
export const PROFILE_RE = /^wz-[0-9a-f]{8}$/;
export const RATE_RE = /^\d{1,6}[kKmMgG]?(\/\d{1,6}[kKmMgG]?)?$/;
export const UUID_RE = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
export const SESSION_ID_RE = /^\*[0-9A-F]{1,16}$/;

export function assert(cond, message) {
  if (!cond) throw new PermanentError(message);
}

/** Erreur non réessayable : la commande est invalide, la rejouer ne changera rien. */
export class PermanentError extends Error {}
