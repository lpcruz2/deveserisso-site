<?php
/**
 * Plugin Name: DSI — Análise WebMCP
 * Description: Aba dedicada em Ferramentas → WebMCP para as chamadas de tool
 *              MCP (tipo='mcp' na mesma tabela de dsi-ai-markdown.php),
 *              incluindo o corpo da resposta devolvida a quem chamou.
 */

defined( 'ABSPATH' ) || exit;

const DSI_WEBMCP_POR_PAGINA = 50;

add_action( 'admin_menu', 'dsi_webmcp_admin_menu' );
add_action( 'wp_ajax_dsi_webmcp_reqs', 'dsi_webmcp_ajax_reqs' );

function dsi_webmcp_admin_menu(): void {
	add_management_page(
		'WebMCP',
		'WebMCP',
		'manage_options',
		'dsi-webmcp',
		'dsi_webmcp_admin_page'
	);
}

function dsi_webmcp_tool_from_request(): string {
	return isset( $_GET['tool'] ) ? sanitize_text_field( wp_unslash( $_GET['tool'] ) ) : '';
}

/** '' = todos, 'erro' = so http_status != 200, 'sucesso' = so 200 */
function dsi_webmcp_status_from_request(): string {
	$status = isset( $_GET['status'] ) ? sanitize_text_field( wp_unslash( $_GET['status'] ) ) : '';
	return in_array( $status, [ 'erro', 'sucesso' ], true ) ? $status : '';
}

/**
 * Sempre restrita a tipo='mcp' -- essa aba e so sobre chamadas de tool, o
 * resto do trafego (Markdown/HTML) ja tem a tela "Bots de IA".
 *
 * @return array{0: string, 1: array<int, string>}
 */
function dsi_webmcp_where_and_params( string $inicio_sql, string $fim_sql, string $tool, string $status ): array {
	$where  = "tipo = 'mcp' AND requested_at BETWEEN %s AND %s";
	$params = [ $inicio_sql, $fim_sql ];

	if ( $tool !== '' ) {
		$where   .= ' AND tool_name = %s';
		$params[] = $tool;
	}

	if ( $status === 'erro' ) {
		$where .= ' AND http_status IS NOT NULL AND http_status <> 200';
	} elseif ( $status === 'sucesso' ) {
		$where .= ' AND http_status = 200';
	}

	return [ $where, $params ];
}

function dsi_webmcp_admin_page(): void {
	global $wpdb;
	$table = $wpdb->prefix . 'ai_bot_requests';

	[ $inicio_sql, $fim_sql, $inicio_input, $fim_input ] = dsi_agentmd_periodo_from_request();
	$tool               = dsi_webmcp_tool_from_request();
	$status             = dsi_webmcp_status_from_request();
	[ $where, $params ] = dsi_webmcp_where_and_params( $inicio_sql, $fim_sql, $tool, $status );

	$total_periodo = (int) $wpdb->get_var(
		$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where}", $params )
	);

	$total_erros = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$table} WHERE {$where} AND http_status IS NOT NULL AND http_status <> 200",
			$params
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

	// Dropdown de tools: todas as ja vistas historicamente, independente do
	// periodo filtrado -- mesmo raciocinio da lista de bots em "Bots de IA".
	$tools_disponiveis = $wpdb->get_col(
		"SELECT DISTINCT tool_name FROM {$table} WHERE tipo = 'mcp' AND tool_name IS NOT NULL ORDER BY tool_name ASC"
	);

	// --- Período imediatamente anterior, mesma duração, pra comparação ---
	$duracao_dias       = (int) ( ( strtotime( $fim_input ) - strtotime( $inicio_input ) ) / DAY_IN_SECONDS ) + 1;
	$anterior_fim_input = gmdate( 'Y-m-d', strtotime( $inicio_input ) - DAY_IN_SECONDS );
	$anterior_ini_input = gmdate( 'Y-m-d', strtotime( $anterior_fim_input ) - ( $duracao_dias - 1 ) * DAY_IN_SECONDS );
	$anterior_ini_sql   = $anterior_ini_input . ' 00:00:00';
	$anterior_fim_sql   = $anterior_fim_input . ' 23:59:59';

	[ $where_anterior, $params_anterior ] = dsi_webmcp_where_and_params( $anterior_ini_sql, $anterior_fim_sql, $tool, $status );
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

	$per_page      = DSI_WEBMCP_POR_PAGINA;
	$paged         = 1;
	$total_paginas = (int) max( 1, ceil( $total_periodo / $per_page ) );
	$detalhe       = dsi_webmcp_busca_detalhe( $table, $where, $params, $paged, $per_page );

	echo '<div class="wrap"><h1>WebMCP — chamadas de tool</h1>';
	echo '<p style="color:#646970;max-width:70ch;">Cada chamada feita por um agente na API pública (<code>/wp-json/webmcp/v1/</code>), com o corpo da resposta devolvida. Tráfego de Markdown/HTML fica na tela <a href="' . esc_url( admin_url( 'tools.php?page=dsi-ai-bots' ) ) . '">Bots de IA</a>.</p>';

	// --- Filtros ---
	echo '<form method="get" style="margin:16px 0;display:flex;gap:8px;align-items:end;flex-wrap:wrap;">';
	echo '<input type="hidden" name="page" value="dsi-webmcp">';
	echo '<label>De <input type="date" name="data_inicio" value="' . esc_attr( $inicio_input ) . '"></label>';
	echo '<label>Até <input type="date" name="data_fim" value="' . esc_attr( $fim_input ) . '"></label>';

	echo '<label>Tool <select name="tool"><option value="">Todas</option>';
	foreach ( $tools_disponiveis as $opcao ) {
		printf(
			'<option value="%s"%s>%s</option>',
			esc_attr( $opcao ),
			selected( $tool, $opcao, false ),
			esc_html( $opcao )
		);
	}
	echo '</select></label>';

	echo '<label>Status <select name="status">';
	printf( '<option value=""%s>Todos</option>', selected( $status, '', false ) );
	printf( '<option value="sucesso"%s>Só sucesso (200)</option>', selected( $status, 'sucesso', false ) );
	printf( '<option value="erro"%s>Só erro</option>', selected( $status, 'erro', false ) );
	echo '</select></label>';

	echo '<button type="submit" class="button">Filtrar</button>';
	echo '</form>';

	// --- Overview ---
	echo '<div style="display:flex;gap:16px;margin-bottom:24px;flex-wrap:wrap;align-items:stretch;">';
	printf(
		'<div style="background:#fff;border:1px solid #ccd0d4;width:180px;height:180px;padding:20px;box-sizing:border-box;display:flex;flex-direction:column;justify-content:space-between;">
			<div style="font-size:13px;color:#646970;line-height:1.4;">Chamadas no período</div>
			<div style="font-size:36px;font-weight:600;line-height:1;">%d</div>
			<div style="font-size:13px;color:%s;font-weight:600;">%s <span style="color:#646970;font-weight:400;">vs. período anterior (%d)</span></div>
		</div>',
		$total_periodo,
		esc_attr( $variacao_cor ),
		esc_html( $variacao_txt ),
		$total_anterior
	);

	printf(
		'<div style="background:#fff;border:1px solid #ccd0d4;width:180px;height:180px;padding:20px;box-sizing:border-box;display:flex;flex-direction:column;justify-content:space-between;">
			<div style="font-size:13px;color:#646970;line-height:1.4;">Erros no período</div>
			<div style="font-size:36px;font-weight:600;line-height:1;color:%s;">%d</div>
			<div style="font-size:13px;color:#646970;">%s das chamadas</div>
		</div>',
		$total_erros > 0 ? '#d63638' : '#1d2327',
		$total_erros,
		$total_periodo > 0 ? esc_html( sprintf( '%.0f%%', ( $total_erros / $total_periodo ) * 100 ) ) : '—'
	);

	dsi_agentmd_render_grafico_linha( $table, $where, $params, $inicio_input, $fim_input );

	echo '</div>';

	dsi_agentmd_render_tools( $top_tools );

	// --- Chamadas: paginadas via AJAX, corpo da resposta expansível por linha ---
	printf(
		'<h2 style="margin-top:32px;">Chamadas do período — <span id="dsi-webmcp-titulo">página %d de %d (%s no total)</span></h2>',
		$paged,
		$total_paginas,
		esc_html( number_format_i18n( $total_periodo ) )
	);
	echo '<table class="widefat striped"><thead><tr><th>Data</th><th>Tool</th><th>Status</th><th title="Verificado via assinatura HTTP Message Signatures, RFC 9421 -- Web Bot Auth">Assinado</th><th>IP</th><th>País</th><th>Resposta devolvida</th></tr></thead>';
	echo '<tbody id="dsi-webmcp-tbody">' . dsi_webmcp_linhas_detalhe( $detalhe ) . '</tbody></table>';
	dsi_agentmd_render_nav( 'webmcp', $paged, $total_paginas, $total_periodo, 'chamadas' );

	echo '</div>';

	dsi_webmcp_render_script( $inicio_input, $fim_input, $tool, $status, $total_paginas );
}

/**
 * Uma linha da tabela de chamadas. O corpo da resposta vai num <details>
 * porque mesmo truncado (ate 2000 chars nos erros) e longo demais pra
 * caber direto na celula sem quebrar o layout da tabela.
 */
function dsi_webmcp_linhas_detalhe( array $rows ): string {
	if ( ! $rows ) {
		return '<tr><td colspan="7">Nenhuma chamada nesse período.</td></tr>';
	}

	$html = '';
	foreach ( $rows as $row ) {
		$status    = (int) ( $row->http_status ?? 0 );
		$cor       = $status >= 400 ? 'color:#d63638;font-weight:600;' : ( $status === 200 ? 'color:#00a32a;' : '' );
		$resposta  = $row->response_body ?? null;
		$resumo    = $resposta ? '<details><summary style="cursor:pointer;">ver resposta</summary><pre style="white-space:pre-wrap;word-break:break-all;max-width:520px;background:#f6f7f7;padding:8px;margin-top:6px;font-size:12px;">' . esc_html( $resposta ) . '</pre></details>' : '—';

		$html .= sprintf(
			'<tr><td>%s</td><td><code>%s</code></td><td style="%s">%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
			esc_html( $row->requested_at ),
			esc_html( $row->tool_name ?? '—' ),
			esc_attr( $cor ),
			esc_html( (string) ( $row->http_status ?? '—' ) ),
			$row->signed_agent ? esc_html( $row->signed_agent ) : '—',
			esc_html( $row->client_ip ),
			esc_html( $row->country ?? '—' ),
			$resumo
		);
	}

	return $html;
}

function dsi_webmcp_busca_detalhe( string $table, string $where, array $params, int $paged, int $per_page ): array {
	global $wpdb;

	return (array) $wpdb->get_results(
		$wpdb->prepare(
			"SELECT requested_at, tool_name, http_status, signed_agent, client_ip, country, response_body
			 FROM {$table}
			 WHERE {$where}
			 ORDER BY requested_at DESC
			 LIMIT %d OFFSET %d",
			array_merge( $params, [ $per_page, ( $paged - 1 ) * $per_page ] )
		)
	);
}

function dsi_webmcp_ajax_reqs(): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( [ 'message' => 'Sem permissão.' ], 403 );
	}
	check_ajax_referer( 'dsi_webmcp_reqs' );

	global $wpdb;
	$table = $wpdb->prefix . 'ai_bot_requests';

	[ $inicio_sql, $fim_sql ] = dsi_agentmd_periodo_from_request();
	[ $where, $params ]       = dsi_webmcp_where_and_params(
		$inicio_sql,
		$fim_sql,
		dsi_webmcp_tool_from_request(),
		dsi_webmcp_status_from_request()
	);

	$total         = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where}", $params ) );
	$total_paginas = (int) max( 1, ceil( $total / DSI_WEBMCP_POR_PAGINA ) );
	$paged         = min( max( 1, absint( $_REQUEST['paged'] ?? 1 ) ), $total_paginas );

	wp_send_json_success(
		[
			'html'          => dsi_webmcp_linhas_detalhe( dsi_webmcp_busca_detalhe( $table, $where, $params, $paged, DSI_WEBMCP_POR_PAGINA ) ),
			'paged'         => $paged,
			'total_paginas' => $total_paginas,
			'total'         => number_format_i18n( $total ),
		]
	);
}

/** Mesmo padrão de paginação AJAX de dsi-ai-bots-admin.php, endpoint próprio. */
function dsi_webmcp_render_script( string $inicio_input, string $fim_input, string $tool, string $status, int $total_paginas ): void {
	$cfg = [
		'url'     => admin_url( 'admin-ajax.php' ),
		'nonce'   => wp_create_nonce( 'dsi_webmcp_reqs' ),
		'paginas' => $total_paginas,
		'filtros' => [
			'data_inicio' => $inicio_input,
			'data_fim'    => $fim_input,
			'tool'        => $tool,
			'status'      => $status,
		],
	];
	?>
	<script>
	(function () {
		var cfg   = <?php echo wp_json_encode( $cfg ); ?>;
		var ant    = document.getElementById( 'dsi-webmcp-ant' );
		var prox   = document.getElementById( 'dsi-webmcp-prox' );
		var corpo  = document.getElementById( 'dsi-webmcp-tbody' );
		var titulo = document.getElementById( 'dsi-webmcp-titulo' );
		var info   = document.getElementById( 'dsi-webmcp-info' );
		if ( ! ant || ! prox || ! corpo ) { return; }

		var estado = { pagina: 1, paginas: cfg.paginas };

		function sincroniza() {
			ant.disabled  = estado.pagina <= 1;
			prox.disabled = estado.pagina >= estado.paginas;
		}

		function ir( destino ) {
			if ( destino < 1 || destino > estado.paginas ) { return; }

			var q = new URLSearchParams( cfg.filtros );
			q.set( 'action', 'dsi_webmcp_reqs' );
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
					if ( info ) { info.textContent = texto; }
					sincroniza();
				} )
				.catch( function () { corpo.style.opacity = '1'; } );
		}

		ant.addEventListener( 'click', function () { ir( estado.pagina - 1 ); } );
		prox.addEventListener( 'click', function () { ir( estado.pagina + 1 ); } );
		sincroniza();
	})();
	</script>
	<?php
}
