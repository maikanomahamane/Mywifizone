// Durées RouterOS : "1w2d3h4m5s" (chaque unité optionnelle) ou "00:30:00".
const UNITS = { w: 604800, d: 86400, h: 3600, m: 60, s: 1 };

export function parseDuration(text) {
  if (typeof text !== 'string' || text === '') return 0;
  if (/^\d+:\d{2}:\d{2}$/.test(text)) {
    const [h, m, s] = text.split(':').map(Number);
    return h * 3600 + m * 60 + s;
  }
  let total = 0;
  for (const [, n, unit] of text.matchAll(/(\d+)([wdhms])/g)) total += Number(n) * UNITS[unit];
  return total;
}

/** Minutes -> "1h30m" (format accepté par limit-uptime). */
export function formatMinutes(minutes) {
  const h = Math.floor(minutes / 60);
  const m = minutes % 60;
  return `${h > 0 ? `${h}h` : ''}${m > 0 || h === 0 ? `${m}m` : ''}`;
}
