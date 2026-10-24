/* Portail captif MY WIFIZONE : aucun secret, aucune bibliothèque. Les textes viennent du serveur (CONFIG.labels) et sont
 * insérés avec textContent (jamais comme HTML) : un nom de profil ou de boutique ne peut pas injecter de HTML. */

/* Le formulaire de connexion envoie le code à `link-login-only` (fourni par le HotSpot MikroTik). Un lien forgé ne doit pas
 * pouvoir envoyer le code ailleurs : seules les adresses de réseau local sont acceptées. */
function isAllowedLoginUrl(raw) {
  var m = /^https?:\/\/([^\/:?#@]+)(?::(\d{1,5}))?(?:[\/?#]|$)/i.exec(String(raw || ''));
  if (!m) return false;
  var host = m[1].toLowerCase();
  var ip = /^(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.(\d{1,3})$/.exec(host);
  if (ip) {
    var a = +ip[1], b = +ip[2], c = +ip[3], d = +ip[4];
    if (a > 255 || b > 255 || c > 255 || d > 255) return false;
    return a === 10 || (a === 172 && b >= 16 && b <= 31) || (a === 192 && b === 168) || (a === 100 && b >= 64 && b <= 127) || (a === 169 && b === 254);
  }
  // Nom du HotSpot (sans point) ou suffixes réservés au réseau local.
  return host.indexOf('.') === -1 || /\.(lan|local|home|internal)$/.test(host);
}

function formatMoney(amount, currency, decimals) {
  var s = String(Math.abs(amount)), out = '', i;
  if (decimals > 0) {
    while (s.length <= decimals) s = '0' + s;
    var frac = s.slice(-decimals); s = s.slice(0, -decimals);
    out = '.' + frac;
  }
  var g = '';
  for (i = 0; i < s.length; i++) { if (i > 0 && (s.length - i) % 3 === 0) g += ' '; g += s[i]; }
  return (amount < 0 ? '-' : '') + g + out + ' ' + currency;
}

function formatDuration(min, L) {
  if (!min) return L.unlimited;
  if (min % 1440 === 0) return (min / 1440) + ' ' + L.day;
  if (min % 60 === 0) return (min / 60) + ' ' + L.hour;
  if (min > 60) return Math.floor(min / 60) + ' ' + L.hour + ' ' + (min % 60) + ' ' + L.min;
  return min + ' ' + L.min;
}

if (typeof module !== 'undefined') { module.exports = { isAllowedLoginUrl: isAllowedLoginUrl, formatMoney: formatMoney, formatDuration: formatDuration }; }

(function () {
  if (typeof document === 'undefined' || typeof CONFIG === 'undefined') return;
  var qs = new URLSearchParams(location.search);
  var state = { locale: CONFIG.locale, offers: [], poll: null };
  var $ = function (id) { return document.getElementById(id); };
  var L = function () { return CONFIG.labels[state.locale] || CONFIG.labels.fr; };
  var API = CONFIG.api;
  var router = qs.get('r') || '';
  var loginUrl = qs.get('link-login-only') || '';
  var dst = qs.get('link-orig') || '';

  function text(el, t) { el.textContent = t; return el; }
  function el(tag, cls, t) { var e = document.createElement(tag); if (cls) e.className = cls; if (t != null) e.textContent = t; return e; }
  function show(id) { ['view-login', 'view-offers', 'view-status'].forEach(function (v) { $(v).hidden = v !== id; }); }

  function applyLocale() {
    var l = L();
    document.documentElement.lang = state.locale;
    document.documentElement.dir = state.locale === 'ar' ? 'rtl' : 'ltr';
    text($('t-enter'), l.enter_code); text($('btn-connect'), l.connect); text($('t-noticket'), l.no_ticket);
    text($('btn-buy'), l.buy); text($('t-offers'), l.offers); text($('btn-back'), l.back); text($('lbl-phone'), l.phone);
    renderOffers();
  }

  function connect(code) {
    var l = L();
    if (!/^[A-Za-z0-9]{4,32}$/.test(code)) { text($('login-msg'), l.error); return; }
    if (!isAllowedLoginUrl(loginUrl)) { text($('login-msg'), l.bad_link); return; }
    var f = document.createElement('form');
    f.method = 'post'; f.action = loginUrl;
    [['username', code], ['password', code], ['dst', dst]].forEach(function (p) {
      var i = document.createElement('input'); i.type = 'hidden'; i.name = p[0]; i.value = p[1]; f.appendChild(i);
    });
    document.body.appendChild(f); f.submit();
  }

  function renderOffers() {
    var box = $('offers'); box.textContent = '';
    var l = L();
    state.offers.forEach(function (o) {
      var card = el('button', 'offer'); card.type = 'button'; card.disabled = !o.available;
      card.appendChild(el('strong', null, o.name));
      card.appendChild(el('span', 'price', formatMoney(o.price, o.currency, o.minor_unit)));
      var meta = l.duration + ': ' + formatDuration(o.duration_minutes, l) + ' · ' + o.device_limit + ' ' + l.devices + (o.rate_limit ? ' · ' + o.rate_limit : '');
      card.appendChild(el('small', null, o.available ? meta : l.sold_out));
      card.onclick = function () { order(o); };
      box.appendChild(card);
    });
  }

  function request(method, path, body) {
    return fetch(API + path, { method: method, headers: { 'Accept': 'application/json', 'Content-Type': 'application/json' }, body: body ? JSON.stringify(body) : undefined })
      .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, json: j }; }); });
  }

  function err(res) { var e = res.json && res.json.errors && res.json.errors[0]; return e && e.code === 'sold_out' ? L().sold_out : L().error; }

  function loadOffers() {
    request('GET', '/portal/' + CONFIG.slug + (router ? '?router=' + encodeURIComponent(router) : '')).then(function (res) {
      if (!res.ok) { text($('login-msg'), L().unavailable); return; }
      state.offers = res.json.data.offers; renderOffers();
    }).catch(function () { text($('login-msg'), L().error); });
  }

  function order(o) {
    var l = L();
    if (!router) { text($('offers-msg'), l.error); return; }
    var body = { profile_id: o.id, router_id: router, locale: state.locale, client_ref: 'web-' + Date.now() + '-' + Math.floor(Math.random() * 1e9) };
    var phone = $('phone').value.replace(/[^0-9+]/g, '');
    if (phone) body.customer_phone = phone;
    text($('offers-msg'), l.loading);
    request('POST', '/portal/' + CONFIG.slug + '/orders', body).then(function (res) {
      if (!res.ok) { text($('offers-msg'), err(res)); return; }
      text($('offers-msg'), '');
      show('view-status'); text($('status-msg'), l.pending); $('code-box').hidden = true;
      pollOrder(res.json.data.token);
    }).catch(function () { text($('offers-msg'), l.error); });
  }

  function pollOrder(token) {
    var tries = 0;
    clearInterval(state.poll);
    state.poll = setInterval(function () {
      tries++;
      request('GET', '/portal/orders/' + encodeURIComponent(token)).then(function (res) {
        if (!res.ok) return;
        var d = res.json.data, l = L();
        if (d.status === 'FULFILLED' && d.code) {
          clearInterval(state.poll); text($('status-msg'), l.success + ' ' + l.use_code);
          text($('code'), d.code); $('code-box').hidden = false;
          $('btn-use').onclick = function () { connect(d.code); };
        } else if (d.status === 'NEEDS_ATTENTION') {
          clearInterval(state.poll); text($('status-msg'), l.attention + ' ' + d.reference);
        } else if (d.status === 'FAILED' || d.status === 'EXPIRED') {
          clearInterval(state.poll); text($('status-msg'), l.failed);
        }
      }).catch(function () {});
      if (tries > 200) clearInterval(state.poll);
    }, 3000);
  }

  var sel = $('lang');
  ['fr', 'en', 'ar', 'ha'].forEach(function (c) { var o = document.createElement('option'); o.value = c; o.textContent = { fr: 'Français', en: 'English', ar: 'العربية', ha: 'Hausa' }[c]; if (c === state.locale) o.selected = true; sel.appendChild(o); });
  sel.onchange = function () { state.locale = sel.value; applyLocale(); };
  $('form-login').onsubmit = function (e) { e.preventDefault(); connect($('code-input').value.trim()); };
  $('btn-buy').onclick = function () { show('view-offers'); };
  $('btn-back').onclick = function () { clearInterval(state.poll); show('view-login'); };
  show('view-login'); applyLocale(); loadOffers();
})();
