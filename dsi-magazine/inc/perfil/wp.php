<?php
/**
 * Perfil de gosto -- cola com o WordPress.
 *
 * E a UNICA parte do perfil que usa funcoes do WP. Regras, esquema e
 * repositorio (mesma pasta) sao PHP puro, pra sair do WP sem reescrever
 * (premissa 4 do PRD, projeto WebMCP-deveserisso, docs/prd-perfil-de-gosto.md).
 *
 * Aqui: conexao PDO preguicosa, criacao das tabelas por versao e o vinculo
 * critica -> titulo do TMDB (tabela titulo_post) mantido quando uma critica
 * e publicada, editada ou despublicada.
 */

require_once __DIR__ . '/repositorio.php';

/**
 * Staging e producao usam o MESMO banco e o mesmo $table_prefix (conferido
 * em 2026-09-28). Sem prefixo proprio por ambiente, conta de teste no
 * staging apareceria na producao. O ambiente sai da pasta de instalacao
 * (/staging/ ou /blog/): home_url() nao serve, porque o wp_options tambem e
 * compartilhado e devolve o endereco da producao nos dois.
 */
function dsi_perfil_prefixo(): string {
	if ( defined( 'DSI_PERFIL_PREFIXO' ) ) {
		return DSI_PERFIL_PREFIXO;
	}
	return strpos( str_replace( '\\', '/', ABSPATH ), '/staging/' ) !== false ? 'stg_perfil_' : 'perfil_';
}

/** Conecta so quando o perfil e usado (pagina comum nao abre conexao extra). */
function dsi_perfil_repo(): DSI_Perfil_Repositorio {
	static $repo = null;
	if ( $repo === null ) {
		$host  = DB_HOST;
		$extra = '';
		if ( strpos( $host, ':' ) !== false ) {
			[ $host, $resto ] = explode( ':', $host, 2 );
			$extra = ctype_digit( $resto ) ? ';port=' . $resto : ';unix_socket=' . $resto;
		}
		$pdo  = new PDO( 'mysql:host=' . $host . $extra . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASSWORD, [ PDO::ATTR_TIMEOUT => 5 ] );
		$repo = new DSI_Perfil_Repositorio( $pdo, dsi_perfil_prefixo() );
	}
	return $repo;
}

// Cria/atualiza as tabelas uma vez por versao de esquema. A opcao leva o
// prefixo no nome porque staging e producao dividem tambem o wp_options.
add_action( 'init', function (): void {
	$opcao = 'dsi_perfil_esquema_' . dsi_perfil_prefixo();
	if ( get_option( $opcao ) === DSI_PERFIL_ESQUEMA_VERSAO ) {
		return;
	}
	// Depois de uma falha, espera 1 h: sem isso, cada visita tentaria conectar
	// de novo (ate 5 s de espera por pagina se o banco estiver fora).
	if ( get_transient( $opcao . '_falhou' ) ) {
		return;
	}
	try {
		dsi_perfil_repo()->criarEsquema( 'mysql' );
		update_option( $opcao, DSI_PERFIL_ESQUEMA_VERSAO, true );
	} catch ( Throwable $e ) {
		set_transient( $opcao . '_falhou', 1, HOUR_IN_SECONDS );
		error_log( '[dsi-perfil] esquema: ' . $e->getMessage() );
	}
} );

// ------------------------------------------------ critica -> titulo TMDB

/**
 * Procura a critica no TMDB pelo titulo, original, ano e tipo da ficha.
 *
 * @return int[] candidatos (1 = resolvido)
 */
function dsi_perfil_tmdb_buscar_post( int $post_id ): array {
	$chave = defined( 'FILMBOX_TMDB_KEY' ) ? FILMBOX_TMDB_KEY : '';
	$raw   = (string) get_post_meta( $post_id, '_dsi_dados_tecnicos_raw', true );
	if ( $chave === '' || trim( $raw ) === '' || ! function_exists( 'dsi_parse_dados_tecnicos' ) ) {
		return [];
	}
	$d     = dsi_parse_dados_tecnicos( $raw );
	$tit   = trim( (string) ( $d['titulo'] ?? '' ) );
	$orig  = trim( (string) ( $d['titulo_original'] ?? '' ) );
	$ano   = (int) ( $d['ano'] ?? 0 );
	$tipo  = ( $d['tipo'] ?? 'filme' ) === 'serie' ? 'serie' : 'filme';
	$cands = [];
	foreach ( array_unique( array_filter( [ $orig, $tit ] ) ) as $q ) {
		$params = [ 'query' => $q, 'language' => 'pt-BR', 'api_key' => $chave ];
		if ( $ano ) {
			$params[ $tipo === 'serie' ? 'first_air_date_year' : 'primary_release_year' ] = $ano;
		}
		$resp = wp_remote_get( add_query_arg( $params, 'https://api.themoviedb.org/3/search/' . ( $tipo === 'serie' ? 'tv' : 'movie' ) ), [ 'timeout' => 8 ] );
		if ( is_wp_error( $resp ) ) {
			continue;
		}
		$res   = json_decode( wp_remote_retrieve_body( $resp ), true )['results'] ?? [];
		$cands = array_values( array_unique( array_merge( $cands, dsi_perfil_tmdb_candidatos( $res, $tit, $orig, $ano ) ) ) );
		if ( count( $cands ) === 1 ) {
			break;
		}
	}
	return $cands;
}

/**
 * Mantem o vinculo ao salvar. Vinculo feito a mao (fonte "manual") nunca e
 * trocado pela busca. Sem resolucao, o vinculo antigo fica e o editor avisa.
 */
function dsi_perfil_sincronizar_post( int $post_id ): void {
	$post = get_post( $post_id );
	if ( ! $post || $post->post_type !== 'post' ) {
		return;
	}
	try {
		$repo = dsi_perfil_repo();
		if ( $post->post_status !== 'publish' ) {
			$repo->desvincularPost( $post_id );
			delete_post_meta( $post_id, '_dsi_perfil_tmdb_pendente' );
			return;
		}
		if ( trim( (string) get_post_meta( $post_id, '_dsi_dados_tecnicos_raw', true ) ) === '' ) {
			return;
		}
		$atual = $repo->tituloDoPost( $post_id );
		if ( $atual && $atual['fonte'] === 'manual' ) {
			return;
		}
		$d     = dsi_parse_dados_tecnicos( (string) get_post_meta( $post_id, '_dsi_dados_tecnicos_raw', true ) );
		$tipo  = ( $d['tipo'] ?? 'filme' ) === 'serie' ? 'serie' : 'filme';
		$cands = dsi_perfil_tmdb_buscar_post( $post_id );
		if ( count( $cands ) === 1 ) {
			$repo->vincularTituloPost( $post_id, $cands[0], $tipo, 'auto' );
			delete_post_meta( $post_id, '_dsi_perfil_tmdb_pendente' );
		} elseif ( ! $atual ) {
			update_post_meta( $post_id, '_dsi_perfil_tmdb_pendente', count( $cands ) > 1 ? 'ambiguo' : 'nao_achou' );
		}
	} catch ( Throwable $e ) {
		error_log( '[dsi-perfil] vinculo do post ' . $post_id . ': ' . $e->getMessage() );
	}
}

// wp_after_insert_post roda depois de todos os save_post, entao a ficha
// tecnica (salva num save_post do tema) ja esta gravada aqui. save_post_post
// rodaria ANTES e leria a ficha antiga.
add_action( 'wp_after_insert_post', function ( int $post_id, $post ): void {
	if ( ! $post || $post->post_type !== 'post' || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ) {
		return;
	}
	dsi_perfil_sincronizar_post( $post_id );
}, 20, 2 );

add_action( 'before_delete_post', function ( int $post_id ): void {
	try {
		dsi_perfil_repo()->desvincularPost( $post_id );
	} catch ( Throwable $e ) {
		error_log( '[dsi-perfil] desvincular ' . $post_id . ': ' . $e->getMessage() );
	}
} );

// Login (token do WorkOS), resenhas na pagina da critica e fila do gestor.
require_once __DIR__ . '/wp-conta.php';

// Painel de acompanhamento de uso (wp-admin > Resenhas > Uso do perfil).
require_once __DIR__ . '/wp-painel.php';

// Aviso no editor quando a critica nao achou titulo no TMDB.
add_action( 'admin_notices', function (): void {
	$tela = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
	if ( ! $tela || $tela->base !== 'post' || empty( $_GET['post'] ) ) {
		return;
	}
	$pendente = get_post_meta( (int) $_GET['post'], '_dsi_perfil_tmdb_pendente', true );
	if ( ! $pendente ) {
		return;
	}
	$msg = $pendente === 'ambiguo'
		? 'Perfil de gosto: o TMDB tem mais de um título com este nome e ano. Confira o Nome, o Ano e o Tipo na ficha técnica.'
		: 'Perfil de gosto: não achei esta crítica no TMDB. Confira o Nome (e o título original entre parênteses), o Ano e o Tipo na ficha técnica.';
	echo '<div class="notice notice-warning"><p>' . esc_html( $msg ) . ' Sem isso, marcações e resenhas de leitores não se ligam a esta crítica.</p></div>';
} );
