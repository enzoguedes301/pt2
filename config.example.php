<?php
/**
 * MODELO DE CONFIGURACAO — copie para config.php e preencha com os valores reais.
 *
 * O config.php real nunca e versionado: ele existe apenas na sua maquina e no
 * servidor. Este arquivo serve so para lembrar quais chaves precisam existir.
 */

// ============= GATEWAY DE PAGAMENTO =============
// Credenciais fornecidas pelo provedor contratado.
define('GATEWAY_API_KEY', 'preencher');
define('GATEWAY_ENDPOINT', 'preencher');

// ============= CONSULTA DE DADOS =============
// Token do servico usado por getCpf.php.
define('MAGMA_DATAHUB_TOKEN', 'preencher');

// ============= UTMIFY =============
// Credencial de API: painel > Integracoes > Webhooks > Credenciais de API >
// Adicionar Credencial. O token so aparece no momento da criacao.
// Tem que ser do MESMO dashboard do pixel configurado em js/tracking.js —
// se forem diferentes, a visita cai num painel e a venda em outro.
// Em branco = envio desligado (o funil segue funcionando normalmente).
define('UTMIFY_API_TOKEN', '');

// ============= AMBIENTE =============
// 'sandbox' para testes, 'producao' para operacao real.
define('AMBIENTE', 'producao');

// ============= CALLBACK =============
// Montado a partir do host atual, entao funciona em qualquer dominio
// configurado no servidor sem precisar editar este arquivo.
$protocolo = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
define('WEBHOOK_URL', $protocolo . '://' . $host . '/php/webhook.php');

// ============= TRANSACAO =============
// Tempo de expiracao da cobranca, em minutos (padrao: 24 horas).
define('PIX_EXPIRA_EM_MINUTOS', 1440);

// ============= OFERTAS =============
// Uma entrada por etapa. O 'preco_base' e o valor efetivamente cobrado e
// precisa bater com o que a pagina correspondente exibe.
$OFFERS_CONFIG = [
    'main' => ['nome' => 'Etapa principal', 'preco_base' => 0.00, 'descricao' => ''],
    'up1'  => ['nome' => 'Etapa 1',         'preco_base' => 0.00, 'descricao' => ''],
    'up2'  => ['nome' => 'Etapa 2',         'preco_base' => 0.00, 'descricao' => ''],
    'up3'  => ['nome' => 'Etapa 3',         'preco_base' => 0.00, 'descricao' => ''],
    'up4'  => ['nome' => 'Etapa 4',         'preco_base' => 0.00, 'descricao' => ''],
    'up5'  => ['nome' => 'Etapa 5',         'preco_base' => 0.00, 'descricao' => ''],
    'up6'  => ['nome' => 'Etapa 6',         'preco_base' => 0.00, 'descricao' => ''],
];
