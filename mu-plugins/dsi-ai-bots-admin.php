<?php
/**
 * Plugin Name: DSI — Painel de bots de IA
 * Description: Mostra em Ferramentas → Bots de IA quem está acessando o site (Markdown ou HTML).
 */

defined( 'ABSPATH' ) || exit;

const DSI_AGENTMD_POR_PAGINA      = 50; // linhas da tabela de requisições
const DSI_AGENTMD_BOTS_POR_PAGINA = 10; // linhas da tabela de bots

add_action( 'admin_menu', 'dsi_agentmd_admin_menu' );
add_action( 'admin_post_dsi_agentmd_export_csv', 'dsi_agentmd_export_csv' );
add_action( 'wp_ajax_dsi_agentmd_reqs', 'dsi_agentmd_ajax_reqs' );

function dsi_agentmd_admin_menu(): void {
	add_management_page(
		'Bots',
		'Bots',
		'manage_options',
		'dsi-ai-bots',
		'dsi_agentmd_admin_page'
	);
}

/**
 * Le e valida o intervalo de datas da querystring, com fallback pros
 * ultimos 30 dias. Usado tanto pela pagina quanto pelo export CSV, pra
 * os dois sempre baterem com o que esta na tela.
 *
 * @return array{0: string, 1: string,2: string, 3: string} [inicio_sql, fim_sql, inicio_input, fim_input]
 */
function dsi_agentmd_periodo_from_request(): array {
	$inicio = isset( $_GET['data_inicio'] ) ? sanitize_text_field( wp_unslash( $_GET['data_inicio'] ) ) : '';
	$fim    = isset( $_GET['data_fim'] ) ? sanitize_text_field( wp_unslash( $_GET['data_fim'] ) ) : '';

	if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $inicio ) ) {
		$inicio = gmdate( 'Y-m-d', strtotime( '-30 days' ) );
	}
	if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $fim ) ) {
		$fim = gmdate( 'Y-m-d' );
	}

	return [ $inicio . ' 00:00:00', $fim . ' 23:59:59', $inicio, $fim ];
}

function dsi_agentmd_bot_from_request(): string {
	return isset( $_GET['bot'] ) ? sanitize_text_field( wp_unslash( $_GET['bot'] ) ) : '';
}

function dsi_agentmd_tipo_label( string $tipo ): string {
	return [ 'md' => 'Markdown', 'html' => 'Página HTML', 'mcp' => 'MCP (tool call)' ][ $tipo ] ?? $tipo;
}

function dsi_agentmd_tipo_from_request(): string {
	$tipo = isset( $_GET['tipo'] ) ? sanitize_text_field( wp_unslash( $_GET['tipo'] ) ) : '';
	return in_array( $tipo, [ 'md', 'html', 'mcp' ], true ) ? $tipo : '';
}

/** '' = todos, '1' = so assinados (Web Bot Auth, RFC 9421), '0' = so nao assinados */
function dsi_agentmd_assinado_from_request(): string {
	$assinado = isset( $_GET['assinado'] ) ? sanitize_text_field( wp_unslash( $_GET['assinado'] ) ) : '';
	return in_array( $assinado, [ '1', '0' ], true ) ? $assinado : '';
}

/**
 * Monta a clausula WHERE (periodo + bot + tipo, todos opcionais exceto
 * periodo) e os parametros pra $wpdb->prepare(), reaproveitada pelo
 * overview, pela tabela paginada e pelo export CSV -- os tres sempre
 * filtram igual.
 *
 * @return array{0: string, 1: array<int, string>}
 */
function dsi_agentmd_where_and_params( string $inicio_sql, string $fim_sql, string $bot, string $tipo = '', string $assinado = '' ): array {
	$where  = 'requested_at BETWEEN %s AND %s';
	$params = [ $inicio_sql, $fim_sql ];

	if ( $bot !== '' ) {
		$where   .= ' AND bot_label = %s';
		$params[] = $bot;
	}

	if ( $tipo !== '' ) {
		$where   .= ' AND tipo = %s';
		$params[] = $tipo;
	}

	if ( $assinado === '1' ) {
		$where .= ' AND signed_agent IS NOT NULL';
	} elseif ( $assinado === '0' ) {
		$where .= ' AND signed_agent IS NULL';
	}

	return [ $where, $params ];
}

function dsi_agentmd_export_csv(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Sem permissão.' );
	}
	check_admin_referer( 'dsi_agentmd_export_csv' );

	global $wpdb;
	$table = $wpdb->prefix . 'ai_bot_requests';

	[ $inicio_sql, $fim_sql, $inicio_input, $fim_input ] = dsi_agentmd_periodo_from_request();
	$bot                = dsi_agentmd_bot_from_request();
	$tipo               = dsi_agentmd_tipo_from_request();
	$assinado           = dsi_agentmd_assinado_from_request();
	[ $where, $params ] = dsi_agentmd_where_and_params( $inicio_sql, $fim_sql, $bot, $tipo, $assinado );

	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT requested_at, bot_label, signed_agent, tipo, tool_name, http_status, post_id, url_path, user_agent, client_ip, country, referer
			 FROM {$table}
			 WHERE {$where}
			 ORDER BY requested_at DESC",
			$params
		)
	);

	$sufixo_bot      = $bot !== '' ? '-' . sanitize_title( $bot ) : '';
	$sufixo_tipo     = $tipo !== '' ? '-' . $tipo : '';
	$sufixo_assinado = $assinado !== '' ? '-assinado' . $assinado : '';

	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="ai-bot-requests-' . $inicio_input . '-a-' . $fim_input . $sufixo_bot . $sufixo_tipo . $sufixo_assinado . '.csv"' );

	$out = fopen( 'php://output', 'w' );
	fputcsv( $out, [ 'data', 'bot', 'assinado_rfc9421', 'tipo', 'categoria_cliente', 'tool_mcp', 'http_status', 'post_id', 'post_titulo', 'url', 'user_agent', 'ip', 'pais', 'referer' ] );

	foreach ( $rows as $row ) {
		$post_title = $row->post_id ? get_the_title( (int) $row->post_id ) : '';
		fputcsv( $out, [
			$row->requested_at,
			$row->bot_label,
			$row->signed_agent ?? '',
			$row->tipo,
			dsi_agentmd_categoria_label( dsi_agentmd_categoria_cliente( $row->bot_label ) ),
			$row->tool_name ?? '',
			$row->http_status ?? '',
			$row->post_id,
			$post_title,
			$row->url_path,
			$row->user_agent,
			$row->client_ip,
			$row->country ?? '',
			$row->referer ?? '',
		] );
	}

	fclose( $out );
	exit;
}

function dsi_agentmd_admin_page(): void {
	global $wpdb;
	$table = $wpdb->prefix . 'ai_bot_requests';

	[ $inicio_sql, $fim_sql, $inicio_input, $fim_input ] = dsi_agentmd_periodo_from_request();
	$bot                = dsi_agentmd_bot_from_request();
	$tipo               = dsi_agentmd_tipo_from_request();
	$assinado           = dsi_agentmd_assinado_from_request();
	[ $where, $params ] = dsi_agentmd_where_and_params( $inicio_sql, $fim_sql, $bot, $tipo, $assinado );

	$total_periodo = (int) $wpdb->get_var(
		$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where}", $params )
	);

	$top_bots = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT bot_label, COUNT(*) AS total, MAX(requested_at) AS ultima_vez
			 FROM {$table}
			 WHERE {$where}
			 GROUP BY bot_label
			 ORDER BY total DESC",
			$params
		)
	);

	// Bot cuja PRIMEIRA aparicao no log INTEIRO (nao so no periodo filtrado)
	// cai dentro do periodo selecionado = novo. E assim que um OraBot da
	// vida aparece destacado no dia 1, em vez de so ser notado semanas
	// depois no meio da tabela.
	$bots_novos = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT bot_label FROM {$table} GROUP BY bot_label HAVING MIN(requested_at) >= %s",
			$inicio_sql
		)
	);

	$top_tools = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT tool_name, COUNT(*) AS total,
			        SUM(http_status IS NOT NULL AND http_status <> 200) AS erros,
			        COUNT(DISTINCT client_ip) AS ips,
			        MAX(requested_at) AS ultima_vez
			 FROM {$table}
			 WHERE {$where} AND tool_name IS NOT NULL
			 GROUP BY tool_name
			 ORDER BY total DESC",
			$params
		)
	);

	$top_posts = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT post_id, COUNT(*) AS total, COUNT(DISTINCT bot_label) AS bots, SUM(tipo = 'md') AS md
			 FROM {$table}
			 WHERE {$where} AND post_id > 0
			 GROUP BY post_id
			 ORDER BY total DESC
			 LIMIT 15",
			$params
		)
	);

	// Lista de bots pro dropdown: todos os ja vistos historicamente,
	// independente do periodo filtrado, pra nao sumir opcao ao estreitar a data.
	$bots_disponiveis = $wpdb->get_col( "SELECT DISTINCT bot_label FROM {$table} ORDER BY bot_label ASC" );

	// --- Período imediatamente anterior, com a mesma duração, pra comparação ---
	$duracao_dias        = (int) ( ( strtotime( $fim_input ) - strtotime( $inicio_input ) ) / DAY_IN_SECONDS ) + 1;
	$anterior_fim_input  = gmdate( 'Y-m-d', strtotime( $inicio_input ) - DAY_IN_SECONDS );
	$anterior_ini_input  = gmdate( 'Y-m-d', strtotime( $anterior_fim_input ) - ( $duracao_dias - 1 ) * DAY_IN_SECONDS );
	$anterior_ini_sql    = $anterior_ini_input . ' 00:00:00';
	$anterior_fim_sql    = $anterior_fim_input . ' 23:59:59';

	[ $where_anterior, $params_anterior ] = dsi_agentmd_where_and_params( $anterior_ini_sql, $anterior_fim_sql, $bot, $tipo, $assinado );
	$total_anterior = (int) $wpdb->get_var(
		$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where_anterior}", $params_anterior )
	);

	if ( $total_anterior > 0 ) {
		$variacao_pct = ( ( $total_periodo - $total_anterior ) / $total_anterior ) * 100;
		$variacao_cor = $variacao_pct > 0 ? '#00a32a' : ( $variacao_pct < 0 ? '#d63638' : '#646970' );
		$variacao_txt = sprintf( '%+.0f%%', $variacao_pct );
	} elseif ( $total_periodo > 0 ) {
		$variacao_cor = '#00a32a';
		$variacao_txt = 'novo';
	} else {
		$variacao_cor = '#646970';
		$variacao_txt = '—';
	}

	// Sempre abre na primeira pagina: a navegacao acontece dentro do
	// componente (AJAX), sem estado na URL.
	$per_page      = DSI_AGENTMD_POR_PAGINA;
	$paged         = 1;
	$total_paginas = (int) max( 1, ceil( $total_periodo / $per_page ) );

	$detalhe = dsi_agentmd_busca_detalhe( $table, $where, $params, $paged, $per_page );

	$export_url = wp_nonce_url(
		add_query_arg(
			[
				'action'      => 'dsi_agentmd_export_csv',
				'data_inicio' => $inicio_input,
				'data_fim'    => $fim_input,
				'bot'         => $bot,
				'tipo'        => $tipo,
				'assinado'    => $assinado,
			],
			admin_url( 'admin-post.php' )
		),
		'dsi_agentmd_export_csv'
	);

	echo '<div class="wrap"><h1>Bots de IA — acessos ao site</h1>';

	// --- Filtros ---
	echo '<form method="get" style="margin:16px 0;display:flex;gap:8px;align-items:end;flex-wrap:wrap;">';
	echo '<input type="hidden" name="page" value="dsi-ai-bots">';
	echo '<label>De <input type="date" name="data_inicio" value="' . esc_attr( $inicio_input ) . '"></label>';
	echo '<label>Até <input type="date" name="data_fim" value="' . esc_attr( $fim_input ) . '"></label>';

	// Agrupado por categoria: sem isso a lista misturava "Baiduspider" com
	// "curl" e "desconhecido" em ordem alfabetica, como se fossem a mesma
	// coisa. Sao respostas a perguntas diferentes.
	$por_categoria = [ 'bot' => [], 'ferramenta' => [], 'nao_identificado' => [] ];
	foreach ( $bots_disponiveis as $opcao ) {
		$por_categoria[ dsi_agentmd_categoria_cliente( $opcao ) ][] = $opcao;
	}

	echo '<label>Cliente <select name="bot"><option value="">Todos</option>';
	foreach ( $por_categoria as $categoria => $opcoes ) {
		if ( ! $opcoes ) {
			continue;
		}
		printf( '<optgroup label="%s">', esc_attr( dsi_agentmd_categoria_label( $categoria ) ) );
		foreach ( $opcoes as $opcao ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $opcao ),
				selected( $bot, $opcao, false ),
				esc_html( $opcao )
			);
		}
		echo '</optgroup>';
	}
	echo '</select></label>';

	echo '<label>Tipo <select name="tipo">';
	printf( '<option value=""%s>Todos</option>', selected( $tipo, '', false ) );
	printf( '<option value="md"%s>Markdown</option>', selected( $tipo, 'md', false ) );
	printf( '<option value="html"%s>Página HTML</option>', selected( $tipo, 'html', false ) );
	printf( '<option value="mcp"%s>MCP (tool call)</option>', selected( $tipo, 'mcp', false ) );
	echo '</select></label>';

	echo '<label title="Verificado via assinatura HTTP Message Signatures, RFC 9421 -- Web Bot Auth">Assinado <select name="assinado">';
	printf( '<option value=""%s>Todos</option>', selected( $assinado, '', false ) );
	printf( '<option value="1"%s>Só assinados</option>', selected( $assinado, '1', false ) );
	printf( '<option value="0"%s>Só não assinados</option>', selected( $assinado, '0', false ) );
	echo '</select></label>';

	echo '<button type="submit" class="button">Filtrar</button>';
	echo '<a href="' . esc_url( $export_url ) . '" class="button button-primary">Baixar CSV do período</a>';
	echo '</form>';

	// --- Overview: card do total e serie temporal lado a lado. O flex-wrap
	// garante que em tela estreita o grafico desce pra baixo do card em vez
	// de espremer os dois. ---
	echo '<div style="display:flex;gap:16px;margin-bottom:24px;flex-wrap:wrap;align-items:stretch;">';
	printf(
		'<div style="background:#fff;border:1px solid #ccd0d4;width:180px;height:180px;padding:20px;box-sizing:border-box;display:flex;flex-direction:column;justify-content:space-between;">
			<div style="font-size:13px;color:#646970;line-height:1.4;">Requisições no período</div>
			<div style="font-size:36px;font-weight:600;line-height:1;">%d</div>
			<div style="font-size:13px;color:%s;font-weight:600;">%s <span style="color:#646970;font-weight:400;">vs. período anterior (%d)</span></div>
		</div>',
		$total_periodo,
		esc_attr( $variacao_cor ),
		esc_html( $variacao_txt ),
		$total_anterior
	);

	dsi_agentmd_render_grafico_linha( $table, $where, $params, $inicio_input, $fim_input );

	echo '</div>';

	// --- Bots: todas as linhas vao pro HTML e o JS mostra 10 por vez. Sao
	// poucas dezenas de bots distintos, entao nao compensa ida ao servidor
	// pra trocar de pagina. ---
	$total_bots_paginas = (int) max( 1, ceil( count( $top_bots ) / DSI_AGENTMD_BOTS_POR_PAGINA ) );

	echo '<h2>Principais clientes que solicitaram</h2>';
	echo '<table class="widefat striped"><thead><tr><th>Cliente</th><th title="Bot declarado se anuncia no User-Agent; ferramenta HTTP é script/monitor; não identificado é User-Agent de navegador">O que é</th><th>Total</th><th>Última vez</th></tr></thead><tbody id="dsi-bots-tbody">';
	if ( $top_bots ) {
		foreach ( $top_bots as $row ) {
			$categoria = dsi_agentmd_categoria_cliente( $row->bot_label );
			$cor       = [ 'bot' => '#2271b1', 'ferramenta' => '#8c6d1f', 'nao_identificado' => '#646970' ][ $categoria ];
			$etiqueta  = in_array( $row->bot_label, $bots_novos, true )
				? ' <span title="Primeira vez que esse cliente aparece no log inteiro cai dentro do período selecionado" style="background:#00a32a;color:#fff;font-size:10px;padding:1px 6px;border-radius:9px;vertical-align:middle;">novo</span>'
				: '';
			printf(
				'<tr class="dsi-bot-row"><td><strong>%s</strong>%s</td><td><span style="color:%s;">%s</span></td><td>%d</td><td>%s</td></tr>',
				esc_html( $row->bot_label ),
				$etiqueta,
				esc_attr( $cor ),
				esc_html( dsi_agentmd_categoria_label( $categoria ) ),
				(int) $row->total,
				esc_html( $row->ultima_vez )
			);
		}
	} else {
		echo '<tr><td colspan="4">Nenhum acesso registrado nesse período.</td></tr>';
	}
	echo '</tbody></table>';
	dsi_agentmd_render_nav( 'bots', 1, $total_bots_paginas, count( $top_bots ), 'clientes' );

	dsi_agentmd_render_tools( $top_tools );
	dsi_agentmd_render_top_posts( $top_posts );

	// --- Requisições: milhares de linhas, então a troca de página busca só
	// o pedaço no servidor (AJAX) em vez de despejar tudo no HTML. ---
	printf(
		'<h2 style="margin-top:32px;">Requisições do período — <span id="dsi-reqs-titulo">página %d de %d (%s no total)</span></h2>',
		$paged,
		$total_paginas,
		esc_html( number_format_i18n( $total_periodo ) )
	);
	echo '<table class="widefat striped"><thead><tr><th>Data</th><th>Bot</th><th title="Verificado via assinatura HTTP Message Signatures, RFC 9421 -- Web Bot Auth">Assinado</th><th>Tipo</th><th title="Nome da tool chamada, quando o tipo e MCP">Tool</th><th>Status</th><th>Post</th><th>URL</th><th>IP</th><th>País</th></tr></thead>';
	echo '<tbody id="dsi-reqs-tbody">' . dsi_agentmd_linhas_detalhe( $detalhe ) . '</tbody></table>';
	dsi_agentmd_render_nav( 'reqs', $paged, $total_paginas, $total_periodo, 'requisições' );

	echo '</div>';

	dsi_agentmd_render_script( $inicio_input, $fim_input, $bot, $tipo, $assinado, $total_paginas );
}

/**
 * Controles de paginacao de um componente. Sao <button>, nao <a>: a
 * navegacao acontece na propria tela, sem recarregar a pagina nem mexer
 * na URL -- por isso tambem nao ha href de fallback pra quem estiver sem
 * JS (nesse caso fica so a primeira pagina, que e o comportamento util).
 */
function dsi_agentmd_render_nav( string $id, int $paged, int $total_paginas, int $total_itens, string $unidade ): void {
	if ( $total_paginas <= 1 ) {
		return;
	}

	printf(
		'<p class="tablenav-pages" style="margin:10px 0 0;display:flex;align-items:center;gap:8px;float:none;">
			<button type="button" class="button" id="dsi-%1$s-ant" disabled>&laquo; Anterior</button>
			<button type="button" class="button" id="dsi-%1$s-prox">Próxima &raquo;</button>
			<span id="dsi-%1$s-info" style="color:#646970;">página %2$d de %3$d (%4$s %5$s)</span>
		</p>',
		esc_attr( $id ),
		$paged,
		$total_paginas,
		esc_html( number_format_i18n( $total_itens ) ),
		esc_html( $unidade )
	);
}

/**
 * As chamadas de tool MCP colapsavam todas em post_id=0 antes de
 * 2026-09-07 -- sem essa tabela nao dava pra saber qual tool foi
 * chamada, so que o endpoint respondia. So ocupa espaco quando ha
 * chamada MCP no periodo.
 */
function dsi_agentmd_render_tools( array $tools ): void {
	if ( ! $tools ) {
		return;
	}

	echo '<h2 style="margin-top:32px;">Tools MCP chamadas</h2>';
	echo '<table class="widefat striped"><thead><tr><th>Tool</th><th>Chamadas</th><th>Erros</th><th title="IPs de origem distintos">IPs</th><th>Última vez</th></tr></thead><tbody>';
	foreach ( $tools as $t ) {
		$erros = (int) $t->erros;
		printf(
			'<tr><td><code>%s</code></td><td>%d</td><td%s>%d</td><td>%d</td><td>%s</td></tr>',
			esc_html( $t->tool_name ),
			(int) $t->total,
			$erros > 0 ? ' style="color:#d63638;font-weight:600;"' : '',
			$erros,
			(int) $t->ips,
			esc_html( $t->ultima_vez )
		);
	}
	echo '</tbody></table>';
}

/** Top 15 posts mais buscados por bots no período -- mesmo raciocínio: os dados já existiam no log, só não tinham tela. */
function dsi_agentmd_render_top_posts( array $posts ): void {
	if ( ! $posts ) {
		return;
	}

	echo '<h2 style="margin-top:32px;">Conteúdo mais buscado por bots</h2>';
	echo '<table class="widefat striped"><thead><tr><th>Post</th><th>Requisições</th><th>Bots distintos</th><th title="Quantas dessas requisições pediram a versão .md">Em Markdown</th></tr></thead><tbody>';
	foreach ( $posts as $p ) {
		$titulo = get_the_title( (int) $p->post_id );
		printf(
			'<tr><td><a href="%s">%s</a></td><td>%d</td><td>%d</td><td>%d</td></tr>',
			esc_url( (string) get_permalink( (int) $p->post_id ) ),
			esc_html( $titulo !== '' ? $titulo : '(sem título — ID ' . (int) $p->post_id . ')' ),
			(int) $p->total,
			(int) $p->bots,
			(int) $p->md
		);
	}
	echo '</tbody></table>';
}

/** Uma linha da tabela de requisições. Compartilhada entre a carga inicial e o AJAX, pra as duas nunca divergirem. */
function dsi_agentmd_linhas_detalhe( array $rows ): string {
	if ( ! $rows ) {
		return '<tr><td colspan="10">Nenhuma requisição nesse período.</td></tr>';
	}

	$html = '';
	foreach ( $rows as $row ) {
		$post_title = $row->post_id ? get_the_title( (int) $row->post_id ) : '—';
		$html      .= sprintf(
			'<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
			esc_html( $row->requested_at ),
			esc_html( $row->bot_label ),
			$row->signed_agent ? esc_html( $row->signed_agent ) : '—',
			esc_html( dsi_agentmd_tipo_label( $row->tipo ) ),
			esc_html( $row->tool_name ?? '—' ),
			esc_html( (string) ( $row->http_status ?? '—' ) ),
			esc_html( $post_title ),
			esc_html( $row->url_path ),
			esc_html( $row->client_ip ),
			esc_html( $row->country ?? '—' )
		);
	}

	return $html;
}

function dsi_agentmd_busca_detalhe( string $table, string $where, array $params, int $paged, int $per_page ): array {
	global $wpdb;

	return (array) $wpdb->get_results(
		$wpdb->prepare(
			"SELECT requested_at, bot_label, signed_agent, tipo, tool_name, http_status, post_id, url_path, client_ip, country
			 FROM {$table}
			 WHERE {$where}
			 ORDER BY requested_at DESC
			 LIMIT %d OFFSET %d",
			array_merge( $params, [ $per_page, ( $paged - 1 ) * $per_page ] )
		)
	);
}

/**
 * Devolve uma pagina da tabela de requisicoes ja renderizada. Reaproveita
 * os mesmos leitores de filtro da pagina, entao o recorte do AJAX e
 * sempre identico ao que esta na tela.
 */
function dsi_agentmd_ajax_reqs(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( [ 'message' => 'Sem permissão.' ], 403 );
	}
	check_ajax_referer( 'dsi_agentmd_reqs' );

	global $wpdb;
	$table = $wpdb->prefix . 'ai_bot_requests';

	[ $inicio_sql, $fim_sql ] = dsi_agentmd_periodo_from_request();
	[ $where, $params ]       = dsi_agentmd_where_and_params(
		$inicio_sql,
		$fim_sql,
		dsi_agentmd_bot_from_request(),
		dsi_agentmd_tipo_from_request(),
		dsi_agentmd_assinado_from_request()
	);

	$total         = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where}", $params ) );
	$total_paginas = (int) max( 1, ceil( $total / DSI_AGENTMD_POR_PAGINA ) );
	$paged         = min( max( 1, absint( $_REQUEST['paged'] ?? 1 ) ), $total_paginas );

	wp_send_json_success(
		[
			'html'          => dsi_agentmd_linhas_detalhe( dsi_agentmd_busca_detalhe( $table, $where, $params, $paged, DSI_AGENTMD_POR_PAGINA ) ),
			'paged'         => $paged,
			'total_paginas' => $total_paginas,
			'total'         => number_format_i18n( $total ),
		]
	);
}

/**
 * JS da paginacao dos dois componentes. Inline porque o wp-admin serve
 * script-src 'unsafe-inline' (conferido no header ao vivo) e sao ~40
 * linhas -- nao vale um arquivo a parte pra isso.
 */
function dsi_agentmd_render_script( string $inicio_input, string $fim_input, string $bot, string $tipo, string $assinado, int $total_paginas ): void {
	$cfg = [
		'url'       => admin_url( 'admin-ajax.php' ),
		'nonce'     => wp_create_nonce( 'dsi_agentmd_reqs' ),
		'paginas'   => $total_paginas,
		'porPagina' => DSI_AGENTMD_BOTS_POR_PAGINA,
		'filtros'   => [
			'data_inicio' => $inicio_input,
			'data_fim'    => $fim_input,
			'bot'         => $bot,
			'tipo'        => $tipo,
			'assinado'    => $assinado,
		],
	];
	?>
	<script>
	(function () {
		var cfg = <?php echo wp_json_encode( $cfg ); ?>;

		function liga( id, aoTrocar, totalPaginas ) {
			var ant  = document.getElementById( 'dsi-' + id + '-ant' );
			var prox = document.getElementById( 'dsi-' + id + '-prox' );
			if ( ! ant || ! prox ) { return null; }

			var estado = { pagina: 1, paginas: totalPaginas };

			function ir( destino ) {
				if ( destino < 1 || destino > estado.paginas ) { return; }
				aoTrocar( destino, estado );
			}

			ant.addEventListener( 'click', function () { ir( estado.pagina - 1 ); } );
			prox.addEventListener( 'click', function () { ir( estado.pagina + 1 ); } );

			estado.sincroniza = function () {
				ant.disabled  = estado.pagina <= 1;
				prox.disabled = estado.pagina >= estado.paginas;
			};

			return estado;
		}

		// --- Bots: mostra/esconde as linhas que ja estao no HTML ---
		var linhas = [].slice.call( document.querySelectorAll( '.dsi-bot-row' ) );
		var infoBots = document.getElementById( 'dsi-bots-info' );

		var estadoBots = liga( 'bots', function ( destino, estado ) {
			estado.pagina = destino;
			pintaBots( estado );
		}, Math.max( 1, Math.ceil( linhas.length / cfg.porPagina ) ) );

		function pintaBots( estado ) {
			var de   = ( estado.pagina - 1 ) * cfg.porPagina;
			var ate  = de + cfg.porPagina;
			linhas.forEach( function ( tr, i ) { tr.hidden = ( i < de || i >= ate ); } );
			if ( infoBots ) {
				infoBots.textContent = 'página ' + estado.pagina + ' de ' + estado.paginas +
					' (' + linhas.length + ' bots)';
			}
			estado.sincroniza();
		}

		if ( estadoBots ) { pintaBots( estadoBots ); }

		// --- Requisições: busca a página no servidor ---
		var corpo    = document.getElementById( 'dsi-reqs-tbody' );
		var titulo   = document.getElementById( 'dsi-reqs-titulo' );
		var infoReqs = document.getElementById( 'dsi-reqs-info' );

		var estadoReqs = liga( 'reqs', function ( destino, estado ) {
			var q = new URLSearchParams( cfg.filtros );
			q.set( 'action', 'dsi_agentmd_reqs' );
			q.set( '_wpnonce', cfg.nonce );
			q.set( 'paged', destino );

			corpo.style.opacity = '0.45';

			fetch( cfg.url + '?' + q.toString(), { credentials: 'same-origin' } )
				.then( function ( r ) { return r.json(); } )
				.then( function ( j ) {
					corpo.style.opacity = '1';
					if ( ! j || ! j.success ) { return; }

					corpo.innerHTML = j.data.html;
					estado.pagina   = j.data.paged;
					estado.paginas  = j.data.total_paginas;

					var texto = 'página ' + j.data.paged + ' de ' + j.data.total_paginas +
						' (' + j.data.total + ' no total)';
					if ( titulo ) { titulo.textContent = texto; }
					if ( infoReqs ) { infoReqs.textContent = texto; }
					estado.sincroniza();
				} )
				.catch( function () { corpo.style.opacity = '1'; } );
		}, cfg.paginas );

		if ( estadoReqs ) { estadoReqs.sincroniza(); }
	})();
	</script>
	<?php
}

/**
 * Volume de requisições por dia do período selecionado, como linha.
 *
 * SVG inline de proposito: nada de biblioteca de grafico. O wp-admin roda
 * sob CSP e nao vale carregar dependencia externa por uma serie de uma
 * dimensao so -- alem de que SVG inline nao depende de JS pra desenhar.
 *
 * Respeita os mesmos filtros do resto da pagina (periodo, bot, tipo,
 * assinado), entao o grafico sempre mostra exatamente o recorte que esta
 * na tela.
 */
function dsi_agentmd_render_grafico_linha( string $table, string $where, array $params, string $inicio_input, string $fim_input ): void {
	global $wpdb;

	$por_dia = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT DATE(requested_at) dia, COUNT(*) total
			 FROM {$table}
			 WHERE {$where}
			 GROUP BY DATE(requested_at)",
			$params
		),
		OBJECT_K
	);

	// Preenche o calendario inteiro do periodo, inclusive dias sem nenhuma
	// requisicao. Se plotasse so o que voltou do GROUP BY, um dia vazio
	// sumiria do eixo e a linha ligaria o dia anterior direto no seguinte
	// -- escondendo justamente a queda que interessa ver.
	$serie  = [];
	$cursor = strtotime( $inicio_input );
	$fim    = strtotime( $fim_input );

	while ( $cursor <= $fim && count( $serie ) <= 400 ) {
		$dia           = gmdate( 'Y-m-d', $cursor );
		$serie[ $dia ] = isset( $por_dia[ $dia ] ) ? (int) $por_dia[ $dia ]->total : 0;
		$cursor       += DAY_IN_SECONDS;
	}

	$n = count( $serie );
	if ( $n < 2 || $n > 400 ) {
		return; // um dia so nao e serie; acima de ~1 ano vira borrao ilegivel
	}

	$pico = max( $serie );
	$topo = $pico > 0 ? $pico : 1;

	// Sistema de coordenadas fixo -- o SVG escala sozinho pra largura da
	// caixa. Formato achatado (1000x150) porque ele divide a linha com o
	// card de total, que tem 180px de altura. Fontes propositalmente
	// grandes pra escala: o viewBox de 1000 costuma ser desenhado em ~650px
	// reais, entao 14 aqui vira ~9px na tela.
	$larg   = 1000;
	$alt    = 150;
	$esq    = 48;
	$dir    = 14;
	$topo_y = 12;
	$base_y = 112;
	$area_l = $larg - $esq - $dir;
	$area_h = $base_y - $topo_y;

	$pontos = [];
	$i      = 0;
	foreach ( $serie as $dia => $valor ) {
		$x        = $esq + ( $n > 1 ? $i * ( $area_l / ( $n - 1 ) ) : 0 );
		$y        = $base_y - ( $valor / $topo ) * $area_h;
		$pontos[] = [ 'x' => round( $x, 1 ), 'y' => round( $y, 1 ), 'dia' => $dia, 'valor' => $valor ];
		$i++;
	}

	$linha = implode( ' ', array_map( static fn( $p ) => $p['x'] . ',' . $p['y'], $pontos ) );
	$area  = $pontos[0]['x'] . ',' . $base_y . ' ' . $linha . ' ' . end( $pontos )['x'] . ',' . $base_y;

	// No maximo ~8 datas no eixo X: dividindo espaco com o card, mais que
	// isso sobrepoe os rotulos.
	$passo = (int) max( 1, ceil( $n / 8 ) );

	echo '<div style="background:#fff;border:1px solid #ccd0d4;padding:16px 18px;box-sizing:border-box;height:180px;flex:1;min-width:380px;display:flex;flex-direction:column;">';
	echo '<div style="font-size:13px;color:#646970;line-height:1.4;margin-bottom:6px;">Requisições por dia</div>';
	printf( '<svg viewBox="0 0 %d %d" role="img" aria-label="Volume de requisições por dia no período selecionado" style="display:block;width:100%%;flex:1;overflow:visible;">', $larg, $alt );

	// Tooltip em CSS puro -- o wp-admin serve style-src 'unsafe-inline'
	// (conferido no header ao vivo), entao nao precisa de JS nem de lib.
	// Substitui o <title> nativo do SVG, que demora ~1s pra abrir e so
	// responde em cima do circulo de 4px: depois da escala do viewBox isso
	// vira um alvo de ~2px, que na pratica ninguem acerta.
	echo '<style>
		.dsi-pt .dsi-tip, .dsi-pt .dsi-guia { opacity: 0; transition: opacity .08s ease; }
		.dsi-pt:hover .dsi-tip, .dsi-pt:hover .dsi-guia { opacity: 1; }
		.dsi-pt:hover .dsi-dot { r: 6; fill: #2271b1; }
	</style>';

	// Grade horizontal + escala do eixo Y
	for ( $g = 0; $g <= 4; $g++ ) {
		$y     = $base_y - ( $g / 4 ) * $area_h;
		$valor = (int) round( $topo * $g / 4 );
		printf(
			'<line x1="%d" y1="%.1f" x2="%d" y2="%.1f" stroke="#e0e0e0" stroke-width="1"/>
			 <text x="%d" y="%.1f" text-anchor="end" font-size="13" fill="#646970">%s</text>',
			$esq,
			$y,
			$larg - $dir,
			$y,
			$esq - 8,
			$y + 4.5,
			esc_html( number_format_i18n( $valor ) )
		);
	}

	printf( '<polygon points="%s" fill="#2271b1" fill-opacity="0.10"/>', esc_attr( $area ) );
	printf( '<polyline points="%s" fill="none" stroke="#2271b1" stroke-width="3" stroke-linejoin="round" stroke-linecap="round"/>', esc_attr( $linha ) );

	// Largura de um dia no eixo -- vira a area de hover de cada ponto, pra
	// o tooltip abrir em qualquer lugar da coluna e nao so no circulo.
	$passo_x = $n > 1 ? $area_l / ( $n - 1 ) : $area_l;

	foreach ( $pontos as $idx => $p ) {
		$rotulo = gmdate( 'd/m', strtotime( $p['dia'] ) ) . ' — ' . number_format_i18n( $p['valor'] );
		$tw     = strlen( $rotulo ) * 8.4 + 20;
		// Prende o balao dentro da caixa: nas pontas ele desloca em vez de
		// vazar pra fora e ficar cortado pela borda do painel.
		$tx     = min( max( $p['x'] - $tw / 2, 2 ), $larg - $tw - 2 );
		// Ponto colado no topo nao tem espaco acima -- o balao vai pra baixo.
		$ty     = $p['y'] > 52 ? $p['y'] - 36 : $p['y'] + 14;

		printf(
			'<g class="dsi-pt" role="img" aria-label="%s: %s requisições">
				<rect x="%.1f" y="0" width="%.1f" height="%d" fill="transparent"/>
				<line class="dsi-guia" x1="%.1f" y1="%d" x2="%.1f" y2="%.1f" stroke="#2271b1" stroke-width="1" stroke-dasharray="3 3"/>
				<circle class="dsi-dot" cx="%.1f" cy="%.1f" r="4" fill="#fff" stroke="#2271b1" stroke-width="2.5"/>
				<g class="dsi-tip">
					<rect x="%.1f" y="%.1f" width="%.1f" height="27" rx="3" fill="#1d2327"/>
					<text x="%.1f" y="%.1f" text-anchor="middle" font-size="15" fill="#fff">%s</text>
				</g>
			</g>',
			esc_attr( gmdate( 'd/m/Y', strtotime( $p['dia'] ) ) ),
			esc_attr( number_format_i18n( $p['valor'] ) ),
			$p['x'] - $passo_x / 2,
			$passo_x,
			$alt,
			$p['x'],
			$base_y,
			$p['x'],
			$p['y'],
			$p['x'],
			$p['y'],
			$tx,
			$ty,
			$tw,
			$tx + $tw / 2,
			$ty + 19,
			esc_html( $rotulo )
		);

		if ( $idx % $passo === 0 || $idx === $n - 1 ) {
			printf(
				'<text x="%.1f" y="%d" text-anchor="middle" font-size="14" fill="#646970">%s</text>',
				$p['x'],
				$base_y + 26,
				esc_html( gmdate( 'd/m', strtotime( $p['dia'] ) ) )
			);
		}
	}

	echo '</svg>';
	echo '</div>';
}

