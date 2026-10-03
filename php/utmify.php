<?php
/**
 * Envio de pedidos para a UTMify.
 *
 * POR QUE ISSO EXISTE
 * -------------------
 * O pixel da UTMify (js/tracking.js) so registra a VISITA. Quem dispara a
 * conversao para o Google Ads e a UTMify, server-side, e para isso ela precisa
 * saber que a venda aconteceu. Com checkout proprio (Skale Pay) ela nao tem
 * como descobrir sozinha — quem avisa e este arquivo.
 *
 * Fluxo documentado pela UTMify para checkout proprio + gateway PIX:
 *   1. PIX gerado    -> envia o pedido com status "waiting_payment"
 *   2. PIX confirmado-> reenvia o MESMO orderId com status "paid"
 *
 * Como o webhook do gateway nao recebe os UTMs (o metadata da transacao so
 * carrega offer/funnel_id/utm_source/utm_campaign), no passo 1 gravamos um
 * arquivo com o pedido completo e no passo 2 so trocamos o status. Sem isso a
 * venda chegaria na UTMify sem atribuicao, que e o mesmo que nao chegar.
 *
 * REGRA DE OURO: nada aqui pode derrubar o checkout. Toda falha e engolida e
 * logada — o lead tem que ver o QR code mesmo se a UTMify estiver fora do ar.
 */

if (!defined('UTMIFY_ENDPOINT')) {
    define('UTMIFY_ENDPOINT', 'https://api.utmify.com.br/api-credentials/orders');
}

function utmifyLog($status, $data = []) {
    $log_file = __DIR__ . '/logs/utmify.log';
    @mkdir(dirname($log_file), 0755, true);
    $json = json_encode(array_merge(
        ['timestamp' => date('Y-m-d H:i:s'), 'status' => $status],
        $data
    ), JSON_UNESCAPED_UNICODE);
    @file_put_contents($log_file, $json . PHP_EOL, FILE_APPEND);
}

function utmifyEnabled() {
    return defined('UTMIFY_API_TOKEN') && UTMIFY_API_TOKEN !== '';
}

/** Caminho do pedido pendente gravado na geracao do PIX. */
function utmifyOrderFile($transactionId) {
    $safe = preg_replace('/[^A-Za-z0-9_\-]/', '', (string)$transactionId);
    return __DIR__ . '/utmify/' . $safe . '.json';
}

/**
 * A UTMify espera data em UTC, no formato "Y-m-d H:i:s".
 * O servidor roda em America/Sao_Paulo, entao converter e obrigatorio —
 * mandar horario local jogaria a venda 3h para o futuro e ela seria recusada.
 */
function utmifyNowUtc() {
    return gmdate('Y-m-d H:i:s');
}

/** Primeiro IP real do visitante, atravessando proxy/CDN quando houver. */
function utmifyClientIp() {
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $key) {
        if (empty($_SERVER[$key])) continue;
        $ip = trim(explode(',', $_SERVER[$key])[0]);
        if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
    }
    return '0.0.0.0';
}

/** Envia o pedido. Devolve true/false; nunca lanca excecao. */
function utmifyPost(array $order) {
    if (!utmifyEnabled()) return false;

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => UTMIFY_ENDPOINT,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($order, JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true,
        // Curto de proposito: a UTMify esta no caminho do lead ver o QR code.
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER     => [
            'x-api-token: ' . UTMIFY_API_TOKEN,
            'Content-Type: application/json',
            'Accept: application/json',
        ],
    ]);

    $response = curl_exec($ch);
    $code     = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $errno    = curl_errno($ch);
    $errmsg   = curl_error($ch);
    curl_close($ch);

    if ($errno !== 0) {
        utmifyLog('CURL_ERROR', [
            'order_id' => $order['orderId'] ?? null,
            'errno'    => $errno,
            'error'    => $errmsg,
        ]);
        return false;
    }

    $ok = ($code >= 200 && $code < 300);
    utmifyLog($ok ? 'SENT' : 'HTTP_ERROR', [
        'order_id'    => $order['orderId'] ?? null,
        'order_status'=> $order['status'] ?? null,
        'http_code'   => $code,
        'response'    => $ok ? null : substr((string)$response, 0, 300),
    ]);

    return $ok;
}

/**
 * Monta o pedido no formato da UTMify.
 * Valores SEMPRE em centavos (a API rejeita reais).
 */
function utmifyBuildOrder(array $p) {
    $utms = $p['utms'] ?? [];

    // A UTMify so aceita estas chaves em trackingParameters; qualquer outra
    // (gclid, fbclid, funnel_session_id...) e ignorada por ela.
    $tracking = [];
    foreach (['src', 'sck', 'utm_source', 'utm_campaign', 'utm_medium', 'utm_content', 'utm_term'] as $k) {
        $v = isset($utms[$k]) && $utms[$k] !== '' ? (string)$utms[$k] : null;
        $tracking[$k] = $v;
    }

    $totalCents   = (int)$p['amount_cents'];
    $gatewayFee   = (int)($p['gateway_fee_cents'] ?? 0);
    // userCommissionInCents nao pode ser zero: sem dado de taxa, vale o total.
    $userCommission = max(0, $totalCents - $gatewayFee);
    if ($userCommission === 0) $userCommission = $totalCents;

    return [
        'orderId'       => (string)$p['transaction_id'],
        'platform'      => 'SkalePayments',
        'paymentMethod' => 'pix',
        'status'        => $p['status'],
        'createdAt'     => $p['created_at'],
        'approvedDate'  => $p['approved_at'] ?? null,
        'refundedAt'    => $p['refunded_at'] ?? null,
        'customer'      => [
            'name'     => (string)($p['name'] ?? ''),
            'email'    => (string)($p['email'] ?? ''),
            'phone'    => $p['phone'] !== '' ? (string)$p['phone'] : null,
            'document' => $p['document'] !== '' ? (string)$p['document'] : null,
            'country'  => 'BR',
            'ip'       => (string)($p['ip'] ?? '0.0.0.0'),
        ],
        'products'      => [[
            'id'           => (string)$p['offer'],
            'name'         => (string)$p['product_name'],
            'planId'       => null,
            'planName'     => null,
            'quantity'     => 1,
            'priceInCents' => $totalCents,
        ]],
        'trackingParameters' => $tracking,
        'commission'    => [
            'totalPriceInCents'     => $totalCents,
            'gatewayFeeInCents'     => $gatewayFee,
            'userCommissionInCents' => $userCommission,
            'currency'              => 'BRL',
        ],
        // Em sandbox a venda entra marcada como teste e nao suja o dashboard.
        'isTest' => (defined('AMBIENTE') && AMBIENTE !== 'producao'),
    ];
}

/**
 * Passo 1 — PIX gerado. Envia "waiting_payment" e guarda o pedido para o
 * webhook poder reenviar como "paid" depois, ja com os UTMs certos.
 */
function utmifyOrderCreated(array $p) {
    if (!utmifyEnabled()) return;

    try {
        $p['status']     = 'waiting_payment';
        $p['created_at'] = utmifyNowUtc();
        $p['ip']         = utmifyClientIp();

        $order = utmifyBuildOrder($p);

        $file = utmifyOrderFile($p['transaction_id']);
        @mkdir(dirname($file), 0755, true);
        @file_put_contents($file, json_encode($order, JSON_UNESCAPED_UNICODE));

        utmifyPost($order);
    } catch (Exception $e) {
        utmifyLog('EXCEPTION_CREATED', ['error' => $e->getMessage()]);
    }
}

/**
 * Passo 2 — gateway confirmou. Reenvia o MESMO orderId com o novo status.
 * E o reenvio que faz a UTMify mandar a conversao para o Google.
 *
 * @param string $status 'paid' | 'refunded' | 'refused' | 'chargedback'
 */
function utmifyOrderUpdated($transactionId, $status, array $extra = []) {
    if (!utmifyEnabled()) return;

    try {
        $file = utmifyOrderFile($transactionId);
        $raw  = @file_get_contents($file);
        $order = $raw ? json_decode($raw, true) : null;

        if (!is_array($order)) {
            // Sem o arquivo nao ha UTMs, e pedido sem atribuicao nao serve de
            // nada para a UTMify. Melhor registrar e nao enviar lixo.
            utmifyLog('ORDER_NOT_FOUND', ['order_id' => $transactionId, 'new_status' => $status]);
            return;
        }

        $order['status'] = $status;

        if ($status === 'paid') {
            $order['approvedDate'] = utmifyNowUtc();
        } elseif ($status === 'refunded') {
            $order['refundedAt'] = utmifyNowUtc();
        }

        // Agora que o gateway liquidou, da para registrar a taxa real.
        if (isset($extra['amount_cents']) && (int)$extra['amount_cents'] > 0) {
            $total = (int)$extra['amount_cents'];
            $net   = isset($extra['net_amount_cents']) ? (int)$extra['net_amount_cents'] : 0;
            $fee   = ($net > 0 && $net <= $total) ? ($total - $net) : 0;

            $order['commission']['totalPriceInCents']     = $total;
            $order['commission']['gatewayFeeInCents']     = $fee;
            $order['commission']['userCommissionInCents'] = $net > 0 ? $net : $total;
            $order['products'][0]['priceInCents']         = $total;
        }

        if (utmifyPost($order)) {
            @file_put_contents($file, json_encode($order, JSON_UNESCAPED_UNICODE));
        }
    } catch (Exception $e) {
        utmifyLog('EXCEPTION_UPDATED', ['error' => $e->getMessage(), 'order_id' => $transactionId]);
    }
}
