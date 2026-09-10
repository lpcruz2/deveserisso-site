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
	dsi_agentmd_send_markdown( $post, $user_agent );
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

	// O 200 vai explicito porque neste ponto o WP ainda acha que a query e
	// 404: o parser de permalinks nao reconhece o sufixo .md (mesma quirk
	// do LiteSpeed com REQUEST_URI comentada em serve_via_md_suffix). Sem
	// isso o log gravava 404 numa resposta que sai 200.
	$user_agent = sanitize_text_field( $_SERVER['HTTP_USER_AGENT'] ?? '' );
	dsi_agentmd_log_request( 0, $user_agent, 'md', null, 200 );

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
function dsi_agentmd_send_markdown( WP_Post $post, string $user_agent ): void {
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

	// 200 explicito -- ver a nota em dsi_agentmd_send_homepage_markdown().
	dsi_agentmd_log_request( $post->ID, $user_agent, 'md', null, 200 );

	status_header( 200 );
	header( 'Content-Type: text/markdown; charset=utf-8' );
	header( 'X-Robots-Tag: noindex' );
	echo $frontmatter . $body;
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
/**
 * IP real do cliente.
 *
 * Nao da pra usar REMOTE_ADDR nem CF-Connecting-IP aqui: o hcdn (CDN da
 * Hostinger, entre o Cloudflare e a origem) nao repassa o CF-Connecting-IP,
 * e o REMOTE_ADDR que sobra e o IP do edge do Cloudflare -- por isso ate
 * 2026-09-07 o log gravava um mesmo "IP" servindo 7 bots diferentes, e
 * "bot rotacionando IP" era, na verdade, edges diferentes.
 *
 * O valor real esta no X-Forwarded-For, mas NAO no primeiro elemento: o
 * Cloudflare faz append ao XFF que o cliente mandar, entao qualquer um pode
 * injetar entradas a esquerda. Confirmado ao vivo mandando
 * "X-Forwarded-For: 1.2.3.4" e recebendo "1.2.3.4,<ip real>,<edge>" na
 * origem. O unico elemento confiavel e o PENULTIMO -- o que o Cloudflare
 * escreveu, logo antes de o hcdn anexar o edge. Tudo a esquerda dele e
 * controlado pelo cliente e precisa ser ignorado.
 */
function dsi_agentmd_client_ip(): string {
	$xff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';

	if ( $xff !== '' ) {
		$partes = array_values( array_filter( array_map( 'trim', explode( ',', $xff ) ) ) );
		if ( count( $partes ) >= 2 ) {
			$candidato = $partes[ count( $partes ) - 2 ];
			if ( filter_var( $candidato, FILTER_VALIDATE_IP ) ) {
				return $candidato;
			}
		}
	}

	return sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '' );
}

/** Pais do visitante -- vem de graca no header do Cloudflare (ex: "BR"). */
function dsi_agentmd_country(): ?string {
	$pais = strtoupper( sanitize_text_field( $_SERVER['HTTP_CF_IPCOUNTRY'] ?? '' ) );

	return preg_match( '/^[A-Z]{2}$/', $pais ) ? $pais : null;
}

/**
 * Corta no limite da coluna. url_path e referer sao varchar(255) e chegam
 * do cliente sem limite -- em strict mode o INSERT inteiro falharia e a
 * requisicao sumiria do log sem aviso nenhum.
 */
function dsi_agentmd_trunca( string $valor, int $limite = 255 ): string {
	return mb_substr( $valor, 0, $limite );
}

/**
 * NOTA SOBRE O QUE ESTE LOG *NAO* MEDE: so chega aqui requisicao que
 * executou PHP, ou seja, que deu MISS nas tres camadas de cache. Medido em
 * 2026-09-07: 3 requisicoes seguidas ao mesmo post com UA de bot geraram 1
 * linha (a 3a voltou x-litespeed-cache: hit, sem tocar a origem). Nenhum
 * numero daqui significa "quantas vezes o bot acessou" -- so "quantas vezes
 * o bot forcou a origem". Contagem real exigiria o access log do servidor.
 * Por isso nao existe coluna de cache_status: do lado do PHP ela seria
 * sempre "miss", por construcao.
 */
function dsi_agentmd_log_request( int $post_id, string $user_agent, string $tipo, ?string $tool_name = null, ?int $http_status = null, ?string $response_body = null ): void {
	global $wpdb;

	// Assinatura Web Bot Auth (RFC 9421), quando presente -- unico jeito de
	// distinguir um agente verificado (ex: ChatGPT) de um User-Agent comum
	// de navegador, ja que a chamada de tool do WebMCP nao carrega UA de bot.
	// ATENCAO: inerte hoje -- a extensao sodium nao existe neste servidor,
	// entao dsi_wba_verify_current_request() retorna null sempre. Ver
	// CLAUDE.md, secao "Servidor e CDN".
	$signed_agent = function_exists( 'dsi_wba_verify_current_request' )
		? dsi_wba_verify_current_request()
		: null;

	$referer = dsi_agentmd_trunca( sanitize_text_field( $_SERVER['HTTP_REFERER'] ?? '' ) );
	$status  = $http_status ?? ( http_response_code() ?: null );

	$wpdb->insert(
		$wpdb->prefix . 'ai_bot_requests',
		[
			'requested_at'  => current_time( 'mysql' ),
			'post_id'       => $post_id,
			'url_path'      => dsi_agentmd_trunca( esc_url_raw( $_SERVER['REQUEST_URI'] ?? '' ) ),
			'user_agent'    => dsi_agentmd_trunca( $user_agent ),
			'client_ip'     => dsi_agentmd_client_ip(),
			'country'       => dsi_agentmd_country(),
			'bot_label'     => dsi_agentmd_classify_bot( $user_agent ),
			'signed_agent'  => $signed_agent,
			'tipo'          => $tipo,
			'http_status'   => $status,
			'tool_name'     => $tool_name,
			'referer'       => $referer !== '' ? $referer : null,
			'response_body' => $response_body,
		],
		[ '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' ]
	);
}

// =============================================================================
// RETENCAO -- a tabela cresce ~1.000 linhas/dia (~500 B cada). Sem purga
// seriam ~180 MB/ano de log que ninguem consulta depois de alguns meses.
// =============================================================================
const DSI_AGENTMD_RETENCAO_DIAS = 180;

add_action( 'init', 'dsi_agentmd_agenda_purga' );
add_action( 'dsi_agentmd_purga_event', 'dsi_agentmd_purga_log' );

function dsi_agentmd_agenda_purga(): void {
	if ( ! wp_next_scheduled( 'dsi_agentmd_purga_event' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'dsi_agentmd_purga_event' );
	}
}

function dsi_agentmd_purga_log(): void {
	global $wpdb;

	$tabela = $wpdb->prefix . 'ai_bot_requests';
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$tabela} WHERE requested_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
			DSI_AGENTMD_RETENCAO_DIAS
		)
	);
}

/** Bots que se anunciam e tem nome canonico conhecido. */
function dsi_agentmd_bots_conhecidos(): array {
	return [
		// Bots de IA
		'GPTBot', 'ChatGPT-User', 'OAI-SearchBot', 'ClaudeBot', 'Claude-Web', 'Claude-User',
		'Claude-SearchBot', 'anthropic-ai', 'PerplexityBot', 'Perplexity-User', 'CCBot',
		'Google-Extended', 'GoogleOther', 'Bytespider', 'Amazonbot', 'Applebot',
		'meta-externalagent', 'FacebookBot', 'DuckAssistBot', 'YouBot', 'Diffbot',
		'cohere-ai', 'AI2Bot', 'ImagesiftBot', 'omgili', 'Timpibot', 'MistralAI',
		// Vistos no proprio log em 2026-09, caiam em "desconhecido" so por
		// falta de entrada aqui (nao por falha de deteccao).
		'OraBot', 'archive.org_bot', 'ShapBot',
		// Buscadores tradicionais (tambem podem pedir a versao .md)
		'Googlebot', 'bingbot', 'Bingbot', 'YandexBot', 'Baiduspider', 'DuckDuckBot',
		'AhrefsBot', 'SemrushBot', 'MJ12bot', 'DotBot', 'PetalBot',
	];
}

/**
 * Clientes HTTP genericos: um script, um monitor, uma sessao de debug.
 * Nao sao bots (nao se anunciam como tal) nem navegadores.
 */
function dsi_agentmd_ferramentas_http(): array {
	return [
		'curl', 'Wget', 'python-requests', 'python-urllib', 'aiohttp', 'httpx',
		'node-fetch', 'undici', 'axios', 'Go-http-client', 'okhttp',
		'Apache-HttpClient', 'libwww-perl', 'HTTPie', 'PostmanRuntime',
		'GuzzleHttp', 'Scrapy', 'Java', 'node',
	];
}

/**
 * Categoria do rotulo -- o painel usa pra nao listar "Baiduspider" e
 * "curl" lado a lado como se fossem a mesma coisa. Sao respostas a
 * perguntas diferentes: um e crawler indexando o site, o outro e alguem
 * rodando um script contra ele.
 */
function dsi_agentmd_categoria_cliente( string $label ): string {
	if ( $label === 'desconhecido' ) {
		return 'nao_identificado';
	}

	if ( in_array( $label, dsi_agentmd_ferramentas_http(), true ) ) {
		return 'ferramenta';
	}

	// Sobra o que veio da lista canonica ou do fallback bot|crawler|spider.
	return 'bot';
}

function dsi_agentmd_categoria_label( string $categoria ): string {
	return [
		'bot'              => 'Bot declarado',
		'ferramenta'       => 'Ferramenta HTTP',
		'nao_identificado' => 'Não identificado',
	][ $categoria ] ?? $categoria;
}

function dsi_agentmd_classify_bot( string $user_agent ): string {
	foreach ( dsi_agentmd_bots_conhecidos() as $bot ) {
		if ( stripos( $user_agent, $bot ) !== false ) {
			return $bot;
		}
	}

	// Fallback heuristico: qualquer token que se autodeclare bot vira o
	// proprio nome, mesmo sem estar na lista acima.
	//
	// Sem isso, todo bot novo caia em "desconhecido" ate alguem reparar e
	// editar o array na mao -- foi exatamente o que aconteceu com o OraBot,
	// que gerou 130 requisicoes rotuladas como "desconhecido" antes de ser
	// notado. Ja se provou em producao: capturou LinkupBot,
	// SERankingBacklinksBot e Pinterestbot sozinho.
	//
	// So casa quem carrega "bot", "crawler" ou "spider" no nome: sao
	// autodeclaracoes, nao inferencia.
	if ( preg_match( '/([A-Za-z0-9][A-Za-z0-9._-]{2,30}(?:bot|crawler|spider)[A-Za-z0-9._-]{0,20})/i', $user_agent, $m ) ) {
		return substr( $m[1], 0, 50 );
	}

	// Cliente HTTP generico. O rotulo e factual (o que o cliente disse
	// ser), nao inferencia de identidade -- por isso "curl", e nao "bot de
	// alguem". "node" fica de fora do loop porque e palavra curta demais
	// pra casar por substring com seguranca: exige igualdade exata.
	foreach ( dsi_agentmd_ferramentas_http() as $ferramenta ) {
		if ( $ferramenta === 'node' ) {
			continue;
		}
		if ( preg_match( '/(?<![A-Za-z0-9])' . preg_quote( $ferramenta, '/' ) . '(?![A-Za-z0-9])/i', $user_agent ) ) {
			return $ferramenta;
		}
	}

	if ( strcasecmp( trim( $user_agent ), 'node' ) === 0 ) {
		return 'node';
	}

	// O que sobra e User-Agent de navegador: ou e gente, ou e scraper se
	// passando por gente. "desconhecido" e o unico rotulo honesto pros dois.
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
