<?php
/**
 * Botão "Sem ideia do que ver?" dentro das listas (post sem ficha técnica com 6+ seções h2),
 * antes da 4ª seção e no fim do texto. Leva ao Curador (LP). Cliques entram no evento
 * menu_curador_clicado (posicao = lista_meio / lista_fim), o mesmo do botão do cabeçalho.
 * 2026-11-07, texto escolhido pelo gestor.
 */
add_filter( 'the_content', function ( string $html ): string {
	if ( ! is_singular( 'post' ) || ! in_the_loop() || ! is_main_query() ) {
		return $html;
	}
	if ( (string) get_post_meta( get_the_ID(), '_dsi_dados_tecnicos_raw', true ) !== '' ) {
		return $html;
	}
	if ( preg_match_all( '/<h2[\s>]/i', $html, $m, PREG_OFFSET_CAPTURE ) < 6 ) {
		return $html;
	}
	$url   = esc_url( home_url( '/filme-serie-bom-assistir-hoje/' ) );
	$botao = static fn( string $pos ): string =>
		'<p class="dsi-cta-lista" style="margin:32px 0;text-align:center"><a href="' . $url . '" data-dsi-cta="' . $pos . '" '
		. 'style="display:inline-block;background:#9c3f14;color:#f4eee2;padding:14px 26px;font-weight:700;border-radius:2px;text-decoration:none">Sem ideia do que ver?</a></p>';
	$corte = $m[0][3][1];
	return substr( $html, 0, $corte ) . $botao( 'lista_meio' ) . substr( $html, $corte ) . $botao( 'lista_fim' );
}, 20 );
