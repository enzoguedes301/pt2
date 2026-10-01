/*! tracking.js — Google Ads (gtag) para o funil.
 *
 * ┌──────────────────────────────────────────────────────────────────┐
 * │  PREENCHA OS DOIS VALORES ABAIXO E O RASTREIO LIGA SOZINHO.      │
 * │                                                                  │
 * │  Onde achar, no painel do Google Ads:                            │
 * │  Objetivos > Conversões > (sua ação de conversão) >              │
 * │  Configuração da tag > "Instalar a tag manualmente"              │
 * │                                                                  │
 * │  Você verá algo como:                                            │
 * │    gtag('event', 'conversion', {                                 │
 * │      'send_to': 'AW-123456789/AbC-D_efG-h12_34-567'              │
 * │    });                                                           │
 * │                     ^^^^^^^^^^^^  ^^^^^^^^^^^^^^^^^^^^           │
 * │                     ADS_ID        CONVERSION_LABEL               │
 * └──────────────────────────────────────────────────────────────────┘
 *
 * Enquanto estiverem vazios, o script nao carrega nada e nao dispara nada —
 * o funil funciona normalmente, so sem rastreio. Nao quebra em hipotese alguma.
 *
 * A captura de gclid/gbraid/wbraid ja e feita pelo utm.js, que roda antes
 * deste arquivo e persiste os parametros por aba durante todo o funil.
 */
(function () {
  "use strict";

  var CONFIG = {
    ADS_ID: "",            // ex.: "AW-123456789"
    CONVERSION_LABEL: ""   // ex.: "AbC-D_efG-h12_34-567"
  };

  if (window.__TRACKING__) return;
  window.__TRACKING__ = true;

  window.dataLayer = window.dataLayer || [];
  function gtag() { window.dataLayer.push(arguments); }
  if (!window.gtag) window.gtag = gtag;

  if (CONFIG.ADS_ID) {
    var s = document.createElement("script");
    s.async = true;
    s.src = "https://www.googletagmanager.com/gtag/js?id=" + encodeURIComponent(CONFIG.ADS_ID);
    (document.head || document.documentElement).appendChild(s);
    gtag("js", new Date());
    gtag("config", CONFIG.ADS_ID);
  }

  /**
   * Dispara a conversao de compra. Chamada pelas paginas de pagamento
   * quando o gateway confirma o PIX como pago.
   *
   * Deduplicada por transacao: se o lead recarregar a pagina de confirmacao,
   * ou se o poll disparar duas vezes, a conversao so vai uma vez para o Google.
   */
  window.trackPurchase = function (value, transactionId) {
    if (!CONFIG.ADS_ID || !CONFIG.CONVERSION_LABEL) return;

    var key = "gads_conv_" + (transactionId || "sem_id");
    try {
      if (localStorage.getItem(key)) return;
      localStorage.setItem(key, "1");
    } catch (e) { /* modo privado: segue e dispara */ }

    gtag("event", "conversion", {
      send_to: CONFIG.ADS_ID + "/" + CONFIG.CONVERSION_LABEL,
      value: Number(value) || 0,
      currency: "BRL",
      transaction_id: transactionId || ""
    });
  };
})();
