<?php
/**
 * Perfil de gosto -- login (token do WorkOS), resenhas na pagina da critica
 * e fila de aprovacao no painel. Cola com o WordPress, carregada por wp.php.
 *
 * Ordem da fase 1 (decisao de 2026-09-28): MCP primeiro. A pessoa entra
 * pelo Claude/ChatGPT (/mcp/conta); no site ficam so o bloco "Resenha do
 * leitor" e a fila do gestor.
 */

require_once __DIR__ . '/auth.php';

const DSI_PERFIL_TERMO_VERSAO    = '2026-09-28';
const DSI_PERFIL_RESENHA_VERSAO  = '2026-09-28';

/**
 * Configuracao do provedor por ambiente, em wp-content/dsi-perfil-config.php
 * (fora do repositorio; staging e producao tem wp-content proprios):
 * <?php return [ 'workos_api_key' => ..., 'workos_client_id' => ...,
 *               'authkit' => 'https://...authkit.app', 'recurso' => 'https://.../mcp/conta' ];
 */
function dsi_perfil_config(): array {
	static $c = null;
	if ( $c === null ) {
		$arq = WP_CONTENT_DIR . '/dsi-perfil-config.php';
		$c   = is_readable( $arq ) ? (array) include $arq : [];
	}
	return $c;
}

/** JWKS do AuthKit, em cache por 1 h; $renovar busca de novo (chave trocada). */
function dsi_perfil_jwks( bool $renovar = false ): array {
	$cfg   = dsi_perfil_config();
	$chave = 'dsi_perfil_jwks_' . md5( (string) ( $cfg['authkit'] ?? '' ) );
	if ( ! $renovar ) {
		$c = get_transient( $chave );
		if ( is_array( $c ) ) {
			return $c;
		}
	}
	$r = wp_remote_get( rtrim( (string) $cfg['authkit'], '/' ) . '/oauth2/jwks', [ 'timeout' => 5 ] );
	$j = is_wp_error( $r ) ? null : json_decode( wp_remote_retrieve_body( $r ), true );
	if ( is_array( $j ) && ! empty( $j['keys'] ) ) {
		set_transient( $chave, $j, HOUR_IN_SECONDS );
		return $j;
	}
	return [];
}

/**
 * Dados do usuario no WorkOS: e-mail, nome e o "sub" do Google (identidade
 * que o site guarda; o id do WorkOS nao sai daqui, pra poder trocar de
 * provedor). Cache de 1 dia por usuario.
 */
function dsi_perfil_workos_usuario( string $workos_id ): ?array {
	$cfg   = dsi_perfil_config();
	$chave = 'dsi_perfil_wu_' . md5( (string) ( $cfg['authkit'] ?? '' ) . $workos_id );
	$c     = get_transient( $chave );
	if ( is_array( $c ) ) {
		return $c;
	}
	$base = 'https://api.workos.com/user_management/users/' . rawurlencode( $workos_id );
	$args = [ 'timeout' => 6, 'headers' => [ 'Authorization' => 'Bearer ' . ( $cfg['workos_api_key'] ?? '' ) ] ];
	$ru   = wp_remote_get( $base, $args );
	$ri   = wp_remote_get( $base . '/identities', $args );
	if ( is_wp_error( $ru ) || is_wp_error( $ri ) || wp_remote_retrieve_response_code( $ru ) !== 200 ) {
		return null;
	}
	$u   = json_decode( wp_remote_retrieve_body( $ru ), true );
	$ids = json_decode( wp_remote_retrieve_body( $ri ), true );
	$ids = isset( $ids['data'] ) ? $ids['data'] : $ids;
	$google = null;
	foreach ( (array) $ids as $i ) {
		if ( is_array( $i ) && ( $i['provider'] ?? '' ) === 'GoogleOAuth' && ! empty( $i['idp_id'] ) ) {
			$google = (string) $i['idp_id'];
		}
	}
	$dados = [
		'workos_id'  => $workos_id,
		'google_sub' => $google,
		'email'      => (string) ( $u['email'] ?? '' ),
		'nome'       => trim( ( $u['first_name'] ?? '' ) . ' ' . ( $u['last_name'] ?? '' ) ),
	];
	if ( $google && $dados['email'] ) {
		set_transient( $chave, $dados, DAY_IN_SECONDS );
	}
	return $dados;
}

/**
 * Autentica a requisicao do /mcp/conta pelo token Bearer.
 *
 * @return array{ok:bool,erro?:string,detalhe?:string,usuario?:array,conta?:?array}
 */
function dsi_perfil_autenticar_requisicao(): array {
	$cfg = dsi_perfil_config();
	if ( empty( $cfg['authkit'] ) || empty( $cfg['recurso'] ) || empty( $cfg['workos_api_key'] ) ) {
		return [ 'ok' => false, 'erro' => 'sem_config' ];
	}
	$jwt = dsi_perfil_bearer( $_SERVER );
	if ( ! $jwt ) {
		return [ 'ok' => false, 'erro' => 'sem_token' ];
	}
	$jwks = dsi_perfil_jwks();
	try {
		$kid = dsi_perfil_jwt_kid( $jwt );
		if ( $kid !== null && ! in_array( $kid, array_column( $jwks['keys'] ?? [], 'kid' ), true ) ) {
			$jwks = dsi_perfil_jwks( true );
		}
		$claims = dsi_perfil_jwt_verificar( $jwt, $jwks, $cfg['authkit'], $cfg['recurso'], time() );
	} catch ( DSI_Perfil_Token_Invalido $e ) {
		return [ 'ok' => false, 'erro' => 'token_invalido', 'detalhe' => $e->getMessage() ];
	}
	$u = dsi_perfil_workos_usuario( (string) $claims['sub'] );
	if ( ! $u ) {
		return [ 'ok' => false, 'erro' => 'provedor_indisponivel' ];
	}
	if ( empty( $u['google_sub'] ) ) {
		return [ 'ok' => false, 'erro' => 'sem_google' ];
	}
	$repo  = dsi_perfil_repo();
	$conta = $repo->contaPorSub( $u['google_sub'] );
	// Ultimo acesso (base da retencao de 24 meses), no maximo 1 vez por hora.
	if ( $conta && strtotime( $conta['ultimo_acesso_em'] . ' UTC' ) < time() - HOUR_IN_SECONDS ) {
		$repo->registrarAcesso( (int) $conta['id'], $u['email'], $u['nome'] );
	}
	return [ 'ok' => true, 'usuario' => $u, 'conta' => $conta ];
}

/** Remove o usuario no provedor quando a pessoa apaga a conta (R6). */
function dsi_perfil_workos_apagar_usuario( string $workos_id ): bool {
	$cfg = dsi_perfil_config();
	$r   = wp_remote_request( 'https://api.workos.com/user_management/users/' . rawurlencode( $workos_id ), [
		'method'  => 'DELETE',
		'timeout' => 6,
		'headers' => [ 'Authorization' => 'Bearer ' . ( $cfg['workos_api_key'] ?? '' ) ],
	] );
	delete_transient( 'dsi_perfil_wu_' . md5( (string) ( $cfg['authkit'] ?? '' ) . $workos_id ) );
	return ! is_wp_error( $r ) && wp_remote_retrieve_response_code( $r ) < 300;
}

// ------------------------------------------------ dados dos titulos

/**
 * Titulo, ano, poster e link de cada tmdb_id+tipo: da critica publicada,
 * ou do cache de externos quando o site nao tem critica.
 *
 * @param array<array{tmdb_id:int,tipo:string}> $pares
 * @return array<string,array> chave "tipo:tmdb_id"
 */
function dsi_perfil_info_titulos( array $pares ): array {
	global $wpdb;
	$repo  = dsi_perfil_repo();
	$saida = [];
	foreach ( $pares as $p ) {
		$tmdb  = (int) ( $p['tmdb_id'] ?? 0 );
		$tipo  = ( $p['tipo'] ?? '' ) === 'serie' ? 'serie' : 'filme';
		$chave = $tipo . ':' . $tmdb;
		if ( ! $tmdb || isset( $saida[ $chave ] ) ) {
			continue;
		}
		$posts = $repo->postsDoTitulo( $tmdb, $tipo );
		if ( $posts ) {
			$pid = $posts[0];
			$d   = function_exists( 'dsi_parse_dados_tecnicos' ) ? dsi_parse_dados_tecnicos( (string) get_post_meta( $pid, '_dsi_dados_tecnicos_raw', true ) ) : [];
			$saida[ $chave ] = [
				'tmdb_id'     => $tmdb,
				'tipo'        => $tipo,
				'titulo'      => (string) ( $d['titulo'] ?? get_the_title( $pid ) ),
				'ano'         => (string) ( $d['ano'] ?? '' ),
				'poster'      => (string) ( get_the_post_thumbnail_url( $pid, 'medium' ) ?: '' ),
				'link'        => (string) get_permalink( $pid ),
				'tem_critica' => true,
			];
			continue;
		}
		$l = $wpdb->get_row( $wpdb->prepare(
			'SELECT titulo, ano_lancamento, poster_url FROM ' . dsi_filme_externo_table_name() . ' WHERE tmdb_id = %d AND tipo = %s LIMIT 1',
			$tmdb, $tipo
		), ARRAY_A );
		$saida[ $chave ] = [
			'tmdb_id'     => $tmdb,
			'tipo'        => $tipo,
			'titulo'      => (string) ( $l['titulo'] ?? ( 'Título ' . $tmdb ) ),
			'ano'         => (string) ( $l['ano_lancamento'] ?? '' ),
			'poster'      => (string) ( $l['poster_url'] ?? '' ),
			'link'        => '',
			'tem_critica' => false,
		];
	}
	return $saida;
}

/** @return array<int,array{tmdb_id:?int,tipo:string}> id do cache de externos -> titulo */
function dsi_perfil_tmdb_de_externos( array $ids ): array {
	global $wpdb;
	$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
	if ( ! $ids ) {
		return [];
	}
	$marcas = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
	$saida  = [];
	foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT id, tmdb_id, tipo FROM ' . dsi_filme_externo_table_name() . " WHERE id IN ({$marcas})", ...$ids ), ARRAY_A ) as $l ) {
		$saida[ (int) $l['id'] ] = [ 'tmdb_id' => $l['tmdb_id'] ? (int) $l['tmdb_id'] : null, 'tipo' => $l['tipo'] === 'serie' ? 'serie' : 'filme' ];
	}
	return $saida;
}

// ------------------------------------------------ resenhas no site

/** Cache curto das resenhas no ar de uma critica; limpo na aprovacao. */
function dsi_perfil_resenhas_do_post( int $post_id ): array {
	$chave = 'dsi_perfil_res_' . dsi_perfil_prefixo() . $post_id;
	$c     = get_transient( $chave );
	if ( is_array( $c ) ) {
		return $c;
	}
	$repo = dsi_perfil_repo();
	$t    = $repo->tituloDoPost( $post_id );
	$lista = $t ? $repo->resenhasNoAr( $t['tmdb_id'], $t['tipo'] ) : [];
	set_transient( $chave, $lista, 10 * MINUTE_IN_SECONDS );
	return $lista;
}

function dsi_perfil_limpar_cache_resenhas( int $tmdb_id, string $tipo ): void {
	foreach ( dsi_perfil_repo()->postsDoTitulo( $tmdb_id, $tipo ) as $pid ) {
		delete_transient( 'dsi_perfil_res_' . dsi_perfil_prefixo() . $pid );
	}
}

add_action( 'rest_api_init', function (): void {
	register_rest_route( 'dsi/v1', '/resenhas', [
		'methods'             => 'GET',
		'permission_callback' => '__return_true',
		'args'                => [ 'post' => [ 'required' => true, 'sanitize_callback' => 'absint' ] ],
		'callback'            => function ( WP_REST_Request $req ) {
			try {
				$lista = dsi_perfil_resenhas_do_post( (int) $req['post'] );
			} catch ( Throwable $e ) {
				error_log( '[dsi-perfil] resenhas: ' . $e->getMessage() );
				$lista = [];
			}
			$saida = array_map( fn( $r ) => [
				'texto'   => $r['texto'],
				'handle'  => $r['handle'],
				'rede'    => $r['rede'],
				'link'    => $r['link_social'],
				'spoiler' => (bool) $r['spoiler'],
				'data'    => substr( (string) $r['aprovada_em'], 0, 10 ),
			], $lista );
			$resp = new WP_REST_Response( [ 'resenhas' => $saida ] );
			$resp->header( 'Cache-Control', 'no-store' );
			$resp->header( 'X-LiteSpeed-Cache-Control', 'no-cache' );
			return $resp;
		},
	] );
} );

// Bloco "Resenha do leitor" logo abaixo da critica. Vem por JavaScript, de
// proposito: a pagina segue igual pra todo mundo no cache (3 camadas), a
// resenha aprovada aparece sem purgar nada e fica fora do indice do Google
// (decisao pendente no PRD; o padrao do plano e nao indexar).
add_filter( 'the_content', function ( $conteudo ) {
	// Liga por ambiente ('bloco_resenhas' => true no dsi-perfil-config.php):
	// sem isso, cada visita a uma critica faria uma consulta a mais a toa.
	if ( empty( dsi_perfil_config()['bloco_resenhas'] ) || ! is_singular( 'post' ) || ! in_the_loop() || ! is_main_query() ) {
		return $conteudo;
	}
	$pid = (int) get_the_ID();
	if ( trim( (string) get_post_meta( $pid, '_dsi_dados_tecnicos_raw', true ) ) === '' ) {
		return $conteudo;
	}
	// Relativo de proposito: rest_url() le o endereco do wp_options, que e o da
	// producao tambem no staging (banco compartilhado).
	$api = '/wp-json/dsi/v1/resenhas';
	return $conteudo . '<section class="dsi-leitores" data-post="' . $pid . '" data-api="' . $api . '" hidden aria-labelledby="dsi-leitores-titulo"></section>' . dsi_perfil_bloco_script();
}, 30 );

function dsi_perfil_bloco_script(): string {
	static $feito = false;
	if ( $feito ) {
		return '';
	}
	$feito = true;
	ob_start();
	?>
<style>
.dsi-leitores{margin:2.5rem 0 1rem;padding-top:1.25rem;border-top:1px solid currentColor}
.dsi-leitores h2{font-size:1.25rem;margin:0 0 .25rem}
.dsi-leitores .dsi-leitores-sub{opacity:.7;font-size:.9rem;margin:0 0 1rem}
.dsi-leitores article{padding:1rem 0;border-bottom:1px solid rgba(127,127,127,.35)}
.dsi-leitores article p{margin:.5rem 0 0;white-space:pre-line}
.dsi-leitores .dsi-leitor-autor{font-weight:600}
.dsi-leitores .dsi-leitor-data{opacity:.65;font-size:.85rem;margin-left:.5rem}
.dsi-leitores button{font:inherit;cursor:pointer;margin-top:.5rem;padding:.35rem .7rem;border:1px solid currentColor;background:transparent;color:inherit}
</style>
<script>
(function () {
	var caixa = document.querySelector('.dsi-leitores');
	if (!caixa || !window.fetch) return;
	var url = caixa.getAttribute('data-api') + '?post=' + encodeURIComponent(caixa.getAttribute('data-post'));
	fetch(url, { credentials: 'omit' }).then(function (r) { return r.ok ? r.json() : null; }).then(function (d) {
		var lista = d && d.resenhas;
		if (!lista || !lista.length) return;
		function el(tag, cls, txt) { var n = document.createElement(tag); if (cls) n.className = cls; if (txt != null) n.textContent = txt; return n; }
		function seguro(u) { return /^https:\/\/([a-z0-9-]+\.)*(instagram\.com|x\.com|threads\.net|threads\.com|bsky\.app|tiktok\.com|youtube\.com|letterboxd\.com|facebook\.com)\//i.test(u || '') ? u : ''; }
		var h = el('h2', null, lista.length > 1 ? 'Resenhas dos leitores' : 'Resenha do leitor');
		h.id = 'dsi-leitores-titulo';
		caixa.appendChild(h);
		caixa.appendChild(el('p', 'dsi-leitores-sub', 'Opinião de quem assistiu, lida e aprovada pela equipe. Não é a crítica do deveserisso.'));
		lista.forEach(function (r) {
			var a = el('article');
			var topo = el('div');
			var link = seguro(r.link);
			var autor = el(link ? 'a' : 'span', 'dsi-leitor-autor', r.handle);
			if (link) { autor.href = link; autor.rel = 'ugc nofollow noopener'; autor.target = '_blank'; }
			topo.appendChild(autor);
			if (r.data) topo.appendChild(el('span', 'dsi-leitor-data', r.data.split('-').reverse().join('/')));
			a.appendChild(topo);
			var texto = el('p', null, r.texto);
			if (r.spoiler) {
				texto.hidden = true;
				var b = el('button', null, 'Contém spoiler: mostrar');
				b.type = 'button';
				b.addEventListener('click', function () { texto.hidden = false; b.remove(); });
				a.appendChild(b);
			}
			a.appendChild(texto);
			caixa.appendChild(a);
		});
		caixa.hidden = false;
	}).catch(function () {});
})();
</script>
	<?php
	return (string) ob_get_clean();
}

// ------------------------------------------------ fila do gestor

add_action( 'admin_menu', function (): void {
	$pendentes = 0;
	try {
		$pendentes = count( dsi_perfil_repo()->filaAprovacao( 200 ) );
	} catch ( Throwable $e ) {
		error_log( '[dsi-perfil] menu: ' . $e->getMessage() );
	}
	$bolha = $pendentes ? ' <span class="awaiting-mod">' . (int) $pendentes . '</span>' : '';
	add_menu_page( 'Resenhas de leitores', 'Resenhas' . $bolha, 'manage_options', 'dsi-resenhas', 'dsi_perfil_admin_pagina', 'dashicons-format-quote', 26 );
} );

function dsi_perfil_admin_pagina(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$repo  = dsi_perfil_repo();
	$fila  = $repo->filaAprovacao( 100 );
	$pauta = $repo->filaPauta();
	$info  = dsi_perfil_info_titulos( array_merge( $fila, $pauta ) );
	$motivos = [ 'spoiler' => 'Spoiler sem aviso', 'ofensa' => 'Ofensa', 'fora_do_tema' => 'Fora do tema', 'spam' => 'Spam' ];
	echo '<div class="wrap"><h1>Resenhas de leitores</h1>';
	echo '<p>Ambiente: <code>' . esc_html( dsi_perfil_prefixo() ) . '</code>. Nada vai ao ar sem aprovação. Resenha de título sem crítica espera e aparece sozinha quando a crítica sair.</p>';
	if ( isset( $_GET['feito'] ) ) {
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $_GET['feito'] === 'aprovada' ? 'Resenha aprovada.' : 'Resenha recusada.' ) . '</p></div>';
	}
	echo '<h2>Esperando aprovação (' . count( $fila ) . ')</h2>';
	if ( ! $fila ) {
		echo '<p>Nenhuma resenha esperando.</p>';
	}
	foreach ( $fila as $v ) {
		$t = $info[ $v['tipo'] . ':' . $v['tmdb_id'] ] ?? null;
		echo '<div class="card" style="max-width:860px;padding:12px 16px;margin:12px 0">';
		echo '<h3 style="margin:.2em 0">' . esc_html( $t['titulo'] ?? ( 'TMDB ' . $v['tmdb_id'] ) ) . ' <small>(' . esc_html( $v['tipo'] . ( ! empty( $t['ano'] ) ? ', ' . $t['ano'] : '' ) ) . ')</small></h3>';
		echo '<p>' . ( $v['tem_critica'] ? ( ! empty( $t['link'] ) ? '<a href="' . esc_url( $t['link'] ) . '" target="_blank">Ver a crítica</a>' : 'Tem crítica' ) : '<strong>Sem crítica no site:</strong> fica esperando depois de aprovada.' ) . '</p>';
		echo '<p>Por <a href="' . esc_url( $v['link_social'] ) . '" target="_blank" rel="noopener">' . esc_html( $v['handle'] ) . '</a> (' . esc_html( $v['rede'] ) . ') · enviada em ' . esc_html( $v['enviada_em'] ) . ' UTC · pelo ' . esc_html( $v['canal'] ) . ( $v['spoiler'] ? ' · <strong>marcada como spoiler</strong>' : '' ) . '</p>';
		if ( $v['sinais'] ) {
			echo '<p style="color:#b32d2e">Filtro automático: ' . esc_html( implode( ', ', $v['sinais'] ) ) . '</p>';
		}
		if ( $v['texto_no_ar'] ) {
			echo '<details><summary>É uma edição. Versão que está no ar</summary><div style="white-space:pre-line;background:#f6f7f7;padding:8px">' . esc_html( $v['texto_no_ar'] ) . '</div></details>';
		}
		echo '<div style="white-space:pre-line;border-left:3px solid #2271b1;padding:6px 10px;margin:8px 0">' . esc_html( $v['texto'] ) . '</div>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">';
		wp_nonce_field( 'dsi_resenha_' . (int) $v['versao_id'] );
		echo '<input type="hidden" name="action" value="dsi_resenha_decidir"><input type="hidden" name="versao" value="' . (int) $v['versao_id'] . '">';
		echo '<button class="button button-primary" name="decisao" value="aprovar">Aprovar</button>';
		echo '<select name="motivo">';
		foreach ( $motivos as $k => $rot ) {
			echo '<option value="' . esc_attr( $k ) . '">' . esc_html( $rot ) . '</option>';
		}
		echo '</select><button class="button" name="decisao" value="recusar">Recusar</button></form></div>';
	}
	echo '<h2>Pauta: títulos sem crítica com resenhas de leitores</h2>';
	if ( ! $pauta ) {
		echo '<p>Nenhum.</p>';
	} else {
		echo '<table class="widefat striped" style="max-width:860px"><thead><tr><th>Título</th><th>Tipo</th><th>Resenhas</th><th>TMDB</th></tr></thead><tbody>';
		foreach ( $pauta as $p ) {
			$t = $info[ $p['tipo'] . ':' . $p['tmdb_id'] ] ?? [];
			$u = 'https://www.themoviedb.org/' . ( $p['tipo'] === 'serie' ? 'tv' : 'movie' ) . '/' . $p['tmdb_id'];
			echo '<tr><td>' . esc_html( $t['titulo'] ?? '' ) . ( ! empty( $t['ano'] ) ? ' (' . esc_html( $t['ano'] ) . ')' : '' ) . '</td><td>' . esc_html( $p['tipo'] ) . '</td><td>' . (int) $p['resenhas'] . '</td><td><a href="' . esc_url( $u ) . '" target="_blank" rel="noopener">' . (int) $p['tmdb_id'] . '</a></td></tr>';
		}
		echo '</tbody></table>';
	}
	echo '</div>';
}

add_action( 'admin_post_dsi_resenha_decidir', function (): void {
	$versao = (int) ( $_POST['versao'] ?? 0 );
	if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'dsi_resenha_' . $versao ) ) {
		wp_die( 'Sem permissão.' );
	}
	$repo    = dsi_perfil_repo();
	$decisao = ( $_POST['decisao'] ?? '' ) === 'aprovar' ? 'aprovada' : 'recusada';
	$alvo    = null;
	foreach ( $repo->filaAprovacao( 200 ) as $v ) {
		if ( (int) $v['versao_id'] === $versao ) {
			$alvo = $v;
		}
	}
	if ( $decisao === 'aprovada' ) {
		$repo->aprovarVersao( $versao );
	} else {
		$motivo = sanitize_key( $_POST['motivo'] ?? 'spam' );
		$repo->recusarVersao( $versao, in_array( $motivo, DSI_PERFIL_MOTIVOS, true ) ? $motivo : 'spam' );
	}
	if ( $alvo ) {
		dsi_perfil_limpar_cache_resenhas( (int) $alvo['tmdb_id'], $alvo['tipo'] );
	}
	wp_safe_redirect( admin_url( 'admin.php?page=dsi-resenhas&feito=' . $decisao ) );
	exit;
} );
