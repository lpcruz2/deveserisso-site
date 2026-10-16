<?php
// purge_ls.php — purge do cache LiteSpeed, protegido por token.
// Falha FECHADA: sem token valido responde 404 vazio ANTES de carregar o WordPress
// (nenhuma conexao de banco e nenhuma informacao para quem nao tem o token).
// O servidor guarda so o SHA-256 do token, em purge-token.php (fora do repositorio).
header('Cache-Control: no-store');

$arquivo  = __DIR__ . '/purge-token.php';           // <?php return '<sha256 do token>';
$esperado = is_file($arquivo) ? (string) include $arquivo : '';
$enviado  = (string) ($_SERVER['HTTP_X_PURGE_TOKEN'] ?? '');

if (!preg_match('/^[a-f0-9]{64}$/', $esperado)
    || $enviado === ''
    || !hash_equals($esperado, hash('sha256', $enviado))) {
    http_response_code(404);
    exit;
}

$blog = __DIR__ . '/blog';
$_SERVER['HTTP_HOST'] = 'deveserisso.com.br';
$_SERVER['REQUEST_URI'] = '/';
chdir($blog);
require_once($blog . '/wp-load.php');
if (class_exists('LiteSpeed\Purge')) {
    LiteSpeed\Purge::purge_all();
    echo 'LiteSpeed cache purged!';
} else {
    wp_cache_flush();
    echo 'WP cache flushed (LiteSpeed not found)';
}
echo ' Done at ' . date('H:i:s') . PHP_EOL;
