/*! utm-supermini — persistência de atribuição por ABA (sessionStorage)
 * Isolamento: cada aba = 1 sessão; nunca mistura leads.
 * Escalável: 100% client-side, zero estado no servidor.
 * Compat: window.mergeFunnelParams / window.getFunnelParams
 */
(function () {
  "use strict";
  if (window.__UTM_SUPERMINI__) return;
  window.__UTM_SUPERMINI__ = true;

  var PARAMS_KEY = "funnel_url_params";
  var META_KEY = "funnel_session_meta";
  var LEAD_KEYS = { email: 1, mail: 1, "e-mail": 1, name: 1, nome: 1, telephone: 1, telefone: 1, document: 1, cpf: 1 };
  var ATTR_KEYS = {
    utm_source: 1, utm_medium: 1, utm_campaign: 1, utm_content: 1, utm_term: 1,
    gclid: 1, gbraid: 1, wbraid: 1, fbclid: 1, ttclid: 1, msclkid: 1,
    keyword: 1, device: 1, network: 1, placement: 1, creative: 1, adgroupid: 1, campaignid: 1
  };

  function uuid() {
    try {
      if (crypto && crypto.randomUUID) return crypto.randomUUID();
    } catch (e) {}
    return "fs_" + Date.now().toString(36) + "_" + Math.random().toString(36).slice(2, 10);
  }

  function safeParse(raw, fallback) {
    try { return raw ? JSON.parse(raw) : fallback; } catch (e) { return fallback; }
  }

  function loadParams() {
    var obj = safeParse(sessionStorage.getItem(PARAMS_KEY), {});
    if (!obj || typeof obj !== "object") obj = {};
    Object.keys(LEAD_KEYS).forEach(function (k) { if (k in obj) delete obj[k]; });
    return obj;
  }

  function saveParams(obj) {
    Object.keys(LEAD_KEYS).forEach(function (k) { if (k in obj) delete obj[k]; });
    try { sessionStorage.setItem(PARAMS_KEY, JSON.stringify(obj)); } catch (e) {}
  }

  function loadMeta() {
    var m = safeParse(sessionStorage.getItem(META_KEY), null);
    if (!m || !m.session_id) {
      m = { session_id: uuid(), created_at: Date.now(), landing_url: "" };
      try { sessionStorage.setItem(META_KEY, JSON.stringify(m)); } catch (e) {}
    }
    return m;
  }

  function saveMeta(m) {
    try { sessionStorage.setItem(META_KEY, JSON.stringify(m)); } catch (e) {}
  }

  function isSandboxEmail(v) {
    v = String(v || "").toLowerCase();
    return /@teste\.local$|@cliente\.temp$|^simulador@/.test(v);
  }

  function paramsFromLocation() {
    var out = {};
    try {
      new URLSearchParams(window.location.search).forEach(function (value, key) {
        if (LEAD_KEYS[key] || isSandboxEmail(value)) return;
        if (value == null || value === "") return;
        out[key] = String(value);
      });
    } catch (e) {}
    return out;
  }

  // First-touch para chaves de atribuição; demais keys: preenche se vazio
  function mergeIncoming(stored, incoming) {
    Object.keys(incoming).forEach(function (k) {
      var v = incoming[k];
      if (v == null || v === "") return;
      if (ATTR_KEYS[k]) {
        if (!stored[k]) stored[k] = v;
      } else if (!stored[k]) {
        stored[k] = v;
      }
    });
    return stored;
  }

  function currentMergedParams() {
    var stored = loadParams();
    var now = paramsFromLocation();
    mergeIncoming(stored, now);
    saveParams(stored);
    var sp = new URLSearchParams(window.location.search);
    Object.keys(stored).forEach(function (k) {
      if (!sp.has(k) && stored[k]) sp.set(k, stored[k]);
    });
    return sp;
  }

  function ensureLanding(meta, stored) {
    if (meta.landing_url) return meta;
    var sp = new URLSearchParams();
    Object.keys(stored).forEach(function (k) {
      if (stored[k]) sp.set(k, stored[k]);
    });
    var qs = sp.toString();
    meta.landing_url = window.location.origin + window.location.pathname + (qs ? "?" + qs : "");
    saveMeta(meta);
    return meta;
  }

  function restoreUrl() {
    var params = currentMergedParams();
    var before = window.location.search.replace(/^\?/, "");
    var after = params.toString();
    if (after !== before) {
      try {
        history.replaceState(null, "", window.location.pathname + (after ? "?" + after : "") + window.location.hash);
      } catch (e) {}
    }
  }

  function mergeIntoHref(href) {
    if (!href || href.indexOf("javascript:") === 0 || href.indexOf("mailto:") === 0 || href.indexOf("tel:") === 0) return href;
    if (href.charAt(0) === "#") return href;
    try {
      var url = new URL(href, window.location.href);
      if (url.origin !== window.location.origin) return href; // não vaza UTMs p/ terceiros
      var stored = loadParams();
      Object.keys(stored).forEach(function (k) {
        if (stored[k] && !url.searchParams.has(k)) url.searchParams.set(k, stored[k]);
      });
      // nunca propagar offer herdado em path de upsell/checkout — callers já tratam; aqui só UTMs
      return url.pathname + (url.search || "") + (url.hash || "");
    } catch (e) {
      return href;
    }
  }

  // Patch navigations same-origin
  function patchNavigation() {
    document.addEventListener("click", function (ev) {
      var a = ev.target && ev.target.closest ? ev.target.closest("a[href]") : null;
      if (!a || ev.defaultPrevented || ev.button !== 0) return;
      if (ev.metaKey || ev.ctrlKey || ev.shiftKey || ev.altKey) return;
      if (a.target && a.target !== "" && a.target !== "_self") return;
      var href = a.getAttribute("href");
      if (!href) return;
      var next = mergeIntoHref(href);
      if (next !== href) a.setAttribute("href", next);
    }, true);

    var _assign = window.location.assign.bind(window.location);
    var _replace = window.location.replace.bind(window.location);
    try {
      window.location.assign = function (url) { return _assign(mergeIntoHref(String(url))); };
      window.location.replace = function (url) { return _replace(mergeIntoHref(String(url))); };
    } catch (e) {}

    // intercept location.href = 'x.html' via prototype when possible is unreliable; patch common pattern:
    try {
      var desc = Object.getOwnPropertyDescriptor(Location.prototype, "href");
      if (desc && desc.set && desc.get) {
        Object.defineProperty(Location.prototype, "href", {
          configurable: true,
          enumerable: true,
          get: function () { return desc.get.call(this); },
          set: function (v) { desc.set.call(this, mergeIntoHref(String(v))); }
        });
      }
    } catch (e) {}
  }

  // boot
  var meta = loadMeta();
  var stored = loadParams();
  mergeIncoming(stored, paramsFromLocation());
  // import UTMify leftovers into THIS session only (fill gaps)
  try {
    ["utm_data", "utmParams"].forEach(function (key) {
      var raw = localStorage.getItem(key);
      if (!raw) return;
      var data = JSON.parse(raw);
      if (!data || typeof data !== "object") return;
      var gap = {};
      Object.keys(data).forEach(function (k) {
        if (LEAD_KEYS[k]) return;
        if (data[k] != null && data[k] !== "" && !stored[k]) gap[k] = String(data[k]);
      });
      mergeIncoming(stored, gap);
    });
  } catch (e) {}
  saveParams(stored);
  ensureLanding(meta, stored);
  restoreUrl();
  patchNavigation();

  window.mergeFunnelParams = mergeIntoHref;
  window.getFunnelParams = function () { return currentMergedParams(); };
  window.getFunnelSession = function () {
    var m = loadMeta();
    var p = loadParams();
    return {
      session_id: m.session_id,
      landing_url: m.landing_url || "",
      params: p
    };
  };
})();
