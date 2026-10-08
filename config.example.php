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

// ============= CONSULTA DE CPF (getCpf.php) =============
// O proxy nao conhece provedor nenhum: tudo o que ele precisa saber esta aqui.
// Para trocar de servico de consulta, mude so estes valores.
//
// CPF_API_URL  — endpoint do provedor. Use os marcadores {cpf} e {token};
//                eles sao substituidos (ja escapados) na hora da chamada.
// CPF_API_AUTH — como o token viaja: 'url' (dentro do {token} da URL),
//                'bearer' (header Authorization: Bearer <token>),
//                'header' (header proprio, nome em CPF_API_AUTH_HEADER)
//                ou 'none' (provedor sem token).
// CPF_API_METODO / CPF_API_CORPO — use 'POST' quando o provedor pedir corpo
//                JSON; o corpo tambem aceita {cpf} e {token}.
// Valores atuais: provedor blueget / zipcardx. So o TOKEN e segredo — troque
// 'preencher' pelo token real aqui no config.php do servidor (nunca no git).
define('CPF_API_URL', 'https://api.zipcardx.online/api/v1/consult/{cpf}');
define('CPF_API_TOKEN', 'preencher');
define('CPF_API_AUTH', 'bearer');
define('CPF_API_AUTH_HEADER', 'Authorization');
define('CPF_API_METODO', 'GET');
define('CPF_API_CORPO', '{"cpf":"{cpf}"}');

// Onde cada dado fica na resposta JSON do provedor. Ponto desce um nivel
// ('dados.nome', 'result.0.nome') e '|' lista alternativas — o primeiro
// caminho que existir ganha. O blueget responde com os campos em MAIUSCULAS.
$CPF_API_CAMPOS = [
    'nome'       => 'NOME|nome|dados.nome',
    'nascimento' => 'NASC|nascimento|data_nascimento',
    'mae'        => 'NOME_MAE|nome_mae|mae',
    'sexo'       => 'SEXO|sexo|genero',
];

// Diagnostico: com true, chamar getCpf.php?cpf=...&debug=1 devolve a resposta
// crua do provedor (sem o token) — serve para descobrir os nomes dos campos
// ao plugar um servico novo. MANTENHA false em producao.
define('CPF_API_DEBUG', false);

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
    'up7'  => ['nome' => 'Etapa 7',         'preco_base' => 0.00, 'descricao' => ''],
    'up8'  => ['nome' => 'Etapa 8',         'preco_base' => 0.00, 'descricao' => ''],
    'up9'  => ['nome' => 'Etapa 9',         'preco_base' => 0.00, 'descricao' => ''],
    'up10' => ['nome' => 'Etapa 10',        'preco_base' => 0.00, 'descricao' => ''],
    'up11' => ['nome' => 'Etapa 11',        'preco_base' => 0.00, 'descricao' => ''],
];
