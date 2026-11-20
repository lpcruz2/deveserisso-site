<?php
/**
 * Plugin Name: DSI — 410 para URLs antigas de posts removidos
 * Description: Quando uma URL de um slug de raiz que termina num sufixo legado ("-e-bom-e-vale-a-pena-assistir-...", "-trailer-legendado-elenco-sinopse-e-mais"...) cai em 404 porque o post não existe mais, responde 410 (removido de vez) com a página 404 do tema. Só age quando o WordPress já decidiu 404, então nunca afeta post que existe, e roda antes do palpite de redirecionamento do WordPress (que mandava /a-origem/ para outro filme). Custo zero nas requisições normais.
 * Criado em 2026-11-20 (relatório de saúde: 733 URLs 404 de posts antigos, ~10 mil visitas de robôs em 30 dias).
 */

defined( 'ABSPATH' ) || defined( 'DSI_GONE410_TESTE' ) || exit;

/** Sufixos de slug usados nos posts antigos que saíram do ar. Precisa existir texto antes do sufixo. */
const DSI_GONE410_SUFIXOS = [
	'-e-bom-e-vale-a-pena-assistir-confira-trailer-sinopse-e-mais',
	'-trailer-legendado-elenco-sinopse-e-mais',
	'-e-bom-onde-assistir-trailer-sinopse-e-muito-mais',
	'-e-bom-e-vale-assistir-onde-assistir-trailer-filmes-parecidos-critica-sinopse-e-muito-mais',
	'-e-hoje-vale-a-pena-assistir-trailer-sinopse-e-elenco',
];

/** Caminhos exatos (sem barras nas pontas) que também são 410. */
const DSI_GONE410_EXATOS = [
	'filmes-e-seriados/feed', // feed da categoria antiga; /filmes/feed/ também não existe
];

/**
 * Lógica pura (sem WordPress): este caminho de URL é um slug antigo que deve responder 410?
 *
 * @param string $caminho Caminho da URL sem query, ex.: "/a-origem-trailer-legendado-elenco-sinopse-e-mais/".
 */
function dsi_gone410_corresponde( string $caminho ): bool {
	$c = strtolower( trim( rawurldecode( $caminho ), '/' ) );
	if ( '' === $c ) {
		return false;
	}
	if ( in_array( $c, DSI_GONE410_EXATOS, true ) ) {
		return true;
	}
	// Feeds, AMP e embed do mesmo slug seguem o destino do post.
	$c = preg_replace( '#/(?:feed|amp|embed)(?:/.*)?$#', '', $c );
	if ( false !== strpos( $c, '/' ) ) {
		return false; // só slug de raiz; /blog/x já é tratado pelo redirecionamento de /blog
	}
	foreach ( DSI_GONE410_SUFIXOS as $sufixo ) {
		$n = strlen( $sufixo );
		if ( strlen( $c ) > $n && substr( $c, -$n ) === $sufixo ) {
			return true;
		}
	}
	return false;
}

if ( defined( 'ABSPATH' ) ) {
	// Prioridade 1: antes do redirect_canonical (10), que "adivinha" outro post para slugs 404.
	add_action( 'template_redirect', 'dsi_gone410_responde', 1 );
}

function dsi_gone410_responde(): void {
	if ( ! is_404() ) {
		return;
	}
	$metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
	if ( 'GET' !== $metodo && 'HEAD' !== $metodo ) {
		return;
	}
	$caminho = (string) wp_parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH );
	if ( ! dsi_gone410_corresponde( $caminho ) ) {
		return;
	}

	status_header( 410 );
	header( 'X-Robots-Tag: noindex' );
	$modelo = get_404_template();
	if ( $modelo ) {
		include $modelo;
	} else {
		echo 'Conteúdo removido.';
	}
	exit;
}
