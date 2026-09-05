<?php
/**
 * Plugin Name: DSI — Markdown para agentes de IA
 * Description: Serve uma versão em Markdown de posts/páginas via sufixo .md na URL, e registra quem pediu.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'template_redirect', 'dsi_agentmd_maybe_serve' );
add_action( 'wp_head', 'dsi_agentmd_alternate_link' );

// Autodescoberta: declara a versao Markdown de qualquer post/pagina na
// propria tag <head>, pra agentes que chegam direto na URL HTML (nao so
// via llms.txt) saberem que a alternativa .md existe. A home entra aqui
// tambem (achado do audit "Ora": agente que cai frio na home, sem ter
// lido o llms.txt antes, nao tinha como saber que /index.md existe).
function dsi_agentmd_alternate_link(): void {
	if ( is_front_page() ) {
		printf(
			'<link rel="alternate" type="text/markdown" href="%s">' . "\n",
			esc_url( home_url( '/index.md' ) )
		);
		return;
	}

	if ( ! is_singular( [ 'post', 'page' ] ) ) {
		return;
	}

	$url = rtrim( get_permalink(), '/' ) . '.md';
	printf( '<link rel="alternate" type="text/markdown" href="%s">' . "\n", esc_url( $url ) );
}

function dsi_agentmd_maybe_serve(): void {
	if ( is_front_page() && isset( $_GET['mode'] ) && $_GET['mode'] === 'agent' ) {
		dsi_agentmd_send_agent_mode_view();
		return;
	}

	if ( isset( $_GET['dsi_markdown'] ) ) {
		dsi_agentmd_serve_via_md_suffix();
		return;
	}

	/**
	 * EXPERIMENTO monitorado, reintroduzido em 2026-09-05 -- decisao
	 * consciente do gestor de assumir o risco ja documentado (hcdn pode
	 * cachear a variante errada por URL, ver commit 005379a e CLAUDE.md).
	 * Critério de rollback: qualquer erro real detectado pelo monitor
	 * (scripts/monitor-accept-negotiation.py) desliga isto de novo, nao
	 * espera prazo nenhum. NAO remover este bloco sem reler o commit que
	 * o removeu da primeira vez e o resultado do monitor.
	 */
	if ( is_singular( [ 'post', 'page' ] ) ) {
		// Vary: Accept -- declarado corretamente aqui a nivel de aplicacao,
		// ainda que a infra (LiteSpeed/hcdn) tire esse header da resposta
		// final antes de chegar no cliente (confirmado, ver CLAUDE.md).
		header( 'Vary: Accept' );

		$negociado = dsi_agentmd_negotiate_accept( $_SERVER['HTTP_ACCEPT'] ?? '' );

		if ( $negociado === 'unsatisfiable' ) {
			dsi_agentmd_send_406();
			return;
		}

		if ( $negociado === 'markdown' ) {
			$post = get_queried_object();
			if ( $post instanceof WP_Post ) {
				$user_agent = sanitize_text_field( $_SERVER['HTTP_USER_AGENT'] ?? '' );
				dsi_agentmd_send_markdown( $post, $user_agent, true );
			}
			return;
		}
	}

	dsi_agentmd_maybe_log_html();
}

/**
 * Sufixo .md na URL -- mecanismo principal (URL propria = cache-safe por
 * natureza, sem precisar de nenhum bypass).
 *
 * O LiteSpeed nao atualiza $_SERVER['REQUEST_URI'] entre os blocos de
 * rewrite do .htaccess (cada <IfModule> e reescrito internamente, mas o
 * valor que o PHP ve continua sendo a URI original com ".md"). Por isso
 * nao da pra confiar em is_singular()/get_queried_object() aqui -- o
 * parser de permalinks do WP tentaria casar ".md" como parte do slug.
 * Resolve o post direto pelo path, ignorando o roteamento do WP.
 */
function dsi_agentmd_serve_via_md_suffix(): void {
	$path = (string) parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH );
	$path = preg_replace( '#\.md$#', '', $path );
	$path = trim( $path, '/' );

	if ( $path === '' ) {
		return;
	}

	// /index.md -- a home nunca teve versao .md (o mecanismo sempre cobriu
	// só post/pagina individual). "index" nao e slug de post nenhum, entao
	// sem esse caso especial isso caia silenciosamente e servia a home em
	// HTML normal pra quem pedia .md.
	if ( $path === 'index' ) {
		dsi_agentmd_send_homepage_markdown();
		return;
	}

	$post = get_page_by_path( $path, OBJECT, [ 'post', 'page' ] );
	if ( ! $post instanceof WP_Post || $post->post_status !== 'publish' ) {
		return;
	}

	global $wp_query;
	$wp_query->queried_object    = $post;
	$wp_query->queried_object_id = $post->ID;
	$GLOBALS['post']             = $post;
	setup_postdata( $post );

	$user_agent = sanitize_text_field( $_SERVER['HTTP_USER_AGENT'] ?? '' );
	dsi_agentmd_send_markdown( $post, $user_agent, false );
}

/**
 * /index.md -- versao Markdown da home. Reaproveita o llms.txt como fonte
 * unica de verdade (mesmo arquivo, sem duplicar conteudo em dois lugares
 * que podem sair de sincronia) em vez de montar um resumo proprio da home.
 */
function dsi_agentmd_send_homepage_markdown(): void {
	$llms_path = realpath( ABSPATH . '../llms.txt' );
	if ( ! $llms_path || ! is_readable( $llms_path ) ) {
		return; // arquivo nao encontrado -- deixa a home servir HTML normal
	}

	$body = file_get_contents( $llms_path );
	if ( $body === false ) {
		return;
	}

	// Frontmatter separado do llms.txt em si -- o llms.txt ja abre com "#
	// Deveserisso" (titulo em Markdown), nao com um bloco --- YAML. Agentes
	// que leem metadado de frontmatter (title/description/canonical/
	// last-updated) sem raspar o corpo precisam dele aqui.
	$frontmatter = sprintf(
		"---\ntitle: %s\ndescription: %s\ncanonical: %s\nlast-updated: %s\n---\n\n",
		dsi_agentmd_yaml_escape( get_bloginfo( 'name' ) ),
		dsi_agentmd_yaml_escape( get_bloginfo( 'description' ) ),
		esc_url( home_url( '/' ) ),
		gmdate( 'Y-m-d', filemtime( $llms_path ) )
	);
	$body = $frontmatter . $body;

	$user_agent = sanitize_text_field( $_SERVER['HTTP_USER_AGENT'] ?? '' );
	dsi_agentmd_log_request( 0, $user_agent, 'md' );

	status_header( 200 );
	header( 'Content-Type: text/markdown; charset=utf-8' );
	header( 'X-Robots-Tag: noindex' );
	echo $body;
	exit;
}

/**
 * ?mode=agent na home -- view estruturada (JSON) em vez do HTML de
 * marketing, pra agente que quer entender rapido do que o site trata e
 * quais APIs/mecanismos existem, sem parsear a pagina inteira. Query
 * string e cache-safe por natureza (URL propria), mesmo raciocinio do
 * sufixo .md -- sem risco de servir a variante errada pra visitante errado.
 */
function dsi_agentmd_send_agent_mode_view(): void {
	$data = [
		'name'        => get_bloginfo( 'name' ),
		'description' => get_bloginfo( 'description' ),
		'url'         => home_url( '/' ),
		'language'    => 'pt-BR',
		'authentication' => 'nenhuma -- API pública, somente leitura, sem custo',
		'api'         => [
			'webmcp'  => rest_url( 'webmcp/v1/' ),
			'openapi' => home_url( '/openapi.json' ),
			'wp_rest' => rest_url(),
			'catalog' => home_url( '/.well-known/api-catalog' ),
		],
		'markdown'    => [
			'homepage'  => home_url( '/index.md' ),
			'any_page'  => 'adicione .md ao final de qualquer URL de post/página do site',
			'llms_txt'  => home_url( '/llms.txt' ),
		],
		'documentation' => home_url( '/desenvolvedores/' ),
	];

	status_header( 200 );
	header( 'Content-Type: application/json; charset=utf-8' );
	header( 'X-Robots-Tag: noindex' );
	echo wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
	exit;
}

/**
 * Monta o Markdown (frontmatter + corpo) e envia. So chamada pelo sufixo
 * .md -- URL propria, cache-safe por natureza, sem precisar de bypass.
 *
 * Negociacao por Accept header (mesma URL variando por Content-Type) foi
 * tentada e removida em 2026-09-04: o hcdn (CDN interna da Hostinger,
 * entre a origem e o Cloudflare) cacheia por URL sem considerar o Accept,
 * e nao ha como fazer so ele pular o cache sem o LiteSpeed tambem parar
 * de cachear (os dois respeitam o mesmo sinal de Cache-Control) -- ver
 * CLAUDE.md, secao "Servidor e CDN", pra detalhes e evidencia.
 */
function dsi_agentmd_send_markdown( WP_Post $post, string $user_agent, bool $bypass_cache ): void {
	$html = apply_filters( 'the_content', $post->post_content );
	$body = dsi_agentmd_html_to_markdown( $html );

	$description = get_post_meta( $post->ID, '_yoast_wpseo_metadesc', true );
	if ( ! $description ) {
		$description = wp_strip_all_tags( get_the_excerpt( $post ) );
	}

	$frontmatter = sprintf(
		"---\ntitle: %s\nurl: %s\ndate: %s\ndescription: %s\n---\n\n",
		dsi_agentmd_yaml_escape( get_the_title( $post ) ),
		esc_url( get_permalink( $post ) ),
		get_the_date( 'Y-m-d', $post ),
		dsi_agentmd_yaml_escape( $description )
	);

	dsi_agentmd_log_request( $post->ID, $user_agent, 'md' );

	status_header( 200 );
	header( 'Content-Type: text/markdown; charset=utf-8' );
	header( 'X-Robots-Tag: noindex' );

	if ( $bypass_cache ) {
		header( 'X-LiteSpeed-Cache-Control: no-cache' );
		header( 'Cache-Control: private, no-store' );
	}

	echo $frontmatter . $body;
	exit;
}

/**
 * Decide o que a negociacao de conteudo deveria retornar pra esse Accept,
 * honrando q-values (RFC 9110) entre os dois tipos que esse recurso
 * realmente oferece -- text/html (padrao) e text/markdown.
 *
 * @return string 'markdown' | 'html' | 'unsatisfiable'
 */
function dsi_agentmd_negotiate_accept( string $accept ): string {
	$accept = strtolower( trim( $accept ) );

	if ( $accept === '' ) {
		return 'html';
	}

	$best_type = '';
	$best_q    = -1.0;
	$algum_ok  = false;

	foreach ( explode( ',', $accept ) as $part ) {
		$bits = explode( ';', trim( $part ) );
		$type = trim( $bits[0] );
		$q    = 1.0;

		foreach ( array_slice( $bits, 1 ) as $param ) {
			if ( preg_match( '/q\s*=\s*([0-9.]+)/', $param, $m ) ) {
				$q = (float) $m[1];
			}
		}

		if ( $q <= 0 ) {
			continue;
		}

		$oferecido = in_array( $type, [ 'text/markdown', 'text/html', 'text/*', '*/*' ], true );
		if ( $oferecido ) {
			$algum_ok = true;
			if ( $q > $best_q ) {
				$best_q    = $q;
				$best_type = $type;
			}
		}
	}

	if ( ! $algum_ok ) {
		return 'unsatisfiable';
	}

	return $best_type === 'text/markdown' ? 'markdown' : 'html';
}

function dsi_agentmd_send_406(): void {
	status_header( 406 );
	header( 'Content-Type: text/plain; charset=utf-8' );
	echo "406 Not Acceptable\n\nEste recurso esta disponivel em text/html ou text/markdown.";
	exit;
}

// Visita normal (sem sufixo .md) a um post/pagina -- so loga se o UA for
// um bot reconhecido, senao a tabela vira log de trafego humano inteiro.
function dsi_agentmd_maybe_log_html(): void {
	if ( ! is_singular( [ 'post', 'page' ] ) ) {
		return;
	}

	$user_agent = sanitize_text_field( $_SERVER['HTTP_USER_AGENT'] ?? '' );
	if ( dsi_agentmd_classify_bot( $user_agent ) === 'desconhecido' ) {
		return;
	}

	dsi_agentmd_log_request( (int) get_queried_object_id(), $user_agent, 'html' );
}

function dsi_agentmd_yaml_escape( string $text ): string {
	$text = str_replace( '"', "'", $text );
	return '"' . trim( $text ) . '"';
}

// =============================================================================
// LOG — quem pediu Markdown ou visitou HTML como bot reconhecido (tabela
// criada via script one-off, ver "Workflow de deploy padrão" no CLAUDE.md)
// =============================================================================
function dsi_agentmd_log_request( int $post_id, string $user_agent, string $tipo ): void {
	global $wpdb;

	$client_ip = sanitize_text_field( $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '' );

	// Assinatura Web Bot Auth (RFC 9421), quando presente -- unico jeito de
	// distinguir um agente verificado (ex: ChatGPT) de um User-Agent comum
	// de navegador, ja que a chamada de tool do WebMCP nao carrega UA de bot.
	$signed_agent = function_exists( 'dsi_wba_verify_current_request' )
		? dsi_wba_verify_current_request()
		: null;

	$wpdb->insert(
		$wpdb->prefix . 'ai_bot_requests',
		[
			'requested_at' => current_time( 'mysql' ),
			'post_id'      => $post_id,
			'url_path'     => esc_url_raw( $_SERVER['REQUEST_URI'] ?? '' ),
			'user_agent'   => $user_agent,
			'client_ip'    => $client_ip,
			'bot_label'    => dsi_agentmd_classify_bot( $user_agent ),
			'signed_agent' => $signed_agent,
			'tipo'         => $tipo,
		],
		[ '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s' ]
	);
}

function dsi_agentmd_classify_bot( string $user_agent ): string {
	$known = [
		// Bots de IA
		'GPTBot', 'ChatGPT-User', 'OAI-SearchBot', 'ClaudeBot', 'Claude-Web', 'Claude-User',
		'Claude-SearchBot', 'anthropic-ai', 'PerplexityBot', 'Perplexity-User', 'CCBot',
		'Google-Extended', 'GoogleOther', 'Bytespider', 'Amazonbot', 'Applebot',
		'meta-externalagent', 'FacebookBot', 'DuckAssistBot', 'YouBot', 'Diffbot',
		'cohere-ai', 'AI2Bot', 'ImagesiftBot', 'omgili', 'Timpibot', 'MistralAI',
		// Buscadores tradicionais (tambem podem pedir a versao .md)
		'Googlebot', 'bingbot', 'Bingbot', 'YandexBot', 'Baiduspider', 'DuckDuckBot',
		'AhrefsBot', 'SemrushBot', 'MJ12bot', 'DotBot', 'PetalBot',
	];

	foreach ( $known as $bot ) {
		if ( stripos( $user_agent, $bot ) !== false ) {
			return $bot;
		}
	}

	return 'desconhecido';
}

// =============================================================================
// CONVERSÃO HTML -> MARKDOWN
// Cobre só as tags que o tema realmente produz no conteúdo (sem dependência
// externa — não há Composer no repo).
// =============================================================================
function dsi_agentmd_html_to_markdown( string $html ): string {
	if ( trim( $html ) === '' ) {
		return '';
	}

	$doc = new DOMDocument();
	libxml_use_internal_errors( true );
	$doc->loadHTML( '<?xml encoding="utf-8" ?><div id="dsi-agentmd-root">' . $html . '</div>', LIBXML_NOWARNING | LIBXML_NOERROR );
	libxml_clear_errors();

	$root = $doc->getElementById( 'dsi-agentmd-root' );
	$md   = $root ? dsi_agentmd_node_to_markdown( $root ) : '';

	$md = preg_replace( "/[ \t]+\n/", "\n", $md );
	$md = preg_replace( "/\n{3,}/", "\n\n", $md );

	return trim( $md );
}

function dsi_agentmd_node_to_markdown( DOMNode $node ): string {
	$out = '';

	foreach ( $node->childNodes as $child ) {
		if ( $child->nodeType === XML_TEXT_NODE ) {
			$out .= preg_replace( '/\s+/', ' ', $child->textContent );
			continue;
		}

		if ( $child->nodeType !== XML_ELEMENT_NODE ) {
			continue;
		}

		$tag   = strtolower( $child->nodeName );
		$inner = dsi_agentmd_node_to_markdown( $child );

		switch ( $tag ) {
			case 'h1':
			case 'h2':
			case 'h3':
			case 'h4':
				$level = (int) substr( $tag, 1 );
				$out  .= "\n\n" . str_repeat( '#', $level ) . ' ' . trim( $inner ) . "\n\n";
				break;

			case 'p':
			case 'blockquote':
				$prefix = $tag === 'blockquote' ? '> ' : '';
				$out   .= "\n\n" . $prefix . trim( $inner ) . "\n\n";
				break;

			case 'strong':
			case 'b':
				$out .= '**' . trim( $inner ) . '**';
				break;

			case 'em':
			case 'i':
				$out .= '*' . trim( $inner ) . '*';
				break;

			case 'a':
				$href = $child instanceof DOMElement ? $child->getAttribute( 'href' ) : '';
				$out .= $href ? '[' . trim( $inner ) . '](' . $href . ')' : trim( $inner );
				break;

			case 'img':
				if ( $child instanceof DOMElement ) {
					$alt = $child->getAttribute( 'alt' );
					$src = $child->getAttribute( 'src' );
					if ( $src ) {
						$out .= "\n\n" . '![' . $alt . '](' . $src . ')' . "\n\n";
					}
				}
				break;

			case 'ul':
			case 'ol':
				if ( $child instanceof DOMElement ) {
					$out .= "\n\n" . dsi_agentmd_list_to_markdown( $child, $tag === 'ol' ) . "\n\n";
				}
				break;

			case 'br':
				$out .= "\n";
				break;

			case 'code':
				$out .= '`' . trim( $inner ) . '`';
				break;

			case 'pre':
				$out .= "\n\n```\n" . trim( $inner ) . "\n```\n\n";
				break;

			case 'script':
			case 'style':
			case 'iframe':
			case 'noscript':
				break;

			default:
				$out .= $inner;
		}
	}

	return $out;
}

function dsi_agentmd_list_to_markdown( DOMElement $list, bool $ordered ): string {
	$lines = [];
	$i     = 1;

	foreach ( $list->childNodes as $item ) {
		if ( $item->nodeType !== XML_ELEMENT_NODE || strtolower( $item->nodeName ) !== 'li' ) {
			continue;
		}
		$marker  = $ordered ? ( $i++ . '.' ) : '-';
		$lines[] = $marker . ' ' . trim( dsi_agentmd_node_to_markdown( $item ) );
	}

	return implode( "\n", $lines );
}
