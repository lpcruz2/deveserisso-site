<?php
/**
 * Plugin Name: DSI — Markdown para agentes de IA
 * Description: Serve uma versão em Markdown de posts/páginas via sufixo .md na URL, e registra quem pediu.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'template_redirect', 'dsi_maybe_serve_markdown' );

// DIAGNOSTICO TEMPORARIO — remover apos achar a causa do 500.
register_shutdown_function( 'dsi_debug_shutdown' );
function dsi_debug_shutdown(): void {
	if ( ! isset( $_GET['dsi_markdown'] ) ) {
		return;
	}
	$error = error_get_last();
	if ( $error && in_array( $error['type'], [ E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR ], true ) ) {
		if ( ! headers_sent() ) {
			header( 'Content-Type: text/plain; charset=utf-8' );
		}
		echo "\nDSI-SHUTDOWN-DEBUG: " . $error['message'] . ' em ' . $error['file'] . ':' . $error['line'];
	}
}

function dsi_maybe_serve_markdown(): void {
	if ( ! isset( $_GET['dsi_markdown'] ) || ! is_singular() ) {
		return;
	}

	// DIAGNOSTICO TEMPORARIO — remover apos achar a causa do 500.
	try {
		dsi_maybe_serve_markdown_inner();
	} catch ( \Throwable $e ) {
		header( 'Content-Type: text/plain; charset=utf-8' );
		echo "DSI-DEBUG: " . $e->getMessage() . " em " . $e->getFile() . ":" . $e->getLine();
		exit;
	}
}

function dsi_maybe_serve_markdown_inner(): void {
	$post = get_queried_object();
	if ( ! $post instanceof WP_Post ) {
		return;
	}

	$html = apply_filters( 'the_content', $post->post_content );
	$body = dsi_html_to_markdown( $html );

	$description = get_post_meta( $post->ID, '_yoast_wpseo_metadesc', true );
	if ( ! $description ) {
		$description = wp_strip_all_tags( get_the_excerpt( $post ) );
	}

	$frontmatter = sprintf(
		"---\ntitle: %s\nurl: %s\ndate: %s\ndescription: %s\n---\n\n",
		dsi_yaml_escape( get_the_title( $post ) ),
		esc_url( get_permalink( $post ) ),
		get_the_date( 'Y-m-d', $post ),
		dsi_yaml_escape( $description )
	);

	dsi_log_ai_bot_request( $post->ID );

	header( 'Content-Type: text/markdown; charset=utf-8' );
	header( 'X-Robots-Tag: noindex' );
	echo $frontmatter . $body;
	exit;
}

function dsi_yaml_escape( string $text ): string {
	$text = str_replace( '"', "'", $text );
	return '"' . trim( $text ) . '"';
}

// =============================================================================
// LOG — quem pediu a versão Markdown (tabela criada via script one-off,
// ver "Workflow de deploy padrão" no CLAUDE.md do projeto)
// =============================================================================
function dsi_log_ai_bot_request( int $post_id ): void {
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
			'bot_label'    => dsi_classify_bot( $user_agent ),
		],
		[ '%s', '%d', '%s', '%s', '%s', '%s' ]
	);
}

function dsi_classify_bot( string $user_agent ): string {
	$known = [
		'GPTBot', 'ChatGPT-User', 'OAI-SearchBot', 'ClaudeBot', 'Claude-Web', 'anthropic-ai',
		'PerplexityBot', 'CCBot', 'Google-Extended', 'GoogleOther', 'Bytespider',
		'Amazonbot', 'Applebot', 'meta-externalagent', 'DuckAssistBot',
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
function dsi_html_to_markdown( string $html ): string {
	if ( trim( $html ) === '' ) {
		return '';
	}

	$doc = new DOMDocument();
	libxml_use_internal_errors( true );
	$doc->loadHTML( '<?xml encoding="utf-8" ?><div id="dsi-root">' . $html . '</div>', LIBXML_NOWARNING | LIBXML_NOERROR );
	libxml_clear_errors();

	$root = $doc->getElementById( 'dsi-root' );
	$md   = $root ? dsi_node_to_markdown( $root ) : '';

	$md = preg_replace( "/[ \t]+\n/", "\n", $md );
	$md = preg_replace( "/\n{3,}/", "\n\n", $md );

	return trim( $md );
}

function dsi_node_to_markdown( DOMNode $node ): string {
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
		$inner = dsi_node_to_markdown( $child );

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
					$out .= "\n\n" . dsi_list_to_markdown( $child, $tag === 'ol' ) . "\n\n";
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

function dsi_list_to_markdown( DOMElement $list, bool $ordered ): string {
	$lines = [];
	$i     = 1;

	foreach ( $list->childNodes as $item ) {
		if ( $item->nodeType !== XML_ELEMENT_NODE || strtolower( $item->nodeName ) !== 'li' ) {
			continue;
		}
		$marker  = $ordered ? ( $i++ . '.' ) : '-';
		$lines[] = $marker . ' ' . trim( dsi_node_to_markdown( $item ) );
	}

	return implode( "\n", $lines );
}
