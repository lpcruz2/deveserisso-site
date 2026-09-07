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
	fputcsv( $out, [ 'data', 'bot', 'assinado_rfc9421', 'tipo', 'tool_mcp', 'http_status', 'post_id', 'post_titulo', 'url', 'user_agent', 'ip', 'pais', 'referer' ] );

	foreach ( $rows as $row ) {
		$post_title = $row->post_id ? get_the_title( (int) $row->post_id ) : '';
		fputcsv( $out, [
			$row->requested_at,
			$row->bot_label,
			$row->signed_agent ?? '',
			$row->tipo,
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

	$per_page      = 50;
	$paged         = max( 1, absint( $_GET['paged'] ?? 1 ) );
	$offset        = ( $paged - 1 ) * $per_page;
	$total_paginas = (int) max( 1, ceil( $total_periodo / $per_page ) );

	$detalhe = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT requested_at, bot_label, signed_agent, tipo, tool_name, http_status, post_id, url_path, client_ip, country
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

	echo '<label title="Verificado via assinatura HTTP Message Signatures, RFC 9421 -- Web Bot Auth">Assinado <select name="assinado">';
	printf( '<option value=""%s>Todos</option>', selected( $assinado, '', false ) );
	printf( '<option value="1"%s>Só assinados</option>', selected( $assinado, '1', false ) );
	printf( '<option value="0"%s>Só não assinados</option>', selected( $assinado, '0', false ) );
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

	dsi_agentmd_render_grafico_linha( $table, $where, $params, $inicio_input, $fim_input );

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
	echo '<table class="widefat striped"><thead><tr><th>Data</th><th>Bot</th><th title="Verificado via assinatura HTTP Message Signatures, RFC 9421 -- Web Bot Auth">Assinado</th><th>Tipo</th><th title="Nome da tool chamada, quando o tipo e MCP">Tool</th><th>Status</th><th>Post</th><th>URL</th><th>IP</th><th>País</th></tr></thead><tbody>';
	if ( $detalhe ) {
		foreach ( $detalhe as $row ) {
			$post_title = $row->post_id ? get_the_title( (int) $row->post_id ) : '—';
			$assinado   = $row->signed_agent ? esc_html( $row->signed_agent ) : '—';
			printf(
				'<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
				esc_html( $row->requested_at ),
				esc_html( $row->bot_label ),
				$assinado,
				esc_html( dsi_agentmd_tipo_label( $row->tipo ) ),
				esc_html( $row->tool_name ?? '—' ),
				esc_html( (string) ( $row->http_status ?? '—' ) ),
				esc_html( $post_title ),
				esc_html( $row->url_path ),
				esc_html( $row->client_ip ),
				esc_html( $row->country ?? '—' )
			);
		}
	} else {
		echo '<tr><td colspan="10">Nenhuma requisição nesse período.</td></tr>';
	}
	echo '</tbody></table>';

	dsi_agentmd_render_pagination( $paged, $total_paginas );

	echo '</div>';
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

	// Sistema de coordenadas fixo -- o SVG escala sozinho pra largura da tela.
	$larg   = 1000;
	$alt    = 260;
	$esq    = 55;
	$dir    = 20;
	$topo_y = 20;
	$base_y = 210;
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

	// No maximo ~12 datas no eixo X, senao os rotulos se sobrepoem.
	$passo = (int) max( 1, ceil( $n / 12 ) );

	echo '<h2 style="margin-top:8px;">Requisições por dia</h2>';
	echo '<div style="background:#fff;border:1px solid #ccd0d4;padding:12px 16px;margin-bottom:24px;">';
	printf( '<svg viewBox="0 0 %d %d" width="100%%" height="260" role="img" aria-label="Volume de requisições por dia no período selecionado" style="display:block;overflow:visible;">', $larg, $alt );

	// Grade horizontal + escala do eixo Y
	for ( $g = 0; $g <= 4; $g++ ) {
		$y     = $base_y - ( $g / 4 ) * $area_h;
		$valor = (int) round( $topo * $g / 4 );
		printf(
			'<line x1="%d" y1="%.1f" x2="%d" y2="%.1f" stroke="#e0e0e0" stroke-width="1"/>
			 <text x="%d" y="%.1f" text-anchor="end" font-size="11" fill="#646970">%s</text>',
			$esq,
			$y,
			$larg - $dir,
			$y,
			$esq - 8,
			$y + 4,
			esc_html( number_format_i18n( $valor ) )
		);
	}

	printf( '<polygon points="%s" fill="#2271b1" fill-opacity="0.10"/>', esc_attr( $area ) );
	printf( '<polyline points="%s" fill="none" stroke="#2271b1" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round"/>', esc_attr( $linha ) );

	foreach ( $pontos as $idx => $p ) {
		printf(
			'<circle cx="%.1f" cy="%.1f" r="3.5" fill="#fff" stroke="#2271b1" stroke-width="2"><title>%s — %s requisições</title></circle>',
			$p['x'],
			$p['y'],
			esc_html( gmdate( 'd/m/Y', strtotime( $p['dia'] ) ) ),
			esc_html( number_format_i18n( $p['valor'] ) )
		);

		if ( $idx % $passo === 0 || $idx === $n - 1 ) {
			printf(
				'<text x="%.1f" y="%d" text-anchor="middle" font-size="11" fill="#646970">%s</text>',
				$p['x'],
				$base_y + 22,
				esc_html( gmdate( 'd/m', strtotime( $p['dia'] ) ) )
			);
		}
	}

	echo '</svg>';
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
