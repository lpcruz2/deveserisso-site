<?php
/**
 * Plugin Name: DSI — Painel de bots de IA
 * Description: Mostra em Ferramentas → Bots de IA quem está acessando o site (Markdown ou HTML).
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_menu', 'dsi_agentmd_admin_menu' );
add_action( 'admin_post_dsi_agentmd_export_csv', 'dsi_agentmd_export_csv' );

function dsi_agentmd_admin_menu(): void {
	add_management_page(
		'Bots de IA',
		'Bots de IA',
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

/**
 * Monta a clausula WHERE (periodo + bot + tipo, todos opcionais exceto
 * periodo) e os parametros pra $wpdb->prepare(), reaproveitada pelo
 * overview, pela tabela paginada e pelo export CSV -- os tres sempre
 * filtram igual.
 *
 * @return array{0: string, 1: array<int, string>}
 */
function dsi_agentmd_where_and_params( string $inicio_sql, string $fim_sql, string $bot, string $tipo = '' ): array {
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
	[ $where, $params ] = dsi_agentmd_where_and_params( $inicio_sql, $fim_sql, $bot, $tipo );

	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT requested_at, bot_label, signed_agent, tipo, post_id, url_path, user_agent, client_ip
			 FROM {$table}
			 WHERE {$where}
			 ORDER BY requested_at DESC",
			$params
		)
	);

	$sufixo_bot  = $bot !== '' ? '-' . sanitize_title( $bot ) : '';
	$sufixo_tipo = $tipo !== '' ? '-' . $tipo : '';

	nocache_headers();
	header( 'Content-Type: text/csv; charset=utf-8' );
	header( 'Content-Disposition: attachment; filename="ai-bot-requests-' . $inicio_input . '-a-' . $fim_input . $sufixo_bot . $sufixo_tipo . '.csv"' );

	$out = fopen( 'php://output', 'w' );
	fputcsv( $out, [ 'data', 'bot', 'assinado_rfc9421', 'tipo', 'post_id', 'post_titulo', 'url', 'user_agent', 'ip' ] );

	foreach ( $rows as $row ) {
		$post_title = $row->post_id ? get_the_title( (int) $row->post_id ) : '';
		fputcsv( $out, [
			$row->requested_at,
			$row->bot_label,
			$row->signed_agent ?? '',
			$row->tipo,
			$row->post_id,
			$post_title,
			$row->url_path,
			$row->user_agent,
			$row->client_ip,
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
	[ $where, $params ] = dsi_agentmd_where_and_params( $inicio_sql, $fim_sql, $bot, $tipo );

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

	// Lista de bots pro dropdown: todos os ja vistos historicamente,
	// independente do periodo filtrado, pra nao sumir opcao ao estreitar a data.
	$bots_disponiveis = $wpdb->get_col( "SELECT DISTINCT bot_label FROM {$table} ORDER BY bot_label ASC" );

	// --- Período imediatamente anterior, com a mesma duração, pra comparação ---
	$duracao_dias        = (int) ( ( strtotime( $fim_input ) - strtotime( $inicio_input ) ) / DAY_IN_SECONDS ) + 1;
	$anterior_fim_input  = gmdate( 'Y-m-d', strtotime( $inicio_input ) - DAY_IN_SECONDS );
	$anterior_ini_input  = gmdate( 'Y-m-d', strtotime( $anterior_fim_input ) - ( $duracao_dias - 1 ) * DAY_IN_SECONDS );
	$anterior_ini_sql    = $anterior_ini_input . ' 00:00:00';
	$anterior_fim_sql    = $anterior_fim_input . ' 23:59:59';

	[ $where_anterior, $params_anterior ] = dsi_agentmd_where_and_params( $anterior_ini_sql, $anterior_fim_sql, $bot, $tipo );
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

	$per_page      = 50;
	$paged         = max( 1, absint( $_GET['paged'] ?? 1 ) );
	$offset        = ( $paged - 1 ) * $per_page;
	$total_paginas = (int) max( 1, ceil( $total_periodo / $per_page ) );

	$detalhe = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT requested_at, bot_label, signed_agent, tipo, post_id, url_path, client_ip
			 FROM {$table}
			 WHERE {$where}
			 ORDER BY requested_at DESC
			 LIMIT %d OFFSET %d",
			array_merge( $params, [ $per_page, $offset ] )
		)
	);

	$export_url = wp_nonce_url(
		add_query_arg(
			[
				'action'      => 'dsi_agentmd_export_csv',
				'data_inicio' => $inicio_input,
				'data_fim'    => $fim_input,
				'bot'         => $bot,
				'tipo'        => $tipo,
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

	echo '<label>Bot <select name="bot"><option value="">Todos</option>';
	foreach ( $bots_disponiveis as $opcao ) {
		printf(
			'<option value="%s"%s>%s</option>',
			esc_attr( $opcao ),
			selected( $bot, $opcao, false ),
			esc_html( $opcao )
		);
	}
	echo '</select></label>';

	echo '<label>Tipo <select name="tipo">';
	printf( '<option value=""%s>Todos</option>', selected( $tipo, '', false ) );
	printf( '<option value="md"%s>Markdown</option>', selected( $tipo, 'md', false ) );
	printf( '<option value="html"%s>Página HTML</option>', selected( $tipo, 'html', false ) );
	printf( '<option value="mcp"%s>MCP (tool call)</option>', selected( $tipo, 'mcp', false ) );
	echo '</select></label>';

	echo '<button type="submit" class="button">Filtrar</button>';
	echo '<a href="' . esc_url( $export_url ) . '" class="button button-primary">Baixar CSV do período</a>';
	echo '</form>';

	// --- Overview ---
	echo '<div style="display:flex;gap:16px;margin-bottom:24px;flex-wrap:wrap;">';
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
	echo '</div>';

	echo '<h2>Principais bots que solicitaram</h2>';
	echo '<table class="widefat striped"><thead><tr><th>Bot</th><th>Total</th><th>Última vez</th></tr></thead><tbody>';
	if ( $top_bots ) {
		foreach ( $top_bots as $row ) {
			printf(
				'<tr><td>%s</td><td>%d</td><td>%s</td></tr>',
				esc_html( $row->bot_label ),
				(int) $row->total,
				esc_html( $row->ultima_vez )
			);
		}
	} else {
		echo '<tr><td colspan="3">Nenhum acesso registrado nesse período.</td></tr>';
	}
	echo '</tbody></table>';

	printf(
		'<h2 style="margin-top:32px;">Requisições do período — página %d de %d (%d no total)</h2>',
		$paged,
		$total_paginas,
		$total_periodo
	);
	echo '<table class="widefat striped"><thead><tr><th>Data</th><th>Bot</th><th title="Verificado via assinatura HTTP Message Signatures, RFC 9421 -- Web Bot Auth">Assinado</th><th>Tipo</th><th>Post</th><th>URL</th><th>IP</th></tr></thead><tbody>';
	if ( $detalhe ) {
		foreach ( $detalhe as $row ) {
			$post_title = $row->post_id ? get_the_title( (int) $row->post_id ) : '—';
			$assinado   = $row->signed_agent ? esc_html( $row->signed_agent ) : '—';
			printf(
				'<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
				esc_html( $row->requested_at ),
				esc_html( $row->bot_label ),
				$assinado,
				esc_html( dsi_agentmd_tipo_label( $row->tipo ) ),
				esc_html( $post_title ),
				esc_html( $row->url_path ),
				esc_html( $row->client_ip )
			);
		}
	} else {
		echo '<tr><td colspan="7">Nenhuma requisição nesse período.</td></tr>';
	}
	echo '</tbody></table>';

	dsi_agentmd_render_pagination( $paged, $total_paginas );

	echo '</div>';
}

function dsi_agentmd_render_pagination( int $paged, int $total_paginas ): void {
	if ( $total_paginas <= 1 ) {
		return;
	}

	$base_url = remove_query_arg( 'paged' );

	echo '<p class="tablenav-pages" style="margin-top:12px;">';

	if ( $paged > 1 ) {
		printf(
			'<a class="button" href="%s">&laquo; Anterior</a> ',
			esc_url( add_query_arg( 'paged', $paged - 1, $base_url ) )
		);
	}

	if ( $paged < $total_paginas ) {
		printf(
			'<a class="button" href="%s">Próxima &raquo;</a>',
			esc_url( add_query_arg( 'paged', $paged + 1, $base_url ) )
		);
	}

	echo '</p>';
}
