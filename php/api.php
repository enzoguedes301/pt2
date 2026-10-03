<?php
/**
 * API de Pagamento PIX — integração com o gateway
 *
 * Endpoints:
 * POST /php/api.php?action=generate  — Gera novo QR code PIX
 * POST /php/api.php?action=status    — Verifica status do pagamento
 *
 */

@ini_set('display_errors', '0');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ============= CARREGAR CONFIGURAÇÕES =============
require_once __DIR__ . '/../config.php';

// ============= FUNÇÕES AUXILIARES =============
function logTransaction($msg, $data = []) {
    $log_file = __DIR__ . '/logs/transactions.log';
    @mkdir(dirname($log_file), 0755, true);
    $timestamp = date('Y-m-d H:i:s');
    $json = json_encode(array_merge(['timestamp' => $timestamp, 'msg' => $msg], $data));
    @file_put_contents($log_file, $json . PHP_EOL, FILE_APPEND);
}

function response($success, $data = [], $httpCode = 200) {
    http_response_code($httpCode);
    echo json_encode(array_merge(['success' => $success], $data));
    exit;
}

function hashKey($offer, $cpf, $email) {
    return md5("$offer|$cpf|$email");
}

// ============= CHAMADA A API DO GATEWAY =============
function callGatewayAPI($method, $endpoint, $body = null) {
    $url = GATEWAY_ENDPOINT . $endpoint;

    $headers = [
        'X-API-Key: ' . GATEWAY_API_KEY,
        'Content-Type: application/json',
        'Accept: application/json'
    ];

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErrno = curl_errno($ch);
    curl_close($ch);

    if ($curlErrno !== 0) {
        logTransaction('CURL_ERROR', ['errno' => $curlErrno, 'endpoint' => $endpoint]);
        throw new Exception('Erro de conexão com o gateway de pagamento');
    }

    $data = json_decode($response, true);
    if (!is_array($data)) {
        logTransaction('INVALID_JSON', ['response' => substr($response, 0, 200), 'endpoint' => $endpoint]);
        throw new Exception('Resposta inválida do gateway de pagamento');
    }

    return ['code' => $httpCode, 'data' => $data];
}

// ============= GERAR QR CODE PIX =============
function generatePIX($offer, $name, $document, $email, $phone, $utms = [], $pixTotal = null) {
    global $OFFERS_CONFIG;

    if (!isset($OFFERS_CONFIG[$offer])) {
        throw new Exception('Oferta inválida');
    }

    $config = $OFFERS_CONFIG[$offer];
    // pix_total (preço dinâmico do seguro) só é aceito na oferta "main" — nos
    // upsells o preço é sempre o preco_base do config.php, nunca o valor enviado
    // pelo cliente (evita manipulação do valor cobrado via chamada direta à API).
    $amountReais = ($offer === 'main' && $pixTotal) ? $pixTotal : $config['preco_base'];
    $amountCentavos = (int)($amountReais * 100);

    // Validação básica
    if (empty($document) || strlen(preg_replace('/\D/', '', $document)) !== 11) {
        throw new Exception('CPF inválido');
    }

    if ($amountCentavos < 500) {
        throw new Exception('Valor mínimo: R$ 5,00');
    }

    if ($amountCentavos > 60000) {
        throw new Exception('Valor máximo: R$ 600,00');
    }

    // Verifica se já foi pago neste navegador
    $txKey = hashKey($offer, $document, $email);
    $paidFile = __DIR__ . '/paid/' . $txKey . '.txt';
    @mkdir(dirname($paidFile), 0755, true);

    if (file_exists($paidFile)) {
        return [
            'already_paid' => true,
            'message' => 'Esta etapa já foi paga'
        ];
    }

    // Prepara os dados para o gateway
    $payload = [
        'amount'        => $amountCentavos,
        'paymentMethod' => 'pix',
        'pix'           => [
            'expiresInDays' => 1  // Expira em 24h
        ],
        'customer'      => [
            'name'  => $name,
            'email' => $email,
            'phone' => preg_replace('/\D/', '', $phone),
            'document' => [
                'number' => preg_replace('/\D/', '', $document),
                'type'   => 'cpf'
            ]
        ],
        'items'         => [
            [
                'title'       => $config['descricao'],
                'unitPrice'   => $amountCentavos,
                'quantity'    => 1,
                'tangible'    => false,
                'externalRef' => $offer
            ]
        ],
        'metadata'      => [
            'offer'        => $offer,
            'funnel_id'    => isset($utms['funnel_session_id']) ? $utms['funnel_session_id'] : '',
            'utm_source'   => $utms['utm_source'] ?? '',
            'utm_campaign' => $utms['utm_campaign'] ?? ''
        ],
        'postbackUrl'   => WEBHOOK_URL
    ];

    try {
        // Chama o gateway para gerar a transação
        $result = callGatewayAPI('POST', '/transactions', $payload);

        // o gateway retorna 201 em sucesso
        if ($result['code'] !== 201 && $result['code'] !== 200) {
            logTransaction('GATEWAY_ERROR', [
                'code' => $result['code'],
                'response' => $result['data']
            ]);
            throw new Exception($result['data']['message'] ?? 'Erro ao gerar QR code');
        }

        $transaction = $result['data'];

        if (empty($transaction['id']) || empty($transaction['pix']['qrcode'])) {
            throw new Exception('Resposta incompleta do gateway de pagamento');
        }

        // Log de sucesso
        logTransaction('PIX_GENERATED', [
            'transaction_id' => $transaction['id'],
            'offer'          => $offer,
            'amount'         => $amountReais,
            'cpf'            => preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.***.***-**', $document)
        ]);

        return [
            'success'        => true,
            'transaction_id' => $transaction['id'],
            'pix'            => [
                'transaction_id'   => $transaction['id'],
                'qr_code'          => $transaction['pix']['qrcode'],
                'qr_code_base64'   => $transaction['pix']['qrcodeImage'] ?? '',
                'amount'           => $amountReais,
                'expires_at'       => (time() + 24*60*60) * 1000  // 24h em ms
            ]
        ];

    } catch (Exception $e) {
        logTransaction('PIX_ERROR', [
            'error'  => $e->getMessage(),
            'offer'  => $offer
        ]);
        throw $e;
    }
}

// ============= VERIFICAR STATUS DO PAGAMENTO =============
function checkPaymentStatus($transactionId) {
    try {
        $result = callGatewayAPI('GET', '/transactions/' . $transactionId);

        if ($result['code'] !== 200) {
            throw new Exception('Transação não encontrada');
        }

        $transaction = $result['data'];
        $status = $transaction['status'] ?? 'unknown';

        // Mapeamento dos status do gateway
        $statusMap = [
            'waiting_payment' => 'pending',
            'paid'            => 'paid',
            'refused'         => 'failed',
            'cancelled'       => 'cancelled',
            'refunded'        => 'refunded'
        ];

        $normalizedStatus = $statusMap[$status] ?? 'unknown';

        if ($normalizedStatus === 'paid') {
            logTransaction('PAYMENT_CONFIRMED', [
                'transaction_id' => $transactionId,
                'end2EndId'      => $transaction['end2EndId'] ?? null
            ]);
        }

        return [
            'success'        => true,
            'status'         => $normalizedStatus,
            'transaction_id' => $transactionId,
            'paid_at'        => null
        ];

    } catch (Exception $e) {
        logTransaction('STATUS_CHECK_ERROR', [
            'error'            => $e->getMessage(),
            'transaction_id'   => $transactionId
        ]);

        return [
            'success' => false,
            'error'   => 'Erro ao verificar status'
        ];
    }
}

// ============= ROTAS =============
try {
    $action = $_GET['action'] ?? '';

    if ($action === 'generate') {
        // POST /api.php?action=generate
        $input = json_decode(file_get_contents('php://input'), true);

        if (!$input) {
            response(false, ['error' => 'Dados inválidos'], 400);
        }

        $offer = $input['offer'] ?? '';
        $name = $input['name'] ?? '';
        $document = $input['document'] ?? '';
        $email = $input['email'] ?? '';
        $phone = $input['phone'] ?? '';
        $utms = $input['utms'] ?? [];
        $pixTotal = $input['pix_total'] ?? null;

        $result = generatePIX($offer, $name, $document, $email, $phone, $utms, $pixTotal);
        response($result['success'] ?? true, $result, isset($result['already_paid']) ? 200 : 201);

    } else if ($action === 'status') {
        // POST /api.php?action=status
        $input = json_decode(file_get_contents('php://input'), true);

        if (!isset($input['transaction_id'])) {
            response(false, ['error' => 'transaction_id obrigatório'], 400);
        }

        $result = checkPaymentStatus($input['transaction_id']);
        response($result['success'], $result);

    } else {
        response(false, ['error' => 'Action inválida'], 400);
    }

} catch (Exception $e) {
    $msg = $e->getMessage();
    logTransaction('EXCEPTION', ['error' => $msg]);
    response(false, ['error' => $msg], 503);
}
