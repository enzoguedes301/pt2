/*! tracking.js — Pixel UTMify + Google tag (GA4 / Google Ads) para o funil.
 *
 * Este arquivo e carregado por TODAS as paginas do fluxo (inicio, home, passos
 * 2..10, pagamento, upsell 1..6 e final). Configurar aqui = ligar em todo lugar.
 *
 * ATENCAO AO DESENHO DA CONTA — por que ADS_ID esta vazio de proposito:
 *
 *   O pixel do Google aqui e o da UTMify. Quem dispara a conversao para o
 *   Google Ads e ela, SERVER-SIDE (Enhanced Conversions), a partir do pedido
 *   que o backend envia para a API de pedidos dela. Se preenchermos ADS_ID +
 *   CONVERSION_LABEL abaixo, o gtag dispara a MESMA venda pelo navegador e o
 *   Google conta duas vezes — ROAS inflado e campanha aprendendo errado.
 *
 *   Portanto: usando o pixel da UTMify, ADS_ID e CONVERSION_LABEL ficam VAZIOS.
 *   So preencha se um dia desligar o pixel dela e voltar a marcar direto.
 *
 * ┌──────────────────────────────────────────────────────────────────────────┐
 * │ 1) UTMIFY_GOOGLE_PIXEL_ID   ← o que esta em uso                          │
 * │    Painel UTMify > Pixel > (pixel do Google) > "Configurar"              │
 * │    O snippet vem ofuscado; dentro dele o valor e:                        │
 * │      window.googlePixelId = "6ac1012f1d066a8530474cd4"                   │
 * │                                                                          │
 * │ 2) UTMIFY_META_PIXEL_ID  (so se um dia rodar Meta Ads)                   │
 * │    Mesmo caminho, escolhendo o pixel do Meta. La o global e outro:       │
 * │      window.pixelId = "..."      e o script e o pixel.js                 │
 * │                                                                          │
 * │ 3) GA4_ID  (opcional — Analytics, nao concorre com a UTMify)             │
 * │    Admin > Fluxos de dados > (seu fluxo) > "ID da metrica" → G-XXXXXXX   │
 * │                                                                          │
 * │ 4) ADS_ID + CONVERSION_LABEL  (NAO usar junto com o pixel da UTMify)     │
 * │    Objetivos > Conversoes > (sua acao) > "Instalar a tag manualmente":   │
 * │      'send_to': 'AW-123456789/AbC-D_efG-h12_34-567'                      │
 * │                  ^^^^^^^^^^^^  ^^^^^^^^^^^^^^^^^^^^                      │
 * │                  ADS_ID        CONVERSION_LABEL                          │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * Cada bloco e independente: o que estiver vazio simplesmente nao carrega e nao
 * dispara. O funil continua funcionando normalmente — nada aqui quebra a pagina.
 *
 * A captura de utm_source, gclid, gbraid, wbraid e fbclid e feita pelo utm.js, que roda
 * ANTES deste arquivo e persiste os parametros por aba durante todo o funil.
 */
(function () {
  "use strict";

  // ===================== PREENCHA AQUI =====================
  var CONFIG = {
    UTMIFY_GOOGLE_PIXEL_ID: "6ac1012f1d066a8530474cd4",
    UTMIFY_META_PIXEL_ID:   "",   // ex.: "67f0a1b2c3d4e5f6a7b8c9d0"
    GA4_ID:                 "",   // ex.: "G-XXXXXXXXXX"
    ADS_ID:                 "",   // vazio de proposito — ver nota no topo
    CONVERSION_LABEL:       ""    // vazio de proposito — ver nota no topo
  };
  // =========================================================

  if (window.__TRACKING__) return;
  window.__TRACKING__ = true;

  var head = document.head || document.documentElement;

  function loadScript(src, async, defer) {
    var s = document.createElement("script");
    if (async) s.setAttribute("async", "");
    if (defer) s.setAttribute("defer", "");
    s.src = src;
    head.appendChild(s);
    return s;
  }

  // ---------------------------------------------------------------- UTMify
  // Faz o pageview e amarra a visita aos parametros de atribuicao em TODA
  // pagina do funil — por isso mora aqui, e nao so na landing.
  //
  // O snippet que a UTMify entrega vem ofuscado (base64 + XOR) para dificultar
  // o bloqueio por adblock. O conteudo dele e exatamente o que esta abaixo:
  // definir o global e carregar o script do CDN. Mantemos em claro porque a
  // ofuscacao nao esconde nada de fato — a requisicao ao cdn.utmify.com.br
  // acontece igual — e assim da para ler e manter o arquivo.
  //
  // Os dois pixels tem global e script DIFERENTES. Nao trocar um pelo outro.
  if (CONFIG.UTMIFY_GOOGLE_PIXEL_ID) {
    window.googlePixelId = CONFIG.UTMIFY_GOOGLE_PIXEL_ID;
    loadScript("https://cdn.utmify.com.br/scripts/pixel/pixel-google.js", true, true);
  }

  if (CONFIG.UTMIFY_META_PIXEL_ID) {
    window.pixelId = CONFIG.UTMIFY_META_PIXEL_ID;
    loadScript("https://cdn.utmify.com.br/scripts/pixel/pixel.js", true, true);
  }

  // ------------------------------------------------------------ Google tag
  window.dataLayer = window.dataLayer || [];
  function gtag() { window.dataLayer.push(arguments); }
  if (!window.gtag) window.gtag = gtag;

  var GOOGLE_ON = !!(CONFIG.GA4_ID || CONFIG.ADS_ID);

  if (GOOGLE_ON) {
    // Um unico gtag.js serve as duas contas; o id da querystring e so o primeiro.
    loadScript(
      "https://www.googletagmanager.com/gtag/js?id=" +
        encodeURIComponent(CONFIG.GA4_ID || CONFIG.ADS_ID),
      true, false
    );
    gtag("js", new Date());

    if (CONFIG.GA4_ID) gtag("config", CONFIG.GA4_ID);
    if (CONFIG.ADS_ID) {
      // conversion_linker mantem o gclid atravessando as paginas do funil
      gtag("config", CONFIG.ADS_ID, { conversion_linker: true });
    }
  }

  // ------------------------------------------------------- Etapa do funil
  // Nome legivel da etapa a partir do caminho, para ver o funil no GA4 sem
  // depender dos nomes de arquivo embaralhados pelo espelhamento.
  function stepName() {
    var p = window.location.pathname;
    var m = p.match(/\/upsell\/(\d+|final)\//);
    if (m) return "upsell_" + m[1];
    if (p.indexOf("/pagamento/") !== -1) return "pagamento";
    if (p.indexOf("/inicio/") !== -1) return "inicio";
    if (p.indexOf("/home/") !== -1) return "home";
    m = p.match(/\/(\d+)\//);
    if (m) return "passo_" + m[1];
    return "outra";
  }

  /**
   * Evento generico. Sai para o GA4 quando configurado; sem GA4 e um no-op.
   * Uso: window.trackEvent('lead_cpf_ok', { passo: 2 })
   */
  window.trackEvent = function (name, params) {
    if (!CONFIG.GA4_ID || !name) return;
    var payload = params || {};
    if (!payload.funnel_step) payload.funnel_step = stepName();
    gtag("event", name, payload);
  };

  // Marca a passagem do lead por cada etapa (1x por carregamento de pagina).
  if (CONFIG.GA4_ID) {
    gtag("event", "funnel_step", { funnel_step: stepName() });
  }

  /**
   * Dispara a conversao de compra. Chamada pelas paginas de pagamento e upsell
   * quando o gateway confirma o PIX como pago.
   *
   * Deduplicada por transacao: se o lead recarregar a pagina de confirmacao,
   * ou se o poll disparar duas vezes, a conversao so vai uma vez.
   */
  window.trackPurchase = function (value, transactionId, offer) {
    if (!GOOGLE_ON) return;

    var key = "gads_conv_" + (transactionId || "sem_id");
    try {
      if (localStorage.getItem(key)) return;
      localStorage.setItem(key, "1");
    } catch (e) { /* modo privado: segue e dispara */ }

    var amount = Number(value) || 0;
    var txn = transactionId || "";
    var step = offer || stepName();

    if (CONFIG.ADS_ID && CONFIG.CONVERSION_LABEL) {
      gtag("event", "conversion", {
        send_to: CONFIG.ADS_ID + "/" + CONFIG.CONVERSION_LABEL,
        value: amount,
        currency: "BRL",
        transaction_id: txn
      });
    }

    if (CONFIG.GA4_ID) {
      gtag("event", "purchase", {
        transaction_id: txn,
        value: amount,
        currency: "BRL",
        funnel_step: step,
        items: [{ item_id: step, item_name: step, price: amount, quantity: 1 }]
      });
    }
  };
})();
