<?php
/**
 * Entrega arquivos de /.well-known/ e registra o acesso no painel
 * Ferramentas -> Bots (tipo 'wk').
 *
 * Por que existe: arquivos estaticos sao entregues direto pelo servidor e
 * nunca passam pelo WordPress, entao o painel nao enxergava quem lia o
 * poppy.json. O .htaccess manda so os caminhos da lista abaixo para ca.
 *
 * Ordem importa: primeiro o arquivo vai inteiro para o cliente e a conexao
 * e encerrada; so depois o WordPress minimo (SHORTINIT: so banco, sem
 * tema nem plugins) e carregado para gravar a linha. Assim o bot nao espera
 * o log, e falha de banco/WordPress nao derruba a entrega do arquivo.
 */

// Lista fechada: caminho pedido (relativo a /.well-known/) -> Content-Type.
// Mesmos valores que o .htaccess define para cada arquivo estatico.
$dsi_wk_arquivos = [
	'poppy.json' => 'application/json',
];

$dsi_wk_nome = isset( $_GET['f'] ) ? (string) $_GET['f'] : '';
if ( ! isset( $dsi_wk_arquivos[ $dsi_wk_nome ] ) ) {
	http_response_code( 404 );
	exit;
}

$dsi_wk_caminho = __DIR__ . '/.well-known/' . $dsi_wk_nome;
$dsi_wk_corpo   = is_file( $dsi_wk_caminho ) ? file_get_contents( $dsi_wk_caminho ) : false;
if ( false === $dsi_wk_corpo ) {
	http_response_code( 404 );
	exit;
}

$dsi_wk_metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ( ! in_array( $dsi_wk_metodo, [ 'GET', 'HEAD' ], true ) ) {
	header( 'Allow: GET, HEAD' );
	http_response_code( 405 );
	exit;
}

header( 'Content-Type: ' . $dsi_wk_arquivos[ $dsi_wk_nome ] );
header( 'Content-Length: ' . strlen( $dsi_wk_corpo ) );
header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', filemtime( $dsi_wk_caminho ) ) . ' GMT' );
header( 'Connection: close' );
if ( 'HEAD' !== $dsi_wk_metodo ) {
	echo $dsi_wk_corpo;
}

// Encerra a resposta para o cliente e segue rodando para gravar o log.
ignore_user_abort( true );
if ( function_exists( 'litespeed_finish_request' ) ) {
	litespeed_finish_request();
} elseif ( function_exists( 'fastcgi_finish_request' ) ) {
	fastcgi_finish_request();
} else {
	@ob_end_flush();
	flush();
}

try {
	define( 'SHORTINIT', true );
	// O WordPress de producao fica em /blog/ (a raiz so tem index.php e .htaccess).
	require __DIR__ . '/blog/wp-load.php';

	$dsi_wk_mu = ( defined( 'WPMU_PLUGIN_DIR' ) ? WPMU_PLUGIN_DIR : ABSPATH . 'wp-content/mu-plugins' ) . '/dsi-ai-markdown.php';
	if ( is_file( $dsi_wk_mu ) ) {
		require_once $dsi_wk_mu;
	}
	if ( function_exists( 'dsi_agentmd_log_request' ) ) {
		dsi_agentmd_log_request( 0, (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' ), 'wk', null, 200 );
	}
} catch ( \Throwable $e ) {
	// Log e secundario: a resposta ja foi entregue.
}
