<?php
/**
 * Webhook de Notificação de Pagamento
 * O gateway chama este endpoint quando um PIX é confirmado
 *
 * Campos esperados no payload:
 * - id: ID da transação
 * - status: waiting_payment, paid, refused, cancelled, refunded
 * - end2EndId: ID de fim a fim do PIX (quando pago)
 * - metadata: Dados enviados na criação
 * - user: Dados do pagador
 * - transaction: Detalhes da transação
 */

@ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../config.php';

// ============= LOG WEBHOOK =============
function logWebhook($status, $data = []) {
    $log_file = __DIR__ . '/logs/webhooks.log';
    @mkdir(dirname($log_file), 0755, true);
    $timestamp = date('Y-m-d H:i:s');
    $json = json_encode(array_merge(['timestamp' => $timestamp, 'status' => $status], $data));
    @file_put_contents($log_file, $json . PHP_EOL, FILE_APPEND);
}

// Mesma chave usada em php/api.php (hashKey) — precisa bater para o
// "já foi pago" em generatePIX() encontrar o marcador gravado aqui.
function hashKey($offer, $cpf, $email) {
    return md5("$offer|$cpf|$email");
}

// ============= PROCESSAR WEBHOOK =============
try {
    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input) {
        http_response_code(400);
        echo json_encode(['error' => 'Payload vazio']);
        logWebhook('EMPTY_PAYLOAD', $_SERVER);
        exit;
    }

    logWebhook('RECEIVED', [
        'transaction_id' => $input['id'] ?? null,
        'status'         => $input['status'] ?? null
    ]);

    // Validar estrutura básica
    if (empty($input['id']) || empty($input['status'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Estrutura inválida']);
        exit;
    }

    $transactionId = $input['id'];
    $status = $input['status'];
    $metadata = $input['metadata'] ?? [];
    $offer = $metadata['offer'] ?? 'unknown';
    $payerCpf = $input['user']['cpf'] ?? '';
    $payerEmail = $input['user']['email'] ?? '';

    // ============= PROCESSAR POR STATUS =============
    switch ($status) {
        case 'paid':
            // Pagamento confirmado! Grava com a MESMA chave que php/api.php usa
            // em generatePIX() (hashKey: offer|cpf|email) para que o "já foi pago"
            // seja detectado corretamente numa nova chamada de geração de PIX.
            $paidFile = __DIR__ . '/paid/' . hashKey($offer, $payerCpf, $payerEmail) . '.txt';
            @mkdir(dirname($paidFile), 0755, true);
            @file_put_contents($paidFile, json_encode([
                'transaction_id' => $transactionId,
                'paid_at'        => date('Y-m-d H:i:s'),
                'amount'         => $input['transaction']['amount'] ?? 0,
                'net_amount'     => $input['transaction']['net_amount'] ?? 0,
                'offer'          => $offer,
                'end2EndId'      => $input['end2EndId'] ?? null,
                'user'           => $input['user'] ?? []
            ]));

            logWebhook('PAYMENT_CONFIRMED', [
                'transaction_id' => $transactionId,
                'offer'          => $offer,
                'amount'         => $input['transaction']['amount'] ?? 0,
                'end2EndId'      => $input['end2EndId'] ?? null
            ]);

            http_response_code(200);
            echo json_encode(['success' => true, 'message' => 'Pagamento registrado']);
            break;

        case 'refused':
            logWebhook('PAYMENT_REFUSED', [
                'transaction_id' => $transactionId,
                'offer'          => $offer
            ]);
            http_response_code(200);
            echo json_encode(['success' => true, 'message' => 'Recusa registrada']);
            break;

        case 'cancelled':
            logWebhook('PAYMENT_CANCELLED', [
                'transaction_id' => $transactionId,
                'offer'          => $offer
            ]);
            http_response_code(200);
            echo json_encode(['success' => true, 'message' => 'Cancelamento registrado']);
            break;

        case 'waiting_payment':
            // PIX gerado, aguardando pagamento
            logWebhook('PAYMENT_PENDING', [
                'transaction_id' => $transactionId,
                'offer'          => $offer
            ]);
            http_response_code(200);
            echo json_encode(['success' => true, 'message' => 'Pagamento aguardado']);
            break;

        case 'refunded':
            logWebhook('PAYMENT_REFUNDED', [
                'transaction_id' => $transactionId,
                'offer'          => $offer
            ]);
            http_response_code(200);
            echo json_encode(['success' => true, 'message' => 'Reembolso registrado']);
            break;

        default:
            logWebhook('UNKNOWN_STATUS', [
                'transaction_id' => $transactionId,
                'status'         => $status
            ]);
            http_response_code(200);
            echo json_encode(['success' => true, 'message' => 'Status recebido']);
            break;
    }

} catch (Exception $e) {
    logWebhook('EXCEPTION', [
        'error'   => $e->getMessage(),
        'trace'   => $e->getTraceAsString()
    ]);

    http_response_code(500);
    echo json_encode(['error' => 'Erro ao processar webhook']);
}
