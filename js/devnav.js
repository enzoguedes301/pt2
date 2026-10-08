/**
 * Navegação DEV do funil inteiro
 * Uma barra fixa para andar por TODO o funil, na ordem, SEM pagar PIX:
 *   inicio → home → passos 2..10 → pagamento(main) → upsell 1 → pagamento(up1)
 *   → upsell 2 → ... → upsell 11 → pagamento(up11) → (site externo)
 *
 * A página pagamento/ é um checkout COMPARTILHADO (?offer=main|up1..up11), então
 * o "próximo" dela depende do offer: offer=main → upsell/1; offer=upN → upsell/(N+1);
 * offer=up11 → site externo. É por isso que "Pular pagamento" leva ao upsell certo.
 *
 * Ativa só com localStorage.modoDesenv==='true' (ou ?dev=1) e só em ambiente
 * LOCAL. NUNCA aparece para o lead em produção. Incluído como ../js/devnav.js
 * (páginas de 1 nível) ou ../../js/devnav.js (upsells) — mesma convenção do utm.js.
 */
(function () {
  'use strict';

  var h = location.hostname;
  var IS_LOCAL = h === 'localhost' || h === '127.0.0.1' || h === '::1' || h === '' ||
                 h.endsWith('.local') || location.protocol === 'file:';
  if (!IS_LOCAL) return;

  try {
    var pd = new URLSearchParams(location.search).get('dev');
    if (pd === '1') localStorage.setItem('modoDesenv', 'true');
    else if (pd === '0') localStorage.removeItem('modoDesenv');
  } catch (e) {}

  var DEV = false;
  try { DEV = localStorage.getItem('modoDesenv') === 'true'; } catch (e) {}
  if (!DEV) return;

  var FINAL_EXTERNO = 'https://www.google.com/';
  // Nome do arquivo de cada passo do funil
  var STEPFILE = { 2:'indexcd8c.html', 3:'indexcd8c.html', 4:'indexe217.html',
                   5:'indexe217.html', 6:'indexe217.html', 7:'index3118.html',
                   8:'index8f6f.html', 9:'indexf5a9.html', 10:'indexae25.html' };

  var path = location.pathname;

  // Descobre a etapa atual e devolve { label, marker, prev, next }.
  // prev/next: caminho relativo À RAIZ do site, ou null, ou '__final__'.
  function pos() {
    if (/\/inicio\//.test(path)) return { label: 'Início', marker: '/inicio/', prev: null, next: 'home/' };
    if (/\/home\//.test(path))   return { label: 'Home', marker: '/home/', prev: 'inicio/', next: '2/' + STEPFILE[2] };

    if (/\/pagamento\//.test(path)) {
      var offer = 'main';
      try { offer = (new URLSearchParams(location.search).get('offer') || 'main').toLowerCase(); } catch (e) {}
      if (offer === 'main') {
        return { label: 'Pagamento · principal', marker: '/pagamento/', prev: '10/' + STEPFILE[10], next: 'upsell/1/' };
      }
      var mo = offer.match(/^up(\d+)$/);
      if (mo) {
        var k = parseInt(mo[1], 10);
        if (k >= 1 && k <= 11) {
          return {
            label: 'Pagamento · upsell ' + k,
            marker: '/pagamento/',
            prev: 'upsell/' + k + '/',
            next: (k < 11) ? ('upsell/' + (k + 1) + '/') : '__final__'
          };
        }
      }
      return { label: 'Pagamento', marker: '/pagamento/', prev: '10/' + STEPFILE[10], next: 'upsell/1/' };
    }

    var mu = path.match(/\/upsell\/(\d+)\//);
    if (mu) {
      var u = parseInt(mu[1], 10);
      if (u >= 1 && u <= 11) {
        return {
          label: 'Upsell ' + u,
          marker: '/upsell/',
          prev: (u === 1) ? 'pagamento/indexe698.html?offer=main' : ('upsell/' + (u - 1) + '/'),
          next: (u < 11) ? ('upsell/' + (u + 1) + '/') : '__final__'
        };
      }
      return null; // ex.: upsell/final (fora da cadeia)
    }

    var ms = path.match(/\/(\d+)\//);
    if (ms) {
      var s = parseInt(ms[1], 10);
      if (s >= 2 && s <= 10) {
        return {
          label: 'Passo ' + s,
          marker: '/' + s + '/',
          prev: (s === 2) ? 'home/' : ((s - 1) + '/' + STEPFILE[s - 1]),
          next: (s < 10) ? ((s + 1) + '/' + STEPFILE[s + 1]) : 'pagamento/indexe698.html?offer=main'
        };
      }
    }
    return null;
  }

  var P = pos();
  if (!P) return; // página fora do funil

  // Raiz do site a partir do marcador desta etapa (vale local e hospedado)
  var root = path.substring(0, path.indexOf(P.marker) + 1);

  function queryExtra() {
    try {
      var p = new URLSearchParams(location.search);
      p.delete('dev');
      var q = p.toString();
      return q ? '&' + q : '';
    } catch (e) { return ''; }
  }

  function irPara(destino) {
    if (!destino) return;
    if (destino === '__final__') { location.href = FINAL_EXTERNO; return; }
    var url = root + destino;
    location.href = url + (url.indexOf('?') < 0 ? '?' : '&') + 'dev=1' + queryExtra();
  }

  function render() {
    if (document.getElementById('funnelDevNav')) return;

    var temVoltar = !!P.prev;
    var naPagamento = P.marker === '/pagamento/';
    var ultimo = P.next === '__final__';
    var proxLabel = ultimo ? 'Finalizar ▶' : (naPagamento ? 'Pular pagamento ▶' : 'Avançar ▶');

    var c = document.createElement('div');
    c.id = 'funnelDevNav';
    c.style.cssText = 'position:fixed;bottom:0;left:0;right:0;z-index:999999;background:#fff;border-top:1px solid #E5E7EB;box-shadow:0 -2px 14px rgba(0,0,0,.10);';

    c.innerHTML =
      '<div style="display:flex;align-items:center;gap:8px;max-width:460px;margin:0 auto;padding:9px 14px;font-family:\'DM Sans\',Arial,sans-serif;">' +
        '<button id="fdPrev" ' + (temVoltar ? '' : 'disabled ') + 'style="padding:9px 12px;background:#F3F4F6;border:1px solid #E5E7EB;border-radius:8px;font-weight:600;font-size:13px;color:#374151;cursor:pointer;white-space:nowrap;' + (temVoltar ? '' : 'opacity:.4;cursor:not-allowed;') + '">◀ Voltar</button>' +
        '<div style="flex:1;text-align:center;font-size:11px;font-weight:700;letter-spacing:.4px;color:#9CA3AF;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">🛠️ DEV · ' + P.label + '</div>' +
        '<button id="fdNext" style="padding:9px 12px;background:#fa6300;border:none;border-radius:8px;font-weight:700;font-size:13px;color:#fff;cursor:pointer;white-space:nowrap;">' + proxLabel + '</button>' +
      '</div>';

    document.body.appendChild(c);
    try { document.body.style.paddingBottom = '72px'; } catch (e) {}

    var pv = document.getElementById('fdPrev'); if (pv && temVoltar) pv.onclick = function () { irPara(P.prev); };
    var nx = document.getElementById('fdNext'); if (nx) nx.onclick = function () { irPara(P.next); };
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', render);
  else render();
})();
