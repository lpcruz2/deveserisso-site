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

/**
 * Fracao das SESSOES (nao dos pageviews) sorteada como baseline de
 * comparacao. Sem esse denominador nao existe "% do trafego que e
 * agentico" -- so contagem bruta de deteccoes, que nao responde nada.
 *
 * Sorteio por sessao e decidido no cliente uma unica vez e guardado em
 * sessionStorage: amostrar pagina a pagina deixaria buracos no meio da
 * sessao e destruiria as features de ritmo entre paginas, que sao
 * justamente a assinatura de navegador agentico.
 *
 * 100% (bootstrap) -- decisao de 2026-09-12: com <100 visitas/dia no site,
 * 20% levaria >1 mes pra juntar amostra com margem de erro utilizavel
 * (~35-40 dias pra n=400). Em 100%, ~50-60 sessoes/dia com interacao viram
 * amostra -- ainda assim so estatistica agregada e anonima, sem PII, 90
 * dias de retencao (mesmo padrao do resto do projeto), bem menos invasivo
 * que GA4 (cookie entre sessoes, fingerprint de device) se o site ja
 * rodar isso. Revisitar depois de 2-3 semanas de baseline acumulada e
 * considerar baixar -- a amostra já coletada continua valendo, não se perde.
 */
const DSI_UISENSOR_BASELINE_RATE = 1.0;

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
		'endpoint'     => rest_url( DSI_UISENSOR_NAMESPACE . DSI_UISENSOR_ROUTE ),
		'traceId'      => wp_generate_uuid4(),
		'baselineRate' => DSI_UISENSOR_BASELINE_RATE,
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
	'movimento_mouse_sintetico',
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

	$sampled = ! empty( $dados['sampled'] );

	// Linha sem motivo só é aceita se vier da amostra de baseline -- é
	// exatamente o "tráfego normal" que forma o denominador. Sem motivo e
	// sem ser amostra significa cliente adulterado ou beacon de outra
	// origem: descarta.
	if ( ! $motivos && ! $sampled ) {
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
			'first_click_path_points'          => dsi_uisensor_int( $dados['first_click_path_points'] ?? null, 0, 1000 ),
			'first_click_straightness'         => dsi_uisensor_float( $dados['first_click_straightness'] ?? null, 0, 1000 ),
			'session_id'                       => mb_substr( sanitize_text_field( (string) ( $dados['session_id'] ?? '' ) ), 0, 36 ),
			'page_index'                       => dsi_uisensor_int( $dados['page_index'] ?? null, 0, 10000 ),
			'ms_since_prev_page'               => dsi_uisensor_int( $dados['ms_since_prev_page'] ?? null, 0, 86400000 ),
			'sampled'                          => $sampled ? 1 : 0,
			'session_degradada'                => ! empty( $dados['session_degradada'] ) ? 1 : 0,
		],
		[
			'%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s',
			'%d', '%d', '%d', '%d', '%d', '%d', '%s', '%d', '%d', '%d',
			'%d', '%d', '%d', '%d', '%d',
			'%f', '%f', '%f', '%f', '%f', '%f', '%f', '%f', '%f', '%f', '%f',
			'%d',
			'%d', '%f',
			'%s', '%d', '%d', '%d', '%d',
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
		'movimento_mouse_sintetico'       => 'movimento de mouse sintético (poucos pontos/reto demais)',
	][ $motivo ] ?? $motivo;
}

/**
 * Prevalência estimada -- a resposta pra "quantas pessoas chegam via
 * navegador agêntico".
 *
 * CALCULADA SÓ SOBRE A AMOSTRA, de propósito. As sessões flagradas fora da
 * amostra entram no banco 100%, então têm viés de seleção por construção:
 * dividir por elas daria um número inventado. Dentro do grupo sorteado às
 * cegas não há esse viés -- é o único recorte em que "quantas dispararam
 * sobre o total" significa alguma coisa.
 */
function dsi_uisensor_render_prevalencia( string $table, string $inicio_sql, string $fim_sql ): void {
	global $wpdb;

	$r = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT
			   COUNT(DISTINCT session_id) amostradas,
			   COUNT(DISTINCT CASE WHEN heuristic_reasons IS NOT NULL AND heuristic_reasons <> '' THEN session_id END) flagradas
			 FROM {$table}
			 WHERE sampled = 1 AND session_id <> '' AND recorded_at BETWEEN %s AND %s",
			$inicio_sql,
			$fim_sql
		)
	);

	$amostradas = (int) ( $r->amostradas ?? 0 );
	$flagradas  = (int) ( $r->flagradas ?? 0 );

	// Margem de erro binomial (95%) -- sem isso um "12%" vindo de 8 sessões
	// parece a mesma coisa que um "12%" vindo de 800, e não é.
	$pct    = $amostradas > 0 ? ( $flagradas / $amostradas ) * 100 : null;
	$margem = $amostradas > 0
		? 1.96 * sqrt( ( ( $flagradas / $amostradas ) * ( 1 - $flagradas / $amostradas ) ) / $amostradas ) * 100
		: null;

	$confiavel = $amostradas >= 100;

	echo '<div style="display:flex;gap:16px;margin:20px 0;flex-wrap:wrap;">';
	printf(
		'<div style="background:#fff;border:1px solid #ccd0d4;padding:20px;min-width:260px;box-sizing:border-box;">
			<div style="font-size:13px;color:#646970;">Sessões agênticas (estimativa)</div>
			<div style="font-size:36px;font-weight:600;line-height:1.2;color:%s;">%s</div>
			<div style="font-size:13px;color:#646970;">%s</div>
		</div>',
		$confiavel ? '#1d2327' : '#8c6d1f',
		$pct === null ? '—' : esc_html( sprintf( '%.1f%%', $pct ) ),
		$pct === null
			? 'sem amostra no período'
			: esc_html( sprintf( '±%.1f p.p. · %d de %d sessões sorteadas', $margem, $flagradas, $amostradas ) )
	);

	if ( ! $confiavel ) {
		printf(
			'<div style="background:#fcf9e8;border:1px solid #dba617;padding:20px;max-width:46ch;box-sizing:border-box;font-size:13px;line-height:1.5;">
				<strong>Amostra ainda pequena (%d sessões).</strong> Abaixo de ~100 sessões sorteadas a margem de erro é maior que a própria diferença que se quer medir. Trate como sinal de que a coleta está funcionando, não como número para decidir nada.
			</div>',
			$amostradas
		);
	}
	echo '</div>';
}

/**
 * Visão por sessão. A assinatura de navegador agêntico descrita pela
 * literatura (e visível nos nossos próprios testes) é "muitas páginas em
 * janela curta, ritmo constante, poucas ações por página" -- nada disso
 * aparece olhando pageview isolado, só agregando a sessão inteira.
 */
function dsi_uisensor_render_sessoes( string $table, string $inicio_sql, string $fim_sql ): void {
	global $wpdb;

	$sessoes = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT session_id,
			        MIN(recorded_at) inicio,
			        COUNT(*) paginas,
			        TIMESTAMPDIFF(SECOND, MIN(recorded_at), MAX(recorded_at)) duracao_s,
			        SUM(n_clicks) cliques, SUM(n_scrolls) scrolls,
			        AVG(ms_since_prev_page) intervalo_medio,
			        STDDEV_POP(ms_since_prev_page) intervalo_desvio,
			        MAX(sampled) sampled,
			        GROUP_CONCAT(DISTINCT NULLIF(heuristic_reasons, '')) motivos,
			        MAX(user_agent) ua, MAX(client_ip) ip, MAX(country) pais
			 FROM {$table}
			 WHERE session_id <> '' AND recorded_at BETWEEN %s AND %s
			 GROUP BY session_id
			 HAVING paginas > 1
			 ORDER BY inicio DESC
			 LIMIT 100",
			$inicio_sql,
			$fim_sql
		)
	);

	echo '<h2 style="margin-top:32px;">Sessões multipágina</h2>';
	echo '<p style="color:#646970;max-width:80ch;">Só sessões com 2+ páginas — é onde o ritmo entre páginas existe e pode ser medido. <strong>Ritmo</strong> é o desvio do intervalo dividido pela média: perto de zero significa cadência de máquina (humano varia muito mais).</p>';
	echo '<table class="widefat striped"><thead><tr><th>Início</th><th>Páginas</th><th>Duração</th><th title="Páginas por minuto">Pág/min</th><th title="Desvio do intervalo entre páginas / média. Baixo = cadência constante">Ritmo</th><th title="(cliques + scrolls) por página">Ações/pág</th><th>Motivos</th><th>Amostra</th><th>IP</th><th>País</th></tr></thead><tbody>';

	if ( ! $sessoes ) {
		echo '<tr><td colspan="10">Nenhuma sessão multipágina nesse período.</td></tr>';
	}

	foreach ( $sessoes as $s ) {
		$paginas   = (int) $s->paginas;
		$duracao   = (int) $s->duracao_s;
		$pag_min   = $duracao > 0 ? $paginas / ( $duracao / 60 ) : null;
		$media     = (float) $s->intervalo_medio;
		$cv        = $media > 0 ? (float) $s->intervalo_desvio / $media : null;
		$acoes     = ( (int) $s->cliques + (int) $s->scrolls ) / max( 1, $paginas );

		// Só destaca cadência robótica quando há amostra suficiente pra
		// isso significar algo -- com 2 páginas, 1 intervalo, CV é ruído.
		$cv_suspeito = $cv !== null && $cv < 0.35 && $paginas >= 3;

		printf(
			'<tr><td>%s</td><td>%d</td><td>%s</td><td>%s</td><td%s>%s</td><td>%.1f</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
			esc_html( $s->inicio ),
			$paginas,
			$duracao > 0 ? esc_html( sprintf( '%dm%02ds', intdiv( $duracao, 60 ), $duracao % 60 ) ) : '—',
			$pag_min !== null ? esc_html( number_format( $pag_min, 1 ) ) : '—',
			$cv_suspeito ? ' style="color:#d63638;font-weight:600;"' : '',
			$cv !== null ? esc_html( number_format( $cv, 2 ) ) : '—',
			$acoes,
			$s->motivos ? esc_html( implode( ', ', array_map( 'dsi_uisensor_motivo_label', array_unique( explode( ',', $s->motivos ) ) ) ) ) : '—',
			$s->sampled ? 'sim' : '—',
			esc_html( (string) $s->ip ),
			esc_html( $s->pais ?? '—' )
		);
	}

	echo '</tbody></table>';
}

function dsi_uisensor_admin_page(): void {
	global $wpdb;
	$table = $wpdb->prefix . 'dsi_ui_flagged_traces';

	[ $inicio_sql, $fim_sql, $inicio_input, $fim_input ] = dsi_agentmd_periodo_from_request();

	// As três consultas abaixo excluem linha de baseline (heuristic_reasons
	// vazio) de propósito -- esta tela e a tabela "Sessões flagradas" são
	// sobre DETECÇÃO. A linha de baseline sem motivo só entra no cálculo de
	// prevalência (dsi_uisensor_render_prevalencia) e na visão por sessão.
	$total_periodo = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$table}
			 WHERE heuristic_reasons <> '' AND recorded_at BETWEEN %s AND %s",
			$inicio_sql,
			$fim_sql
		)
	);

	$por_motivo = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT heuristic_reasons, COUNT(*) AS total FROM {$table}
			 WHERE heuristic_reasons <> '' AND recorded_at BETWEEN %s AND %s
			 GROUP BY heuristic_reasons ORDER BY total DESC",
			$inicio_sql,
			$fim_sql
		)
	);

	$linhas = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT * FROM {$table}
			 WHERE heuristic_reasons <> '' AND recorded_at BETWEEN %s AND %s
			 ORDER BY recorded_at DESC LIMIT %d",
			$inicio_sql,
			$fim_sql,
			DSI_UISENSOR_POR_PAGINA
		)
	);

	echo '<div class="wrap"><h1>Navegadores agênticos</h1>';
	echo '<p style="color:#646970;max-width:80ch;">Navegador agêntico (Claude no Chrome, ChatGPT Atlas, Perplexity Comet) manda <strong>User-Agent de Chrome puro</strong> — não existe detecção por header, só por comportamento. Esta tela mede isso de duas formas: <strong>detecção</strong> (sessões que dispararam algum sinal de automação, registradas 100%) e <strong>baseline</strong> (uma amostra de ' . (int) round( DSI_UISENSOR_BASELINE_RATE * 100 ) . '% das sessões, sorteada às cegas, que serve de denominador).</p>';

	dsi_uisensor_render_prevalencia( $table, $inicio_sql, $fim_sql );

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

	dsi_uisensor_render_sessoes( $table, $inicio_sql, $fim_sql );

	echo '<h2 style="margin-top:32px;">Sessões flagradas (' . (int) DSI_UISENSOR_POR_PAGINA . ' mais recentes)</h2>';
	echo '<table class="widefat striped"><thead><tr><th>Data</th><th>URL</th><th>Motivos</th><th>Cliente (UA)</th><th>IP</th><th>País</th><th>Cliques</th><th>Scrolls</th><th>Teclas</th><th>Viewport</th><th title="navigator.webdriver">WebDriver</th><th title="Houve mousemove antes do 1º clique?">Mousemove antes</th><th title="Pontos no caminho do mouse até o 1º clique">Pontos mouse</th><th title="Comprimento do caminho / distância em linha reta -- perto de 1.0 = trajetória sintética">Retidão</th></tr></thead><tbody>';

	if ( ! $linhas ) {
		echo '<tr><td colspan="14">Nenhuma sessão flagrada nesse período.</td></tr>';
	}

	foreach ( $linhas as $row ) {
		$mousemove = $row->had_mousemove_before_first_click;
		$mousemove_txt = $mousemove === null ? '—' : ( $mousemove ? 'sim' : 'não' );
		$rotulos = implode( ', ', array_map( 'dsi_uisensor_motivo_label', explode( ',', $row->heuristic_reasons ) ) );

		printf(
			'<tr><td>%s</td><td><code>%s</code></td><td>%s</td><td title="%s">%s</td><td>%s</td><td>%s</td><td>%d</td><td>%d</td><td>%d</td><td>%s×%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
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
			esc_html( $mousemove_txt ),
			esc_html( (string) ( $row->first_click_path_points ?? '—' ) ),
			$row->first_click_straightness !== null ? esc_html( number_format( (float) $row->first_click_straightness, 3 ) ) : '—'
		);
	}

	echo '</tbody></table></div>';
}
