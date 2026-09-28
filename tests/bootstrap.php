<?php
/**
 * Bootstrap dos testes de logica pura do bilheteiro (2026-09-22). NAO
 * carrega um WordPress inteiro de proposito -- so fornece a UNICA funcao
 * do WP de que dsi-magazine/inc/bilheteiro-logica.php depende, com
 * comportamento real (nao um mock vazio), pra testar a logica isolada
 * rapido, sem banco de dados nem servidor.
 */

define( 'DSI_BILHETEIRO_LOGICA_TESTE', true );

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $string, $remove_breaks = false ) {
		$string = preg_replace( '@<(script|style)[^>]*?>.*?</\\1>@si', '', (string) $string );
		$string = strip_tags( $string );
		if ( $remove_breaks ) {
			$string = preg_replace( '/[\r\n\t ]+/', ' ', $string );
		}
		return trim( $string );
	}
}

require_once __DIR__ . '/../dsi-magazine/inc/bilheteiro-logica.php';

// Perfil de gosto (2026-09-28): PHP puro por premissa (site pode sair do WP),
// nao precisa de nenhuma funcao do WordPress.
require_once __DIR__ . '/../dsi-magazine/inc/perfil/repositorio.php';
