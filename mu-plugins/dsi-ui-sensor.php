<?php
/**
 * Plugin Name: DSI — Sensor de navegador agêntico
 * Description: Detecta se um visitante é um agente de IA pilotando o navegador
 *              (tipo computer-use/Midscene.js) via sinais de clique/timing/
 *              scroll, e mostra em Ferramentas → Navegadores agênticos.
 *
 * Avaliação que motivou isto (ver memória do projeto/CLAUDE.md): o paper
 * "Known By Their Actions" (arXiv:2605.14786) treina um classificador pesado
 * pra saber QUAL modelo está navegando -- inviável aqui sem dataset rotulado
 * próprio. Este mu-plugin resolve a pergunta anterior e mais barata: ESSE
 * TIPO de tráfego (agente pilotando um navegador de verdade, não um crawler
 * HTTP comum) sequer existe no site? Sem essa resposta não vale investir no
 * classificador.
 *
 * Design deliberado pra não virar vigilância de todo visitante: o sensor
 * (assets/dsi-ui-sensor.js) só envia o beacon quando algum sinal de
 * automação já disparou no próprio navegador (navigator.webdriver, clique
 * sem mousemove antes, timing regular demais etc.) -- pageview normal de
 * humano nunca gera requisição nem linha nesta tabela. Nunca captura o
 * caractere digitado, só classificação estrutural/imprimível e timing.
 */

defined( 'ABSPATH' ) || exit;

const DSI_UISENSOR_NAMESPACE     = 'dsi/v1';
const DSI_UISENSOR_ROUTE         = '/uitrace';
const DSI_UISENSOR_RL_LIMITE     = 20;  // beacons
const DSI_UISENSOR_RL_JANELA     = 60;  // segundos
const DSI_UISENSOR_RETENCAO_DIAS = 90;
const DSI_UISENSOR_POR_PAGINA    = 100;

// =============================================================================
// FRONT-END — injeta o sensor em toda visita pública (não em wp-admin, feed
// ou visitante logado -- o alvo é quem visita de fora, não a própria equipe).
// =============================================================================
add_action( 'wp_enqueue_scripts', 'dsi_uisensor_enqueue' );

function dsi_uisensor_enqueue(): void {
	if ( is_admin() || is_feed() || is_user_logged_in() ) {
		return;
	}

	wp_enqueue_script(
		'dsi-ui-sensor',
		content_url( 'mu-plugins/assets/dsi-ui-sensor.js' ),
		[],
		'1.0.0',
		[ 'strategy' => 'defer', 'in_footer' => true ]
	);

	// trace_id por pageview (não por sessão multi-página -- granularidade
	// escolhida pra manter o MVP simples; ver nota de arquitetura no topo).
	wp_localize_script( 'dsi-ui-sensor', 'dsiUiSensor', [
		'endpoint' => rest_url( DSI_UISENSOR_NAMESPACE . DSI_UISENSOR_ROUTE ),
		'traceId'  => wp_generate_uuid4(),
	] );
}

// =============================================================================
// INGESTÃO — rota pública (sendBeacon não permite header custom, então não
// dá pra exigir nonce aqui; rate limit por IP faz esse papel).
// =============================================================================
add_action( 'rest_api_init', 'dsi_uisensor_register_route' );

function dsi_uisensor_register_route(): void {
	register_rest_route(
		DSI_UISENSOR_NAMESPACE,
		DSI_UISENSOR_ROUTE,
		[
			'methods'             => 'POST',
			'callback'            => 'dsi_uisensor_ingest',
			'permission_callback' => '__return_true',
		]
	);
}

function dsi_uisensor_client_ip(): string {
	return function_exists( 'dsi_agentmd_client_ip' )
		? dsi_agentmd_client_ip()
		: sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '' );
}

/** Mesmo esquema de balde do dsi-api-ratelimit.php, namespace próprio. */
function dsi_uisensor_rate_limit_excedido( string $ip ): bool {
	$key   = 'dsi_uis_rl_' . md5( $ip );
	$state = get_transient( $key );
	$now   = time();

	if ( ! is_array( $state ) || $state['reset'] <= $now ) {
		$state = [ 'count' => 0, 'reset' => $now + DSI_UISENSOR_RL_JANELA ];
	}

	$state['count']++;
	set_transient( $key, $state, DSI_UISENSOR_RL_JANELA );

	return $state['count'] > DSI_UISENSOR_RL_LIMITE;
}

/** Reduz a um float sensato ou null -- protege contra lixo/overflow vindo do cliente. */
function dsi_uisensor_float( $valor, float $min = -1e9, float $max = 1e9 ): ?float {
	if ( $valor === null || ! is_numeric( $valor ) ) {
		return null;
	}
	return max( $min, min( $max, (float) $valor ) );
}

function dsi_uisensor_int( $valor, int $min = 0, int $max = 100000 ): ?int {
	if ( $valor === null || ! is_numeric( $valor ) ) {
		return null;
	}
	return max( $min, min( $max, (int) $valor ) );
}

const DSI_UISENSOR_MOTIVOS_VALIDOS = [
	'webdriver',
	'clique_sem_mousemove',
	'timing_regular_demais',
	'viewport_automacao_sem_plugins',
	'sem_idiomas',
];

/**
 * sendBeacon envia como text/plain (Blob), não application/json -- por isso
 * lê o corpo bruto em vez de $request->get_json_params(), que exige o
 * Content-Type correto pra popular os parâmetros.
 */
function dsi_uisensor_ingest( WP_REST_Request $request ) {
	$ip = dsi_uisensor_client_ip();
	if ( dsi_uisensor_rate_limit_excedido( $ip ) ) {
		return new WP_REST_Response( null, 204 );
	}

	$dados = json_decode( $request->get_body(), true );
	if ( ! is_array( $dados ) ) {
		return new WP_REST_Response( null, 204 );
	}

	$motivos = array_values( array_intersect(
		array_map( 'sanitize_text_field', (array) ( $dados['motivos'] ?? [] ) ),
		DSI_UISENSOR_MOTIVOS_VALIDOS
	) );

	// Sem motivo válido -- cliente adulterado ou beacon de outra origem.
	// Nunca deveria acontecer vindo do sensor real (ele só chama flush()
	// quando já tem pelo menos 1 motivo), então trata como ruído.
	if ( ! $motivos ) {
		return new WP_REST_Response( null, 204 );
	}

	$user_agent = sanitize_text_field( $_SERVER['HTTP_USER_AGENT'] ?? '' );

	global $wpdb;
	$wpdb->insert(
		$wpdb->prefix . 'dsi_ui_flagged_traces',
		[
			'recorded_at'                      => current_time( 'mysql' ),
			'trace_id'                          => mb_substr( sanitize_text_field( (string) ( $dados['trace_id'] ?? '' ) ), 0, 36 ),
			'url_path'                          => mb_substr( sanitize_text_field( (string) ( $dados['url_path'] ?? '' ) ), 0, 255 ),
			'user_agent'                        => mb_substr( $user_agent, 0, 255 ),
			'client_ip'                         => $ip,
			'country'                           => function_exists( 'dsi_agentmd_country' ) ? dsi_agentmd_country() : null,
			'bot_label'                         => function_exists( 'dsi_agentmd_classify_bot' ) ? dsi_agentmd_classify_bot( $user_agent ) : null,
			'heuristic_reasons'                 => implode( ',', $motivos ),
			'viewport_w'                        => dsi_uisensor_int( $dados['viewport_w'] ?? null, 0, 20000 ),
			'viewport_h'                        => dsi_uisensor_int( $dados['viewport_h'] ?? null, 0, 20000 ),
			'screen_w'                          => dsi_uisensor_int( $dados['screen_w'] ?? null, 0, 20000 ),
			'screen_h'                          => dsi_uisensor_int( $dados['screen_h'] ?? null, 0, 20000 ),
			'touch_points'                      => dsi_uisensor_int( $dados['touch_points'] ?? null, 0, 100 ),
			'plugins_count'                     => dsi_uisensor_int( $dados['plugins_count'] ?? null, 0, 1000 ),
			'languages'                         => mb_substr( sanitize_text_field( (string) ( $dados['languages'] ?? '' ) ), 0, 100 ),
			'timezone_offset_min'               => dsi_uisensor_int( $dados['timezone_offset_min'] ?? null, -1440, 1440 ),
			'hardware_concurrency'              => dsi_uisensor_int( $dados['hardware_concurrency'] ?? null, 0, 256 ),
			'navigator_webdriver'               => ! empty( $dados['navigator_webdriver'] ) ? 1 : 0,
			'n_clicks'                          => dsi_uisensor_int( $dados['n_clicks'] ?? null, 0, 5000 ),
			'n_scrolls'                         => dsi_uisensor_int( $dados['n_scrolls'] ?? null, 0, 5000 ),
			'n_keydowns'                        => dsi_uisensor_int( $dados['n_keydowns'] ?? null, 0, 5000 ),
			'n_focus'                           => dsi_uisensor_int( $dados['n_focus'] ?? null, 0, 5000 ),
			't_first_action_ms'                 => dsi_uisensor_int( $dados['t_first_action_ms'] ?? null, 0, 3600000 ),
			'mean_iei_ms'                       => dsi_uisensor_float( $dados['mean_iei_ms'] ?? null, 0, 3600000 ),
			'std_iei_ms'                        => dsi_uisensor_float( $dados['std_iei_ms'] ?? null, 0, 3600000 ),
			'p10_iei_ms'                        => dsi_uisensor_float( $dados['p10_iei_ms'] ?? null, 0, 3600000 ),
			'p90_iei_ms'                        => dsi_uisensor_float( $dados['p90_iei_ms'] ?? null, 0, 3600000 ),
			'click_x_std'                       => dsi_uisensor_float( $dados['click_x_std'] ?? null, 0, 20000 ),
			'click_y_std'                       => dsi_uisensor_float( $dados['click_y_std'] ?? null, 0, 20000 ),
			'click_top_frac'                    => dsi_uisensor_float( $dados['click_top_frac'] ?? null, 0, 1 ),
			'link_click_ratio'                  => dsi_uisensor_float( $dados['link_click_ratio'] ?? null, 0, 1 ),
			'structural_key_ratio'              => dsi_uisensor_float( $dados['structural_key_ratio'] ?? null, 0, 1 ),
			'max_scroll_pct'                    => dsi_uisensor_float( $dados['max_scroll_pct'] ?? null, 0, 100 ),
			'mean_scroll_pct'                   => dsi_uisensor_float( $dados['mean_scroll_pct'] ?? null, 0, 100 ),
			'had_mousemove_before_first_click'  => array_key_exists( 'had_mousemove_before_first_click', $dados )
				? ( $dados['had_mousemove_before_first_click'] === null ? null : ( $dados['had_mousemove_before_first_click'] ? 1 : 0 ) )
				: null,
		],
		[
			'%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s',
			'%d', '%d', '%d', '%d', '%d', '%d', '%s', '%d', '%d', '%d',
			'%d', '%d', '%d', '%d', '%d',
			'%f', '%f', '%f', '%f', '%f', '%f', '%f', '%f', '%f', '%f', '%f',
			'%d',
		]
	);

	return new WP_REST_Response( null, 204 );
}

// =============================================================================
// RETENÇÃO
// =============================================================================
add_action( 'init', 'dsi_uisensor_agenda_purga' );
add_action( 'dsi_uisensor_purga_event', 'dsi_uisensor_purga' );

function dsi_uisensor_agenda_purga(): void {
	if ( ! wp_next_scheduled( 'dsi_uisensor_purga_event' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'dsi_uisensor_purga_event' );
	}
}

function dsi_uisensor_purga(): void {
	global $wpdb;
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->prefix}dsi_ui_flagged_traces WHERE recorded_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
			DSI_UISENSOR_RETENCAO_DIAS
		)
	);
}

// =============================================================================
// PAINEL — Ferramentas → Navegadores agênticos
// =============================================================================
add_action( 'admin_menu', 'dsi_uisensor_admin_menu' );

function dsi_uisensor_admin_menu(): void {
	add_management_page(
		'Navegadores agênticos',
		'Navegadores agênticos',
		'manage_options',
		'dsi-ui-sensor',
		'dsi_uisensor_admin_page'
	);
}

function dsi_uisensor_motivo_label( string $motivo ): string {
	return [
		'webdriver'                       => 'navigator.webdriver',
		'clique_sem_mousemove'            => 'clique sem mousemove antes',
		'timing_regular_demais'           => 'timing regular demais',
		'viewport_automacao_sem_plugins'  => 'viewport de automação + sem plugins',
		'sem_idiomas'                     => 'sem idiomas declarados',
	][ $motivo ] ?? $motivo;
}

function dsi_uisensor_admin_page(): void {
	global $wpdb;
	$table = $wpdb->prefix . 'dsi_ui_flagged_traces';

	[ $inicio_sql, $fim_sql, $inicio_input, $fim_input ] = dsi_agentmd_periodo_from_request();

	$total_periodo = (int) $wpdb->get_var(
		$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE recorded_at BETWEEN %s AND %s", $inicio_sql, $fim_sql )
	);

	$por_motivo = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT heuristic_reasons, COUNT(*) AS total FROM {$table}
			 WHERE recorded_at BETWEEN %s AND %s
			 GROUP BY heuristic_reasons ORDER BY total DESC",
			$inicio_sql,
			$fim_sql
		)
	);

	$linhas = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT * FROM {$table} WHERE recorded_at BETWEEN %s AND %s
			 ORDER BY recorded_at DESC LIMIT %d",
			$inicio_sql,
			$fim_sql,
			DSI_UISENSOR_POR_PAGINA
		)
	);

	echo '<div class="wrap"><h1>Navegadores agênticos</h1>';
	echo '<p style="color:#646970;max-width:80ch;">Sessões onde o navegador do visitante disparou algum sinal de automação (ver <code>mu-plugins/dsi-ui-sensor.php</code>) -- pageview normal de humano <strong>não</strong> aparece aqui, o sensor não envia nada nesse caso. Isto responde "existe tráfego de agente pilotando navegador (tipo computer-use) no site?" antes de investir em classificar qual modelo é.</p>';

	echo '<form method="get" style="margin:16px 0;display:flex;gap:8px;align-items:end;flex-wrap:wrap;">';
	echo '<input type="hidden" name="page" value="dsi-ui-sensor">';
	echo '<label>De <input type="date" name="data_inicio" value="' . esc_attr( $inicio_input ) . '"></label>';
	echo '<label>Até <input type="date" name="data_fim" value="' . esc_attr( $fim_input ) . '"></label>';
	echo '<button type="submit" class="button">Filtrar</button>';
	echo '</form>';

	printf(
		'<div style="background:#fff;border:1px solid #ccd0d4;width:220px;padding:20px;box-sizing:border-box;margin-bottom:24px;">
			<div style="font-size:13px;color:#646970;">Sessões flagradas no período</div>
			<div style="font-size:36px;font-weight:600;">%d</div>
		</div>',
		$total_periodo
	);

	if ( $por_motivo ) {
		echo '<h2>Por combinação de motivo</h2>';
		echo '<table class="widefat striped"><thead><tr><th>Motivos</th><th>Sessões</th></tr></thead><tbody>';
		foreach ( $por_motivo as $m ) {
			$rotulos = implode( ', ', array_map( 'dsi_uisensor_motivo_label', explode( ',', $m->heuristic_reasons ) ) );
			printf( '<tr><td>%s</td><td>%d</td></tr>', esc_html( $rotulos ), (int) $m->total );
		}
		echo '</tbody></table>';
	}

	echo '<h2 style="margin-top:32px;">Sessões flagradas (' . (int) DSI_UISENSOR_POR_PAGINA . ' mais recentes)</h2>';
	echo '<table class="widefat striped"><thead><tr><th>Data</th><th>URL</th><th>Motivos</th><th>Cliente (UA)</th><th>IP</th><th>País</th><th>Cliques</th><th>Scrolls</th><th>Teclas</th><th>Viewport</th><th title="navigator.webdriver">WebDriver</th><th title="Houve mousemove antes do 1º clique?">Mousemove antes</th></tr></thead><tbody>';

	if ( ! $linhas ) {
		echo '<tr><td colspan="12">Nenhuma sessão flagrada nesse período.</td></tr>';
	}

	foreach ( $linhas as $row ) {
		$mousemove = $row->had_mousemove_before_first_click;
		$mousemove_txt = $mousemove === null ? '—' : ( $mousemove ? 'sim' : 'não' );
		$rotulos = implode( ', ', array_map( 'dsi_uisensor_motivo_label', explode( ',', $row->heuristic_reasons ) ) );

		printf(
			'<tr><td>%s</td><td><code>%s</code></td><td>%s</td><td title="%s">%s</td><td>%s</td><td>%s</td><td>%d</td><td>%d</td><td>%d</td><td>%s×%s</td><td>%s</td><td>%s</td></tr>',
			esc_html( $row->recorded_at ),
			esc_html( $row->url_path ),
			esc_html( $rotulos ),
			esc_attr( $row->user_agent ),
			esc_html( $row->bot_label ?? '—' ),
			esc_html( $row->client_ip ),
			esc_html( $row->country ?? '—' ),
			(int) $row->n_clicks,
			(int) $row->n_scrolls,
			(int) $row->n_keydowns,
			esc_html( (string) ( $row->viewport_w ?? '—' ) ),
			esc_html( (string) ( $row->viewport_h ?? '—' ) ),
			$row->navigator_webdriver ? 'sim' : 'não',
			esc_html( $mousemove_txt )
		);
	}

	echo '</tbody></table></div>';
}
