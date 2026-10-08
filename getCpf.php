<?php
/**
 * Proxy de consulta de CPF – roda no servidor, nunca expõe o token ao navegador.
 *
 * NAO e amarrado a nenhum provedor: endpoint, autenticacao e nomes dos campos
 * vem todos do config.php (ver config.example.php). Para trocar de servico de
 * consulta basta editar o config.php no servidor — este arquivo nao muda.
 *
 * Resposta, sempre no mesmo formato (independente do provedor):
 *   sucesso -> {"success":true,"nome":"...","cpf":"...","nascimento":"...","mae":"...","sexo":"..."}
 *   falha   -> {"success":false,"erro":"mensagem para o usuario"}
 *
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

function responder(array $corpo, int $status = 200) {
    http_response_code($status);
    echo json_encode($corpo, JSON_UNESCAPED_UNICODE);
    exit;
}

// -- Configuracao (server-side only – nunca exposta ao navegador) -------------
// Fica em config.php, que nao esta no git. Ver config.example.php.
if (is_file(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
}

$urlModelo = defined('CPF_API_URL') ? trim(CPF_API_URL) : '';
$token     = defined('CPF_API_TOKEN') ? trim(CPF_API_TOKEN) : '';

// Sem endpoint configurado o proxy nao tem o que consultar.
if ($urlModelo === '' || $urlModelo === 'preencher') {
    responder(['success' => false, 'erro' => 'Servico de consulta nao configurado.'], 503);
}

// Token so e obrigatorio quando ele participa da chamada
$auth = defined('CPF_API_AUTH') ? strtolower(trim(CPF_API_AUTH)) : 'url';
if ($auth !== 'none' && ($token === '' || $token === 'preencher' || $token === 'seu-token-aqui')) {
    responder(['success' => false, 'erro' => 'Servico de consulta nao configurado.'], 503);
}

// -- Validação do CPF (formato + dígitos verificadores) -----------------------
$cpf = preg_replace('/\D/', '', $_GET['cpf'] ?? '');

function cpfValido(string $cpf): bool {
    if (strlen($cpf) !== 11) {
        return false;
    }
    if (preg_match('/^(\d)\1{10}$/', $cpf)) {
        return false;
    }
    // Confere os dois digitos verificadores: evita gastar consulta com CPF falso
    for ($digitos = 9; $digitos < 11; $digitos++) {
        $soma = 0;
        for ($i = 0; $i < $digitos; $i++) {
            $soma += (int) $cpf[$i] * (($digitos + 1) - $i);
        }
        $resto = ($soma * 10) % 11;
        if ($resto === 10) {
            $resto = 0;
        }
        if ($resto !== (int) $cpf[$digitos]) {
            return false;
        }
    }
    return true;
}

if (!cpfValido($cpf)) {
    responder(['success' => false, 'erro' => 'CPF inválido'], 400);
}

// -- Verificar extensão cURL --------------------------------------------------
if (!function_exists('curl_init')) {
    responder(['success' => false, 'erro' => 'Serviço temporariamente indisponível'], 503);
}

// -- Montagem da chamada ------------------------------------------------------
// Os marcadores {cpf} e {token} sao trocados aqui, ja escapados para URL.
$url = str_replace(
    ['{cpf}', '{token}'],
    [urlencode($cpf), urlencode($token)],
    $urlModelo
);

$metodo = defined('CPF_API_METODO') ? strtoupper(trim(CPF_API_METODO)) : 'GET';
$metodo = $metodo === 'POST' ? 'POST' : 'GET';

$headers = [
    'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/121.0.0.0',
    'Accept: application/json',
];

if ($auth === 'bearer') {
    $headers[] = 'Authorization: Bearer ' . $token;
} elseif ($auth === 'header') {
    $nomeHeader = defined('CPF_API_AUTH_HEADER') ? trim(CPF_API_AUTH_HEADER) : 'Authorization';
    if ($nomeHeader !== '') {
        $headers[] = $nomeHeader . ': ' . $token;
    }
}
// 'url' e 'none' nao precisam de header: o token (quando existe) ja esta na URL.

$opcoes = [
    CURLOPT_URL            => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_CONNECTTIMEOUT => 6,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS      => 3,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_HTTPHEADER     => $headers,
];

if ($metodo === 'POST') {
    // Corpo em JSON, tambem com os marcadores {cpf} / {token}
    $modeloCorpo = defined('CPF_API_CORPO') ? trim(CPF_API_CORPO) : '{"cpf":"{cpf}"}';
    $corpo = str_replace(['{cpf}', '{token}'], [$cpf, $token], $modeloCorpo);

    $opcoes[CURLOPT_POST]       = true;
    $opcoes[CURLOPT_POSTFIELDS] = $corpo;
    $opcoes[CURLOPT_HTTPHEADER] = array_merge($headers, ['Content-Type: application/json']);
}

$ch = curl_init();
curl_setopt_array($ch, $opcoes);

$response  = curl_exec($ch);
$httpCode  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErrno = curl_errno($ch);
curl_close($ch);

// -- Modo diagnostico ---------------------------------------------------------
// Ligar CPF_API_DEBUG no config.php e chamar getCpf.php?cpf=...&debug=1 mostra
// a resposta crua do provedor — e o jeito de descobrir os nomes dos campos ao
// plugar um servico novo. O token nunca aparece aqui. Desligue depois.
$debug = defined('CPF_API_DEBUG') && CPF_API_DEBUG === true && isset($_GET['debug']);
if ($debug) {
    responder([
        'success'    => false,
        'erro'       => 'Modo diagnostico',
        'debug'      => [
            'http'        => $httpCode,
            'curl_errno'  => $curlErrno,
            'metodo'      => $metodo,
            'url'         => str_replace(urlencode($token), '***', $url),
            'resposta'    => is_string($response) ? substr($response, 0, 4000) : null,
        ],
    ]);
}

// -- Erros de conexão / timeout -----------------------------------------------
if ($curlErrno !== 0) {
    responder(['success' => false, 'erro' => 'Serviço de consulta indisponível no momento. Tente novamente em instantes.'], 503);
}

// -- Falha de autenticação ----------------------------------------------------
if ($httpCode === 401 || $httpCode === 403) {
    responder(['success' => false, 'erro' => 'Serviço temporariamente indisponível'], 503);
}

// -- Limite de requisições ----------------------------------------------------
if ($httpCode === 429) {
    responder(['success' => false, 'erro' => 'Muitas consultas em sequência. Aguarde alguns instantes e tente novamente.'], 429);
}

// -- API indisponível (5xx) ---------------------------------------------------
if ($httpCode >= 500) {
    responder(['success' => false, 'erro' => 'Serviço de consulta indisponível. Tente novamente mais tarde.'], 503);
}

// -- Resposta não é JSON válido -----------------------------------------------
$data = json_decode($response, true);
if (!$data || !is_array($data)) {
    responder(['success' => false, 'erro' => 'Resposta inválida do serviço de consulta.'], 503);
}

// -- Leitura dos campos -------------------------------------------------------
/**
 * Busca um valor dentro da resposta seguindo um caminho com pontos
 * ('dados.nome', 'result.0.nome'). Varios caminhos podem ser separados
 * por '|' — o primeiro que existir ganha, o que deixa um mesmo config
 * servir para provedores que nomeiam os campos de formas diferentes.
 */
function valorEm(array $dados, string $caminhos): string {
    foreach (explode('|', $caminhos) as $caminho) {
        $caminho = trim($caminho);
        if ($caminho === '') {
            continue;
        }

        $atual = $dados;
        $achou = true;
        foreach (explode('.', $caminho) as $parte) {
            if (is_array($atual) && array_key_exists($parte, $atual)) {
                $atual = $atual[$parte];
            } else {
                $achou = false;
                break;
            }
        }

        if ($achou && is_scalar($atual) && trim((string) $atual) !== '') {
            return trim((string) $atual);
        }
    }
    return '';
}

$mapa = (isset($CPF_API_CAMPOS) && is_array($CPF_API_CAMPOS)) ? $CPF_API_CAMPOS : [];
$mapa += [
    'nome'       => 'nome|dados.nome|data.nome|result.nome',
    'nascimento' => 'nascimento|data_nascimento|dados.nascimento|dados.data_nascimento',
    'mae'        => 'nome_mae|mae|dados.nome_mae|dados.mae',
    'sexo'       => 'sexo|genero|dados.sexo',
];

$nome = valorEm($data, (string) $mapa['nome']);

// -- CPF encontrado com sucesso -----------------------------------------------
// O nome e o minimo: sem ele a consulta nao serve para o funil.
if ($httpCode === 200 && $nome !== '') {
    responder([
        'success'    => true,
        'nome'       => $nome,
        'cpf'        => $cpf,
        'nascimento' => valorEm($data, (string) $mapa['nascimento']),
        'mae'        => valorEm($data, (string) $mapa['mae']),
        'sexo'       => valorEm($data, (string) $mapa['sexo']),
    ]);
}

// -- CPF não encontrado ou dados insuficientes --------------------------------
responder(['success' => false, 'erro' => 'CPF não encontrado na base de dados.'], 404);
