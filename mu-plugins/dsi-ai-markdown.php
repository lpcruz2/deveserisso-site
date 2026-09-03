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
// via llms.txt) saberem que a alternativa .md existe.
function dsi_agentmd_alternate_link(): void {
	if ( ! is_singular( [ 'post', 'page' ] ) ) {
		return;
	}

	$url = rtrim( get_permalink(), '/' ) . '.md';
	printf( '<link rel="alternate" type="text/markdown" href="%s">' . "\n", esc_url( $url ) );
}

function dsi_agentmd_maybe_serve(): void {
	if ( ! isset( $_GET['dsi_markdown'] ) ) {
		return;
	}

	// O LiteSpeed nao atualiza $_SERVER['REQUEST_URI'] entre os blocos de
	// rewrite do .htaccess (cada <IfModule> e reescrito internamente, mas o
	// valor que o PHP ve continua sendo a URI original com ".md"). Por isso
	// nao da pra confiar em is_singular()/get_queried_object() aqui -- o
	// parser de permalinks do WP tentaria casar ".md" como parte do slug.
	// Resolve o post direto pelo path, ignorando o roteamento do WP.
	$path = (string) parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH );
	$path = preg_replace( '#\.md$#', '', $path );
	$path = trim( $path, '/' );

	if ( $path === '' ) {
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

	dsi_agentmd_log_request( $post->ID );

	status_header( 200 );
	header( 'Content-Type: text/markdown; charset=utf-8' );
	header( 'X-Robots-Tag: noindex' );
	echo $frontmatter . $body;
	exit;
}

function dsi_agentmd_yaml_escape( string $text ): string {
	$text = str_replace( '"', "'", $text );
	return '"' . trim( $text ) . '"';
}

// =============================================================================
// LOG — quem pediu a versão Markdown (tabela criada via script one-off,
// ver "Workflow de deploy padrão" no CLAUDE.md do projeto)
// =============================================================================
function dsi_agentmd_log_request( int $post_id ): void {
	global $wpdb;

	$user_agent = sanitize_text_field( $_SERVER['HTTP_USER_AGENT'] ?? '' );
	$client_ip  = sanitize_text_field( $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '' );

	$wpdb->insert(
		$wpdb->prefix . 'ai_bot_requests',
		[
			'requested_at' => current_time( 'mysql' ),
			'post_id'      => $post_id,
			'url_path'     => esc_url_raw( $_SERVER['REQUEST_URI'] ?? '' ),
			'user_agent'   => $user_agent,
			'client_ip'    => $client_ip,
			'bot_label'    => dsi_agentmd_classify_bot( $user_agent ),
		],
		[ '%s', '%d', '%s', '%s', '%s', '%s' ]
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
