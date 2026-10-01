<?php
/**
 * Proxy de consulta de CPF – roda no servidor, nunca expõe o token ao navegador.
 *
 * Endpoint: magmadatahub.com/api.php
 * Requisitos: PHP 7.4+, extensão cURL, acesso HTTPS de saída.
 */

// Impede que warnings/notices do PHP corrompam a resposta JSON
@ini_set('display_errors', '0');

// Headers CORS
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// -- Token da API (server-side only – nunca exposto ao navegador) -------------
// Fica em config.php, que nao esta no git. Ver config.example.php.
if (is_file(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
}

if (!defined('MAGMA_DATAHUB_TOKEN') || MAGMA_DATAHUB_TOKEN === '' || MAGMA_DATAHUB_TOKEN === 'seu-token-aqui') {
    http_response_code(503);
    echo json_encode(['success' => false, 'erro' => 'Servico de consulta nao configurado.']);
    exit;
}

$token = MAGMA_DATAHUB_TOKEN;

// -- Validação básica do CPF --------------------------------------------------
$cpf = preg_replace('/\D/', '', $_GET['cpf'] ?? '');

if (strlen($cpf) !== 11) {
    http_response_code(400);
    echo json_encode(['success' => false, 'erro' => 'CPF inválido']);
    exit;
}

if (preg_match('/^(\d)\1{10}$/', $cpf)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'erro' => 'CPF inválido']);
    exit;
}

// -- Verificar extensão cURL --------------------------------------------------
if (!function_exists('curl_init')) {
    http_response_code(503);
    echo json_encode(['success' => false, 'erro' => 'Serviço temporariamente indisponível']);
    exit;
}

// -- Chamada à API externa (server-side) --------------------------------------
$url = "https://magmadatahub.com/api.php?token=" . urlencode($token) . "&cpf=" . urlencode($cpf);

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL            => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_CONNECTTIMEOUT => 6,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS      => 3,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_HTTPHEADER     => [
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/121.0.0.0',
        'Accept: application/json',
    ],
]);

$response  = curl_exec($ch);
$httpCode  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErrno = curl_errno($ch);
curl_close($ch);

// -- Erros de conexão / timeout -----------------------------------------------
if ($curlErrno !== 0) {
    http_response_code(503);
    echo json_encode(['success' => false, 'erro' => 'Serviço de consulta indisponível no momento. Tente novamente em instantes.']);
    exit;
}

// -- Falha de autenticação ----------------------------------------------------
if ($httpCode === 401 || $httpCode === 403) {
    http_response_code(503);
    echo json_encode(['success' => false, 'erro' => 'Serviço temporariamente indisponível']);
    exit;
}

// -- Limite de requisições ----------------------------------------------------
if ($httpCode === 429) {
    http_response_code(429);
    echo json_encode(['success' => false, 'erro' => 'Muitas consultas em sequência. Aguarde alguns instantes e tente novamente.']);
    exit;
}

// -- API indisponível (5xx) ---------------------------------------------------
if ($httpCode >= 500) {
    http_response_code(503);
    echo json_encode(['success' => false, 'erro' => 'Serviço de consulta indisponível. Tente novamente mais tarde.']);
    exit;
}

// -- Resposta não é JSON válido -----------------------------------------------
$data = json_decode($response, true);
if (!$data || !is_array($data)) {
    http_response_code(503);
    echo json_encode(['success' => false, 'erro' => 'Resposta inválida do serviço de consulta.']);
    exit;
}

// -- CPF encontrado com sucesso -----------------------------------------------
if ($httpCode === 200 && !empty($data['success']) && $data['success'] === true && !empty($data['nome'])) {
    echo json_encode([
        'success'    => true,
        'nome'       => $data['nome'],
        'cpf'        => $cpf,
        'nascimento' => $data['nascimento'] ?? '',
        'mae'        => $data['nome_mae'] ?? '',
        'sexo'       => $data['sexo'] ?? '',
    ]);
    exit;
}

// -- CPF não encontrado ou dados insuficientes --------------------------------
http_response_code(404);
echo json_encode(['success' => false, 'erro' => 'CPF não encontrado na base de dados.']);
exit;
