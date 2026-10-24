// Test de charge k6 (point de départ, NON exécuté ici) : routes publiques les plus exposées.
//   k6 run -e BASE=https://recette.example.com -e SLUG=<slug du portail> scripts/load-test.js
// À lancer sur une RECETTE, jamais sur la production. Seuils : p95 < 500 ms, moins de 1 % d'erreurs.
import http from 'k6/http';
import { check, sleep } from 'k6';

export const options = {
  stages: [{ duration: '1m', target: 50 }, { duration: '3m', target: 50 }, { duration: '30s', target: 0 }],
  thresholds: { http_req_duration: ['p(95)<500'], http_req_failed: ['rate<0.01'] },
};

const BASE = __ENV.BASE;
const SLUG = __ENV.SLUG;

export default function () {
  check(http.get(`${BASE}/api/v1/health`), { 'health 200': (r) => r.status === 200 });
  if (SLUG) {
    check(http.get(`${BASE}/api/v1/portal/${SLUG}`), { 'portail 200': (r) => r.status === 200 });
    check(http.get(`${BASE}/api/v1/portal/${SLUG}/page`), { 'page 200': (r) => r.status === 200 });
  }
  sleep(1);
}
