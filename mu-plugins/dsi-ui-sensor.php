<?php
/**
 * Plugin Name: DSI — Sensor de navegador agêntico
 * Description: Mede se um visitante é um agente de IA pilotando o navegador
 *              (tipo computer-use/Midscene.js) via sinais de clique/timing/
 *              scroll, e mostra em Ferramentas → Navegadores agênticos.
 *
 * Avaliação que motivou isto: o paper "Known By Their Actions"
 * (arXiv:2605.14786) treina um classificador pesado pra saber QUAL modelo
 * está navegando -- inviável aqui sem dataset rotulado próprio. Este
 * mu-plugin resolve a pergunta anterior e mais barata: ESSE TIPO de tráfego
 * (agente pilotando um navegador de verdade, não um crawler HTTP comum)
 * sequer existe no site? Sem essa resposta não vale investir no classificador.
 *
 * ---------------------------------------------------------------------------
 * VOLUME DE COLETA -- leia antes de assumir que isto é "amostragem seletiva".
 *
 * O sensor envia beacon em dois casos: (a) algum sinal de automação disparou,
 * (b) a sessão caiu na amostra de baseline. Com
 * DSI_UISENSOR_BASELINE_RATE = 1.0 (padrão atual, decisão de bootstrap por
 * causa do baixo tráfego do site), o caso (b) vale para TODAS as sessões --
 * ou seja, hoje toda visita com qualquer interação grava uma linha, com IP,
 * User-Agent, caminho da URL, viewport, fuso e timing.
 *
 * Isso é coleta analítica de tráfego, comparável a um GA4 mais enxuto, NÃO
 * "só quem parece bot deixa rastro". A frase antiga do cabeçalho dizia o
 * contrário e estava errada. Para voltar ao regime seletivo, baixe
 * BASELINE_RATE (ex.: 0.2) -- aí sim o pageview humano fora do sorteio não
 * gera requisição nenhuma.
 *
 * IP: retenção em duas camadas (ver dsi_uisensor_purga). `client_ip` bruto
 * vive DSI_UISENSOR_IP_RAW_DIAS dias, o suficiente pra investigar uma sessão
 * suspeita à mão; `ip_hash` (HMAC com salt do site, rotativo) vive os
 * DSI_UISENSOR_RETENCAO_DIAS e é o que sustenta agrupamento por origem. IP é
 * dado pessoal sob LGPD -- o hash não elimina isso, só reduz a exposição na
 * janela longa.
 * ---------------------------------------------------------------------------
 *
 * Rate limit em 90 beacons/60s desde 2026-09-12: teste ao vivo com Claude no
 * Chrome navegando 20 páginas reais mostrou só 1 de 20 beacons chegando na
 * tabela com o limite antigo (20/60s) -- não era bug do sensor. A chave do
 * balde é o IP do EDGE da Cloudflare, compartilhado por qualquer tráfego que
 * caia no mesmo edge no mesmo minuto, então uma única sessão de navegação
 * rápida (o próprio padrão de um agente) esgotava o balde sozinha.
 */

defined( 'ABSPATH' ) || exit;

const DSI_UISENSOR_NAMESPACE     = 'dsi/v1';
const DSI_UISENSOR_ROUTE         = '/uitrace';
const DSI_UISENSOR_RL_LIMITE     = 90;  // beacons
const DSI_UISENSOR_RL_JANELA     = 60;  // segundos
const DSI_UISENSOR_RETENCAO_DIAS = 90;  // vida da linha inteira
const DSI_UISENSOR_IP_RAW_DIAS   = 7;   // vida do client_ip BRUTO dentro da linha
const DSI_UISENSOR_POR_PAGINA    = 100;

/**
 * IPs da própria equipe/máquina de teste. Linhas vindas daqui recebem
 * is_dev_traffic = 1 e ficam fora dos cálculos do painel por padrão.
 *
 * Existe pra NÃO precisar guardar IP bruto de todo visitante por 90 dias só
 * pra reconhecer o próprio tráfego de teste depois (foi o que aconteceu no
 * cruzamento H4: 1 dos 3 IPs "sobrepostos" era a máquina de desenvolvimento).
 *
 * Alternativa sem configurar nada, e a recomendada: abra o site uma vez com
 * ?dsi_debug=1 -- o sensor marca aquele navegador como dev em localStorage,
 * de forma permanente (?dsi_debug=0 limpa). Não é credencial, não concede
 * nada: só pede pra ser ignorado nas contas.
 *
 * Se preencher a lista, defina em wp-config.php e não aqui:
 *
 *   define( 'DSI_UISENSOR_DEV_IPS_EXTRA', '203.0.113.7,203.0.113.8' );
 *
 * IP residencial é dado pessoal e este arquivo vai pro controle de versão --
 * commitar o próprio IP contradiz toda a seção de retenção acima.
 */
const DSI_UISENSOR_DEV_IPS = [];

/** Lista efetiva: constante do arquivo + o que vier de wp-config.php. */
function dsi_uisensor_dev_ips(): array {
	$extra = defined( 'DSI_UISENSOR_DEV_IPS_EXTRA' )
		? array_filter( array_map( 'trim', explode( ',', (string) DSI_UISENSOR_DEV_IPS_EXTRA ) ) )
		: [];

	return array_merge( DSI_UISENSOR_DEV_IPS, $extra );
}

/**
 * Fracao das SESSOES (nao dos pageviews) sorteada como baseline de
 * comparacao. Sem esse denominador nao existe "% do trafego que e agentico"
 * -- so contagem bruta de deteccoes, que nao responde nada.
 *
 * Sorteio por sessao e decidido no cliente uma unica vez e guardado em
 * sessionStorage: amostrar pagina a pagina deixaria buracos no meio da
 * sessao e destruiria as features de ritmo entre paginas.
 *
 * 1.0 (bootstrap) -- decisao de 2026-09-12: com <100 visitas/dia, 20%
 * levaria >1 mes pra juntar amostra com margem de erro utilizavel. Ver a
 * nota de VOLUME DE COLETA no topo: em 1.0 a coleta NAO e seletiva.
 * Revisitar depois de 2-3 semanas -- a amostra ja coletada continua valendo.
 */
const DSI_UISENSOR_BASELINE_RATE = 1.0;

// =============================================================================
// FRONT-END — injeta o sensor em toda visita pública (não em wp-admin, feed
// ou visitante logado -- o alvo é quem visita de fora, não a própria equipe).
//
// Impresso direto no wp_footer (não via wp_enqueue_script) de propósito,
// achado ao vivo em 2026-09-12: o script sumia (sem erro, sem log, só
// ausência de dado) em 6 de 9 posts testados. Já descartei regra do Asset
// CleanUp e cache (LiteSpeed e Cloudflare ambos MISS/DYNAMIC nas páginas
// quebradas) -- a causa exata dentro do pipeline de otimização de JS não foi
// identificada, mas qualquer mecanismo de terceiro que só enxerga scripts
// passando pela fila padrão do WP some de cena imprimindo direto como HTML.
//
// ATENÇÃO: o HTML deste rodapé É CACHEADO. Nada que precise ser único por
// visitante pode nascer aqui -- foi exatamente o bug do session_id em v1.
// =============================================================================
add_action( 'wp_footer', 'dsi_uisensor_print_inline', 20 );

function dsi_uisensor_print_inline(): void {
	if ( is_admin() || is_feed() || is_user_logged_in() ) {
		return;
	}

	$path = __DIR__ . '/assets/dsi-ui-sensor.js';
	$js   = @file_get_contents( $path );
	if ( $js === false ) {
		return;
	}

	// traceId aqui é o id do RENDER, não do pageview nem da sessão: como este
	// HTML é cacheável, ele pode chegar idêntico a muitos visitantes. É
	// gravado como render_id justamente pra tornar isso mensurável (duas
	// linhas com o mesmo render_id e session_id diferentes = cache HIT).
	$config = [
		'endpoint'     => rest_url( DSI_UISENSOR_NAMESPACE . DSI_UISENSOR_ROUTE ),
		'traceId'      => wp_generate_uuid4(),
		'baselineRate' => DSI_UISENSOR_BASELINE_RATE,
	];

	echo "\n<script id=\"dsi-ui-sensor-inline\">\n";
	echo 'var dsiUiSensor = ' . wp_json_encode( $config ) . ";\n";
	echo $js;
	echo "\n</script>\n";
}

// =============================================================================
// IDENTIDADE DE ORIGEM — duas camadas
// =============================================================================

/**
 * Salt do HMAC de IP. Fica em option, gerado sozinho na primeira vez, e é
 * rotacionado junto com a purga de retenção: depois da rotação, os hashes
 * antigos deixam de ser comparáveis com qualquer IP novo -- ou seja, o
 * histórico envelhece pra um identificador órfão em vez de continuar
 * vinculável indefinidamente.
 *
 * `epoch` é gravado na linha porque hash de epochs diferentes NÃO pode ser
 * comparado: agrupar por ip_hash sem filtrar epoch produz falsa separação.
 */
function dsi_uisensor_ip_salt(): array {
	$estado = get_option( 'dsi_uisensor_ip_salt' );

	if ( ! is_array( $estado ) || empty( $estado['salt'] ) ) {
		$estado = [
			'salt'   => wp_generate_password( 64, true, true ),
			'epoch'  => 1,
			'criado' => time(),
		];
		update_option( 'dsi_uisensor_ip_salt', $estado, false );
	}

	return $estado;
}

function dsi_uisensor_rotaciona_salt(): void {
	$estado = dsi_uisensor_ip_salt();
	$idade  = time() - (int) ( $estado['criado'] ?? 0 );

	if ( $idade < DSI_UISENSOR_RETENCAO_DIAS * DAY_IN_SECONDS ) {
		return;
	}

	update_option(
		'dsi_uisensor_ip_salt',
		[
			'salt'   => wp_generate_password( 64, true, true ),
			'epoch'  => (int) ( $estado['epoch'] ?? 1 ) + 1,
			'criado' => time(),
		],
		false
	);
}

function dsi_uisensor_ip_hash( string $ip ): string {
	if ( $ip === '' ) {
		return '';
	}
	$estado = dsi_uisensor_ip_salt();
	return hash_hmac( 'sha256', $ip, (string) $estado['salt'] );
}

/**
 * Prefixo de rede (/24 em IPv4, /48 em IPv6). Sobrevive os 90 dias junto com
 * o hash: serve pra ver "veio da mesma rede/ASN" sem apontar um domicílio, e
 * continua legível por humano depois que o IP bruto já foi apagado.
 */
function dsi_uisensor_ip_prefix( string $ip ): string {
	if ( $ip === '' ) {
		return '';
	}

	if ( strpos( $ip, ':' ) !== false ) {
		$bin = @inet_pton( $ip );
		if ( $bin === false || strlen( $bin ) !== 16 ) {
			return '';
		}
		$mask = str_repeat( "\xff", 6 ) . str_repeat( "\x00", 10 );
		$rede = @inet_ntop( $bin & $mask );
		return $rede === false ? '' : $rede . '/48';
	}

	$partes = explode( '.', $ip );
	if ( count( $partes ) !== 4 ) {
		return '';
	}
	return $partes[0] . '.' . $partes[1] . '.' . $partes[2] . '.0/24';
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

/** Reduz a um float sensato ou null -- protege contra lixo/overflow do cliente. */
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

function dsi_uisensor_bool_ou_null( array $dados, string $chave ): ?int {
	if ( ! array_key_exists( $chave, $dados ) || $dados[ $chave ] === null ) {
		return null;
	}
	return $dados[ $chave ] ? 1 : 0;
}

function dsi_uisensor_texto( $valor, int $tamanho ): string {
	return mb_substr( sanitize_text_field( (string) $valor ), 0, $tamanho );
}

/**
 * Motivos aceitos. Os três `*_nao_confiavel` substituíram o antigo
 * `evento_nao_confiavel` na v2 do ruleset -- o nome antigo fica na lista só
 * para as linhas já gravadas continuarem legíveis no painel; nenhuma linha
 * nova o emite.
 */
const DSI_UISENSOR_MOTIVOS_VALIDOS = [
	'webdriver',
	'clique_sem_mousemove',
	'timing_regular_demais',
	'viewport_automacao_sem_plugins',
	'sem_idiomas',
	'movimento_mouse_sintetico',
	'clique_duracao_impossivel',
	'digitacao_impossivel',
	'scroll_multiplo_viewport',
	'clique_nao_confiavel',
	'tecla_nao_confiavel',
	'input_nao_confiavel',
	'evento_nao_confiavel', // legado (ruleset v1)
];

/**
 * sendBeacon envia como text/plain (Blob), não application/json -- por isso
 * lê o corpo bruto em vez de $request->get_json_params(), que exige o
 * Content-Type correto pra popular os parâmetros.
 */
function dsi_uisensor_ingest( WP_REST_Request $request ) {
	$ip = dsi_uisensor_client_ip();
	if ( dsi_uisensor_rate_limit_excedido( $ip ) ) {
		// Descarte silencioso -- contabilizado pra o painel poder avisar que
		// o denominador do período está incompleto.
		dsi_uisensor_conta_descarte();
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
	// exatamente o "tráfego normal" que forma o denominador. Sem motivo e sem
	// ser amostra significa cliente adulterado ou beacon de outra origem.
	if ( ! $motivos && ! $sampled ) {
		return new WP_REST_Response( null, 204 );
	}

	$user_agent = sanitize_text_field( $_SERVER['HTTP_USER_AGENT'] ?? '' );
	$salt       = dsi_uisensor_ip_salt();

	$is_dev = ! empty( $dados['dev_traffic'] ) || in_array( $ip, dsi_uisensor_dev_ips(), true );

	// Mapa coluna => [formato, valor]. Em v1 isto era um array de valores e um
	// array de formatos paralelos, com 47 posições que precisavam bater na
	// ordem -- classe de bug caro e silencioso. Aqui os dois nascem juntos.
	$campos = [
		'recorded_at'                      => [ '%s', current_time( 'mysql' ) ],
		'trace_id'                         => [ '%s', dsi_uisensor_texto( $dados['trace_id'] ?? '', 36 ) ],
		'render_id'                        => [ '%s', dsi_uisensor_texto( $dados['render_id'] ?? '', 36 ) ],
		'session_id'                       => [ '%s', dsi_uisensor_texto( $dados['session_id'] ?? '', 36 ) ],
		'page_index'                       => [ '%d', dsi_uisensor_int( $dados['page_index'] ?? null, 0, 10000 ) ],
		'ms_since_prev_page'               => [ '%d', dsi_uisensor_int( $dados['ms_since_prev_page'] ?? null, 0, 86400000 ) ],
		'sampled'                          => [ '%d', $sampled ? 1 : 0 ],
		'session_degradada'                => [ '%d', ! empty( $dados['session_degradada'] ) ? 1 : 0 ],
		'is_dev_traffic'                   => [ '%d', $is_dev ? 1 : 0 ],
		// Taxa vigente na gravação: sem ela, mudar BASELINE_RATE torna as taxas
		// de dois períodos incomparáveis sem jeito de descobrir isso depois.
		'baseline_rate'                    => [ '%f', DSI_UISENSOR_BASELINE_RATE ],
		'url_path'                         => [ '%s', dsi_uisensor_texto( $dados['url_path'] ?? '', 255 ) ],
		'user_agent'                       => [ '%s', mb_substr( $user_agent, 0, 255 ) ],
		'client_ip'                        => [ '%s', $ip ],
		'ip_hash'                          => [ '%s', dsi_uisensor_ip_hash( $ip ) ],
		'ip_prefix'                        => [ '%s', dsi_uisensor_ip_prefix( $ip ) ],
		'ip_salt_epoch'                    => [ '%d', (int) ( $salt['epoch'] ?? 1 ) ],
		'country'                          => [ '%s', function_exists( 'dsi_agentmd_country' ) ? dsi_agentmd_country() : null ],
		'bot_label'                        => [ '%s', function_exists( 'dsi_agentmd_classify_bot' ) ? dsi_agentmd_classify_bot( $user_agent ) : null ],
		'heuristic_reasons'                => [ '%s', implode( ',', $motivos ) ],
		'viewport_w'                       => [ '%d', dsi_uisensor_int( $dados['viewport_w'] ?? null, 0, 20000 ) ],
		'viewport_h'                       => [ '%d', dsi_uisensor_int( $dados['viewport_h'] ?? null, 0, 20000 ) ],
		'screen_w'                         => [ '%d', dsi_uisensor_int( $dados['screen_w'] ?? null, 0, 20000 ) ],
		'screen_h'                         => [ '%d', dsi_uisensor_int( $dados['screen_h'] ?? null, 0, 20000 ) ],
		'touch_points'                     => [ '%d', dsi_uisensor_int( $dados['touch_points'] ?? null, 0, 100 ) ],
		'plugins_count'                    => [ '%d', dsi_uisensor_int( $dados['plugins_count'] ?? null, 0, 1000 ) ],
		'languages'                        => [ '%s', dsi_uisensor_texto( $dados['languages'] ?? '', 100 ) ],
		'timezone_offset_min'              => [ '%d', dsi_uisensor_int( $dados['timezone_offset_min'] ?? null, -1440, 1440 ) ],
		'hardware_concurrency'             => [ '%d', dsi_uisensor_int( $dados['hardware_concurrency'] ?? null, 0, 256 ) ],
		'navigator_webdriver'              => [ '%d', ! empty( $dados['navigator_webdriver'] ) ? 1 : 0 ],
		'n_clicks'                         => [ '%d', dsi_uisensor_int( $dados['n_clicks'] ?? null, 0, 5000 ) ],
		'n_scrolls'                        => [ '%d', dsi_uisensor_int( $dados['n_scrolls'] ?? null, 0, 5000 ) ],
		'n_keydowns'                       => [ '%d', dsi_uisensor_int( $dados['n_keydowns'] ?? null, 0, 5000 ) ],
		'n_focus'                          => [ '%d', dsi_uisensor_int( $dados['n_focus'] ?? null, 0, 5000 ) ],
		'n_inputs'                         => [ '%d', dsi_uisensor_int( $dados['n_inputs'] ?? null, 0, 5000 ) ],
		'n_untrusted_click'                => [ '%d', dsi_uisensor_int( $dados['n_untrusted_click'] ?? null, 0, 5000 ) ],
		'n_untrusted_key'                  => [ '%d', dsi_uisensor_int( $dados['n_untrusted_key'] ?? null, 0, 5000 ) ],
		'n_untrusted_input'                => [ '%d', dsi_uisensor_int( $dados['n_untrusted_input'] ?? null, 0, 5000 ) ],
		't_first_action_ms'                => [ '%d', dsi_uisensor_int( $dados['t_first_action_ms'] ?? null, 0, 3600000 ) ],
		'mean_iei_ms'                      => [ '%f', dsi_uisensor_float( $dados['mean_iei_ms'] ?? null, 0, 3600000 ) ],
		'std_iei_ms'                       => [ '%f', dsi_uisensor_float( $dados['std_iei_ms'] ?? null, 0, 3600000 ) ],
		'p10_iei_ms'                       => [ '%f', dsi_uisensor_float( $dados['p10_iei_ms'] ?? null, 0, 3600000 ) ],
		'p90_iei_ms'                       => [ '%f', dsi_uisensor_float( $dados['p90_iei_ms'] ?? null, 0, 3600000 ) ],
		'mean_iei_acao_ms'                 => [ '%f', dsi_uisensor_float( $dados['mean_iei_acao_ms'] ?? null, 0, 3600000 ) ],
		'std_iei_acao_ms'                  => [ '%f', dsi_uisensor_float( $dados['std_iei_acao_ms'] ?? null, 0, 3600000 ) ],
		'click_x_std'                      => [ '%f', dsi_uisensor_float( $dados['click_x_std'] ?? null, 0, 20000 ) ],
		'click_y_std'                      => [ '%f', dsi_uisensor_float( $dados['click_y_std'] ?? null, 0, 20000 ) ],
		'click_top_frac'                   => [ '%f', dsi_uisensor_float( $dados['click_top_frac'] ?? null, 0, 1 ) ],
		'link_click_ratio'                 => [ '%f', dsi_uisensor_float( $dados['link_click_ratio'] ?? null, 0, 1 ) ],
		'structural_key_ratio'             => [ '%f', dsi_uisensor_float( $dados['structural_key_ratio'] ?? null, 0, 1 ) ],
		'max_scroll_pct'                   => [ '%f', dsi_uisensor_float( $dados['max_scroll_pct'] ?? null, 0, 100 ) ],
		'mean_scroll_pct'                  => [ '%f', dsi_uisensor_float( $dados['mean_scroll_pct'] ?? null, 0, 100 ) ],
		'had_mousemove_before_first_click' => [ '%d', dsi_uisensor_bool_ou_null( $dados, 'had_mousemove_before_first_click' ) ],
		'first_click_path_points'          => [ '%d', dsi_uisensor_int( $dados['first_click_path_points'] ?? null, 0, 1000 ) ],
		'first_click_straightness'         => [ '%f', dsi_uisensor_float( $dados['first_click_straightness'] ?? null, 0, 1000 ) ],
		// SEM clamp em 0: dwell negativo é evidência de timeStamp inconsistente
		// de evento sintético. Clampar em 0 fazia isso passar o teste de
		// "< 20ms" e virar detecção por artefato, não por medição.
		'first_click_dwell_ms'             => [ '%f', dsi_uisensor_float( $dados['first_click_dwell_ms'] ?? null, -60000, 60000 ) ],
		'mean_key_dwell_ms'                => [ '%f', dsi_uisensor_float( $dados['mean_key_dwell_ms'] ?? null, 0, 60000 ) ],
		'max_scroll_px'                    => [ '%d', dsi_uisensor_int( $dados['max_scroll_px'] ?? null, 0, 10000000 ) ],
		'doc_scroll_max_px'                => [ '%d', dsi_uisensor_int( $dados['doc_scroll_max_px'] ?? null, 0, 10000000 ) ],
		'n_scroll_stops'                   => [ '%d', dsi_uisensor_int( $dados['n_scroll_stops'] ?? null, 0, 1000 ) ],
		'n_scroll_stops_multiplo'          => [ '%d', dsi_uisensor_int( $dados['n_scroll_stops_multiplo'] ?? null, 0, 1000 ) ],
		'ruleset_version'                  => [ '%d', dsi_uisensor_int( $dados['ruleset_version'] ?? null, 0, 1000 ) ],
	];

	$valores  = [];
	$formatos = [];
	foreach ( $campos as $coluna => $par ) {
		$formatos[]        = $par[0];
		$valores[ $coluna ] = $par[1];
	}

	global $wpdb;
	$ok = $wpdb->insert( $wpdb->prefix . 'dsi_ui_flagged_traces', $valores, $formatos );

	// Falha de insert era silenciosa: se a tabela não existe (instalação que
	// não rodou o schema.sql) ou uma coluna falta (migração pendente), o dado
	// desaparecia sem sinal nenhum.
	if ( $ok === false ) {
		dsi_uisensor_log_falha( $wpdb->last_error );
	}

	return new WP_REST_Response( null, 204 );
}

/** Contador de beacons descartados por rate limit, por dia (só números). */
function dsi_uisensor_conta_descarte(): void {
	$chave = 'dsi_uisensor_descartes_' . current_time( 'Y-m-d' );
	$atual = (int) get_option( $chave, 0 );
	update_option( $chave, $atual + 1, false );
}

function dsi_uisensor_log_falha( string $erro ): void {
	update_option(
		'dsi_uisensor_ultima_falha',
		[ 'quando' => current_time( 'mysql' ), 'erro' => mb_substr( $erro, 0, 500 ) ],
		false
	);
}

// =============================================================================
// RETENÇÃO — duas camadas
// =============================================================================
add_action( 'init', 'dsi_uisensor_agenda_purga' );
add_action( 'dsi_uisensor_purga_event', 'dsi_uisensor_purga' );

function dsi_uisensor_agenda_purga(): void {
	if ( ! wp_next_scheduled( 'dsi_uisensor_purga_event' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'dsi_uisensor_purga_event' );
	}
}

/**
 * Corte calculado em PHP na MESMA base de tempo em que recorded_at é gravado
 * (current_time = hora local do site). A versão anterior comparava com NOW()
 * do MySQL, que pode estar em outro fuso -- desalinhamento silencioso na
 * janela de purga.
 */
function dsi_uisensor_corte( int $dias ): string {
	return gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - $dias * DAY_IN_SECONDS );
}

function dsi_uisensor_purga(): void {
	global $wpdb;
	$table = $wpdb->prefix . 'dsi_ui_flagged_traces';

	// Camada 1 — IP bruto sai em 7 dias, a linha continua. `ip_hash`,
	// `ip_prefix` e `country` seguem sustentando agrupamento e investigação
	// grosseira sem manter o identificador direto.
	$wpdb->query(
		$wpdb->prepare(
			"UPDATE {$table} SET client_ip = '' WHERE client_ip <> '' AND recorded_at < %s",
			dsi_uisensor_corte( DSI_UISENSOR_IP_RAW_DIAS )
		)
	);

	// Camada 2 — linha inteira sai em 90 dias.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$table} WHERE recorded_at < %s",
			dsi_uisensor_corte( DSI_UISENSOR_RETENCAO_DIAS )
		)
	);

	// Salt novo depois da purga: os hashes que sobraram deixam de ser
	// vinculáveis a qualquer IP observado daqui pra frente.
	dsi_uisensor_rotaciona_salt();
}

/**
 * Backfill do ip_hash nas linhas antigas (v1), usando o MESMO HMAC e o MESMO
 * salt que a ingestao usa -- e por isso que isto e PHP e nao SQL: SHA2() do
 * MySQL nao e HMAC, e um backfill com SHA2(CONCAT(salt, ip)) criaria um
 * espaco de identificadores incompativel com todas as linhas novas, o que e
 * pior que nao ter hash nenhum (parece comparavel e nao e).
 *
 * URGENCIA: so funciona enquanto client_ip ainda existe. Depois da purga de
 * DSI_UISENSOR_IP_RAW_DIAS dias nao ha mais o que hashear, e as sessoes
 * contaminadas por colisao de id SAEM DA QUARENTENA (COUNT(DISTINCT ...)
 * passa a ver uma unica origem vazia) e voltam silenciosamente pras contas.
 *
 * Rode uma vez: wp eval 'dsi_uisensor_backfill_ip_hash();'
 *
 * @return array{processadas:int,restantes:int}
 */
function dsi_uisensor_backfill_ip_hash( int $lote = 500 ): array {
	global $wpdb;
	$table = $wpdb->prefix . 'dsi_ui_flagged_traces';
	$salt  = dsi_uisensor_ip_salt();

	$linhas = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT id, client_ip FROM {$table}
			 WHERE ip_hash = '' AND client_ip <> '' LIMIT %d",
			$lote
		)
	);

	foreach ( $linhas as $linha ) {
		$wpdb->update(
			$table,
			[
				'ip_hash'       => dsi_uisensor_ip_hash( $linha->client_ip ),
				'ip_prefix'     => dsi_uisensor_ip_prefix( $linha->client_ip ),
				'ip_salt_epoch' => (int) ( $salt['epoch'] ?? 1 ),
			],
			[ 'id' => (int) $linha->id ],
			[ '%s', '%s', '%d' ],
			[ '%d' ]
		);
	}

	$restantes = (int) $wpdb->get_var(
		"SELECT COUNT(*) FROM {$table} WHERE ip_hash = '' AND client_ip <> ''"
	);

	return [ 'processadas' => count( $linhas ), 'restantes' => $restantes ];
}

/**
 * Aviso no painel enquanto houver linha com IP bruto e sem hash -- some
 * sozinho quando o backfill termina (ou quando a purga apaga os IPs, caso em
 * que a janela ja passou).
 */
function dsi_uisensor_aviso_backfill( string $table ): void {
	global $wpdb;

	$pendentes = (int) $wpdb->get_var(
		"SELECT COUNT(*) FROM {$table} WHERE ip_hash = '' AND client_ip <> ''"
	);

	if ( $pendentes === 0 ) {
		return;
	}

	printf(
		'<div style="background:#fcf9e8;border:1px solid #dba617;padding:16px;margin:16px 0;max-width:80ch;font-size:13px;line-height:1.6;">
			<strong>%d linhas ainda sem <code>ip_hash</code>.</strong>
			Elas t&ecirc;m IP bruto e perdem a capacidade de agrupamento (inclusive a quarentena de sess&otilde;es com id colidido) quando a purga apagar o IP, em at&eacute; %d dias.
			Para preservar: <code>wp eval \'dsi_uisensor_backfill_ip_hash();\'</code> (repita at&eacute; <code>restantes</code> chegar a zero).
			N&Atilde;O use <code>SHA2()</code> no SQL &mdash; n&atilde;o &eacute; HMAC e gera hash incompat&iacute;vel com as linhas novas.
		</div>',
		$pendentes,
		(int) DSI_UISENSOR_IP_RAW_DIAS
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
		'timing_regular_demais'           => 'timing regular demais (sem scroll)',
		'viewport_automacao_sem_plugins'  => 'viewport de automação + sem plugins',
		'sem_idiomas'                     => 'sem idiomas declarados',
		'movimento_mouse_sintetico'       => 'movimento de mouse sintético (poucos pontos/reto demais)',
		'clique_duracao_impossivel'       => 'clique rápido demais (mousedown→mouseup)',
		'digitacao_impossivel'            => 'digitação rápida demais (keydown→keyup)',
		'scroll_multiplo_viewport'        => 'parada de scroll em múltiplo exato da tela (fora do fim do documento)',
		'clique_nao_confiavel'            => 'clique com isTrusted=false (disparado via JavaScript)',
		'tecla_nao_confiavel'             => 'tecla com isTrusted=false',
		'input_nao_confiavel'             => 'input com isTrusted=false (ambíguo: agente OU gerenciador de senha/tradutor)',
		'evento_nao_confiavel'            => 'evento com isTrusted=false (motivo legado, ruleset v1)',
	][ $motivo ] ?? $motivo;
}

/**
 * Período do filtro. O helper do painel de bots vive em outro arquivo do site
 * de origem (dsi-ai-bots-admin.php) e NÃO acompanha este repositório -- sem a
 * guarda, abrir o painel numa instalação limpa dava fatal error.
 *
 * @return array{0:string,1:string,2:string,3:string}
 */
function dsi_uisensor_periodo(): array {
	if ( function_exists( 'dsi_agentmd_periodo_from_request' ) ) {
		return dsi_agentmd_periodo_from_request();
	}

	$hoje = current_time( 'Y-m-d' );

	$valida = static function ( string $chave, string $padrao ): string {
		$valor = isset( $_GET[ $chave ] ) ? sanitize_text_field( wp_unslash( $_GET[ $chave ] ) ) : '';
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $valor ) ? $valor : $padrao;
	};

	$fim    = $valida( 'data_fim', $hoje );
	$inicio = $valida( 'data_inicio', gmdate( 'Y-m-d', strtotime( $hoje ) - 6 * DAY_IN_SECONDS ) );

	if ( $inicio > $fim ) {
		[ $inicio, $fim ] = [ $fim, $inicio ];
	}

	return [ $inicio . ' 00:00:00', $fim . ' 23:59:59', $inicio, $fim ];
}

/**
 * Intervalo de Wilson (95%). A aproximação normal (p ± 1,96·√(p(1-p)/n)) é
 * inválida justamente no regime deste projeto -- p pequeno e n na casa das
 * dezenas -- e chega a produzir limite inferior negativo.
 *
 * @return array{0:?float,1:?float,2:?float} proporção, limite inferior, superior
 */
function dsi_uisensor_wilson( int $k, int $n, float $z = 1.96 ): array {
	if ( $n <= 0 ) {
		return [ null, null, null ];
	}

	$p      = $k / $n;
	$den    = 1 + ( $z * $z ) / $n;
	$centro = ( $p + ( $z * $z ) / ( 2 * $n ) ) / $den;
	$meio   = ( $z * sqrt( ( $p * ( 1 - $p ) ) / $n + ( $z * $z ) / ( 4 * $n * $n ) ) ) / $den;

	return [ $p, max( 0.0, $centro - $meio ), min( 1.0, $centro + $meio ) ];
}

/**
 * Sessões contaminadas por colisão de id. Até o ruleset v1, o session_id
 * reaproveitava o UUID impresso pelo servidor, que era cacheado junto com o
 * HTML -- visitantes distintos caíam na mesma "sessão" (o pior caso nos dados
 * reais: 30 páginas, 28 origens diferentes). Sessão com mais de uma origem
 * distinta é contaminada por construção e não pode entrar em nenhuma taxa.
 *
 * Usa ip_hash quando existe e cai pro client_ip nas linhas antigas.
 */
function dsi_uisensor_sql_quarentena( string $table ): string {
	return "session_id NOT IN (
		SELECT session_id FROM (
			SELECT session_id
			FROM {$table}
			WHERE session_id <> ''
			GROUP BY session_id
			HAVING COUNT( DISTINCT COALESCE( NULLIF( ip_hash, '' ), NULLIF( client_ip, '' ) ) ) > 1
		) contaminadas
	)";
}

/**
 * Prevalência estimada -- a resposta pra "que fração das visitas parece vir
 * de um agente".
 *
 * CALCULADA SÓ SOBRE A AMOSTRA, de propósito. As sessões flagradas fora da
 * amostra entram no banco 100%, então têm viés de seleção por construção:
 * dividir por elas daria um número inventado.
 *
 * LIMITES que o card precisa dizer em voz alta, porque nenhum deles é óbvio:
 *  - o denominador é "sessões COM interação", não "todas as visitas";
 *  - agente puramente leitor (caso Manus etapa 1) não gera beacon e portanto
 *    não está em nenhum dos dois lados da conta -- o número SUBESTIMA;
 *  - sessões contaminadas por colisão de id ficam fora;
 *  - tráfego marcado como dev fica fora.
 */
function dsi_uisensor_render_prevalencia( string $table, string $inicio_sql, string $fim_sql ): void {
	global $wpdb;

	$quarentena = dsi_uisensor_sql_quarentena( $table );

	$r = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT
			   COUNT(DISTINCT session_id) amostradas,
			   COUNT(DISTINCT CASE WHEN heuristic_reasons <> '' THEN session_id END) flagradas
			 FROM {$table}
			 WHERE sampled = 1
			   AND is_dev_traffic = 0
			   AND session_id <> ''
			   AND recorded_at BETWEEN %s AND %s
			   AND {$quarentena}",
			$inicio_sql,
			$fim_sql
		)
	);

	$amostradas = (int) ( $r->amostradas ?? 0 );
	$flagradas  = (int) ( $r->flagradas ?? 0 );

	[ $p, $inf, $sup ] = dsi_uisensor_wilson( $flagradas, $amostradas );

	$confiavel = $amostradas >= 100;

	echo '<div style="display:flex;gap:16px;margin:20px 0;flex-wrap:wrap;">';
	printf(
		'<div style="background:#fff;border:1px solid #ccd0d4;padding:20px;min-width:300px;box-sizing:border-box;">
			<div style="font-size:13px;color:#646970;">Sessões com sinal de automação observável</div>
			<div style="font-size:36px;font-weight:600;line-height:1.2;color:%s;">%s</div>
			<div style="font-size:13px;color:#646970;">%s</div>
			<div style="font-size:12px;color:#646970;margin-top:8px;line-height:1.5;">
				Entre sessões sorteadas <strong>com interação</strong>. Agente que apenas lê não gera beacon e não entra em nenhum dos dois lados da conta — este número subestima.
			</div>
		</div>',
		$confiavel ? '#1d2327' : '#8c6d1f',
		$p === null ? '—' : esc_html( sprintf( '%.1f%%', $p * 100 ) ),
		$p === null
			? 'sem amostra no período'
			: esc_html( sprintf(
				'IC95%% Wilson: %.1f%%–%.1f%% · %d de %d sessões sorteadas',
				$inf * 100,
				$sup * 100,
				$flagradas,
				$amostradas
			) )
	);

	if ( ! $confiavel ) {
		printf(
			'<div style="background:#fcf9e8;border:1px solid #dba617;padding:20px;max-width:46ch;box-sizing:border-box;font-size:13px;line-height:1.5;">
				<strong>Amostra ainda pequena (%d sessões).</strong> Abaixo de ~100 sessões sorteadas o intervalo é maior que a própria diferença que se quer medir. Trate como sinal de que a coleta está funcionando, não como número para decidir nada.
			</div>',
			$amostradas
		);
	}

	$descartes = (int) get_option( 'dsi_uisensor_descartes_' . current_time( 'Y-m-d' ), 0 );
	if ( $descartes > 0 ) {
		printf(
			'<div style="background:#fcf0f1;border:1px solid #d63638;padding:20px;max-width:46ch;box-sizing:border-box;font-size:13px;line-height:1.5;">
				<strong>%d beacons descartados hoje por rate limit.</strong> O denominador do período está incompleto nessa medida. A chave do balde é o IP do edge do CDN, então sessão de navegação rápida (o padrão de um agente) é a mais afetada.
			</div>',
			$descartes
		);
	}

	$falha = get_option( 'dsi_uisensor_ultima_falha' );
	if ( is_array( $falha ) && ! empty( $falha['erro'] ) ) {
		printf(
			'<div style="background:#fcf0f1;border:1px solid #d63638;padding:20px;max-width:46ch;box-sizing:border-box;font-size:13px;line-height:1.5;">
				<strong>Última falha de gravação:</strong> %s<br><code>%s</code><br>Migração de schema pendente?
			</div>',
			esc_html( (string) $falha['quando'] ),
			esc_html( (string) $falha['erro'] )
		);
	}
	echo '</div>';
}

/**
 * Visão por sessão. A assinatura de navegador agêntico é "muitas páginas em
 * janela curta, ritmo constante, poucas ações por página" -- nada disso
 * aparece olhando pageview isolado, só agregando a sessão inteira.
 */
function dsi_uisensor_render_sessoes( string $table, string $inicio_sql, string $fim_sql ): void {
	global $wpdb;

	$quarentena = dsi_uisensor_sql_quarentena( $table );

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
			        SUM(CASE WHEN heuristic_reasons LIKE '%%scroll_multiplo_viewport%%' THEN 1 ELSE 0 END) paginas_multiplo,
			        GROUP_CONCAT(DISTINCT NULLIF(heuristic_reasons, '')) motivos,
			        MAX(user_agent) ua, MAX(client_ip) ip, MAX(ip_prefix) prefixo,
			        MAX(country) pais, MAX(ruleset_version) rv
			 FROM {$table}
			 WHERE session_id <> ''
			   AND is_dev_traffic = 0
			   AND recorded_at BETWEEN %s AND %s
			   AND {$quarentena}
			 GROUP BY session_id
			 HAVING paginas > 1
			 ORDER BY inicio DESC
			 LIMIT 100",
			$inicio_sql,
			$fim_sql
		)
	);

	echo '<h2 style="margin-top:32px;">Sessões multipágina</h2>';
	echo '<p style="color:#646970;max-width:80ch;">Só sessões com 2+ páginas — é onde o ritmo entre páginas existe e pode ser medido. <strong>Ritmo</strong> é o desvio do intervalo dividido pela média: perto de zero significa cadência de máquina (humano varia muito mais). <strong>Scroll em múltiplo</strong> conta em quantas páginas da sessão houve parada em múltiplo exato da tela — uma página isolada é fraca, repetição é que vira evidência. Sessões com mais de uma origem distinta estão em quarentena e não aparecem aqui.</p>';
	echo '<table class="widefat striped"><thead><tr><th>Início</th><th>Páginas</th><th>Duração</th><th title="Páginas por minuto">Pág/min</th><th title="Desvio do intervalo entre páginas / média. Baixo = cadência constante">Ritmo</th><th title="(cliques + scrolls) por página">Ações/pág</th><th title="Páginas da sessão com parada em múltiplo exato da tela">Scroll em múltiplo</th><th>Motivos</th><th>Amostra</th><th title="Regra vigente quando a linha foi gravada">Ruleset</th><th>Rede</th><th>País</th></tr></thead><tbody>';

	if ( ! $sessoes ) {
		echo '<tr><td colspan="12">Nenhuma sessão multipágina nesse período.</td></tr>';
	}

	foreach ( $sessoes as $s ) {
		$paginas = (int) $s->paginas;
		$duracao = (int) $s->duracao_s;
		$pag_min = $duracao > 0 ? $paginas / ( $duracao / 60 ) : null;
		$media   = (float) $s->intervalo_medio;
		$cv      = $media > 0 ? (float) $s->intervalo_desvio / $media : null;
		$acoes   = ( (int) $s->cliques + (int) $s->scrolls ) / max( 1, $paginas );

		// Só destaca cadência robótica quando há amostra suficiente pra isso
		// significar algo -- com 2 páginas, 1 intervalo, CV é ruído.
		$cv_suspeito = $cv !== null && $cv < 0.35 && $paginas >= 3;

		// Rede em vez de IP: o bruto sai em 7 dias, o prefixo fica os 90.
		$rede = $s->prefixo ?: ( $s->ip ?: '—' );

		printf(
			'<tr><td>%s</td><td>%d</td><td>%s</td><td>%s</td><td%s>%s</td><td>%.1f</td><td>%d de %d</td><td>%s</td><td>%s</td><td>%s</td><td><code>%s</code></td><td>%s</td></tr>',
			esc_html( $s->inicio ),
			$paginas,
			$duracao > 0 ? esc_html( sprintf( '%dm%02ds', intdiv( $duracao, 60 ), $duracao % 60 ) ) : '—',
			$pag_min !== null ? esc_html( number_format( $pag_min, 1 ) ) : '—',
			$cv_suspeito ? ' style="color:#d63638;font-weight:600;"' : '',
			$cv !== null ? esc_html( number_format( $cv, 2 ) ) : '—',
			$acoes,
			(int) $s->paginas_multiplo,
			$paginas,
			$s->motivos ? esc_html( implode( ', ', array_map( 'dsi_uisensor_motivo_label', array_unique( explode( ',', $s->motivos ) ) ) ) ) : '—',
			$s->sampled ? 'sim' : '—',
			esc_html( (string) ( $s->rv ?? '—' ) ),
			esc_html( (string) $rede ),
			esc_html( $s->pais ?? '—' )
		);
	}

	echo '</tbody></table>';
}

/** Quantas linhas de cada versão de regra no período -- v1 e v2 não são comparáveis. */
function dsi_uisensor_render_rulesets( string $table, string $inicio_sql, string $fim_sql ): void {
	global $wpdb;

	$linhas = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT COALESCE(ruleset_version, 0) rv, COUNT(*) total
			 FROM {$table}
			 WHERE recorded_at BETWEEN %s AND %s
			 GROUP BY rv ORDER BY rv",
			$inicio_sql,
			$fim_sql
		)
	);

	if ( count( $linhas ) < 2 ) {
		return;
	}

	echo '<div style="background:#fcf9e8;border:1px solid #dba617;padding:16px;margin:16px 0;max-width:80ch;font-size:13px;line-height:1.5;">';
	echo '<strong>Período mistura versões de regra.</strong> Na v2 mudaram o significado de <code>digitacao_impossivel</code>, <code>timing_regular_demais</code>, <code>scroll_multiplo_viewport</code> e a identidade de sessão. Não compare taxa entre versões: ';
	$partes = [];
	foreach ( $linhas as $l ) {
		$partes[] = sprintf( 'v%d: %d linhas', (int) $l->rv, (int) $l->total );
	}
	echo esc_html( implode( ' · ', $partes ) );
	echo '</div>';
}

function dsi_uisensor_admin_page(): void {
	global $wpdb;
	$table = $wpdb->prefix . 'dsi_ui_flagged_traces';

	[ $inicio_sql, $fim_sql, $inicio_input, $fim_input ] = dsi_uisensor_periodo();

	// As três consultas abaixo excluem linha de baseline (heuristic_reasons
	// vazio) de propósito -- esta tela e a tabela "Sessões flagradas" são
	// sobre DETECÇÃO. A linha de baseline sem motivo só entra no cálculo de
	// prevalência e na visão por sessão.
	$total_periodo = (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM {$table}
			 WHERE heuristic_reasons <> '' AND is_dev_traffic = 0
			   AND recorded_at BETWEEN %s AND %s",
			$inicio_sql,
			$fim_sql
		)
	);

	$por_motivo = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT heuristic_reasons, COUNT(*) AS total FROM {$table}
			 WHERE heuristic_reasons <> '' AND is_dev_traffic = 0
			   AND recorded_at BETWEEN %s AND %s
			 GROUP BY heuristic_reasons ORDER BY total DESC",
			$inicio_sql,
			$fim_sql
		)
	);

	$linhas = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT * FROM {$table}
			 WHERE heuristic_reasons <> '' AND is_dev_traffic = 0
			   AND recorded_at BETWEEN %s AND %s
			 ORDER BY recorded_at DESC LIMIT %d",
			$inicio_sql,
			$fim_sql,
			DSI_UISENSOR_POR_PAGINA
		)
	);

	echo '<div class="wrap"><h1>Navegadores agênticos</h1>';
	echo '<p style="color:#646970;max-width:80ch;">Navegador agêntico (Claude no Chrome, ChatGPT Atlas, Perplexity Comet) manda <strong>User-Agent de Chrome puro</strong> — não existe detecção por header, só por comportamento. Esta tela mede isso de duas formas: <strong>detecção</strong> (sessões que dispararam algum sinal de automação, registradas 100%) e <strong>baseline</strong> (amostra de ' . (int) round( DSI_UISENSOR_BASELINE_RATE * 100 ) . '% das sessões, sorteada às cegas, que serve de denominador). Nenhum sinal aqui prova que há um agente ou que não há uma pessoa: são evidências observáveis de interação programática.</p>';

	dsi_uisensor_aviso_backfill( $table );
	dsi_uisensor_render_rulesets( $table, $inicio_sql, $fim_sql );
	dsi_uisensor_render_prevalencia( $table, $inicio_sql, $fim_sql );

	echo '<form method="get" style="margin:16px 0;display:flex;gap:8px;align-items:end;flex-wrap:wrap;">';
	echo '<input type="hidden" name="page" value="dsi-ui-sensor">';
	echo '<label>De <input type="date" name="data_inicio" value="' . esc_attr( $inicio_input ) . '"></label>';
	echo '<label>Até <input type="date" name="data_fim" value="' . esc_attr( $fim_input ) . '"></label>';
	echo '<button type="submit" class="button">Filtrar</button>';
	echo '</form>';

	printf(
		'<div style="background:#fff;border:1px solid #ccd0d4;width:220px;padding:20px;box-sizing:border-box;margin-bottom:24px;">
			<div style="font-size:13px;color:#646970;">Páginas com sinal no período</div>
			<div style="font-size:36px;font-weight:600;">%d</div>
		</div>',
		$total_periodo
	);

	if ( $por_motivo ) {
		echo '<h2>Por combinação de motivo</h2>';
		echo '<table class="widefat striped"><thead><tr><th>Motivos</th><th>Páginas</th></tr></thead><tbody>';
		foreach ( $por_motivo as $m ) {
			$rotulos = implode( ', ', array_map( 'dsi_uisensor_motivo_label', explode( ',', $m->heuristic_reasons ) ) );
			printf( '<tr><td>%s</td><td>%d</td></tr>', esc_html( $rotulos ), (int) $m->total );
		}
		echo '</tbody></table>';
	}

	dsi_uisensor_render_sessoes( $table, $inicio_sql, $fim_sql );

	echo '<h2 style="margin-top:32px;">Páginas com sinal (' . (int) DSI_UISENSOR_POR_PAGINA . ' mais recentes)</h2>';
	echo '<table class="widefat striped"><thead><tr><th>Data</th><th>URL</th><th>Motivos</th><th>Cliente (UA)</th><th>Rede</th><th>País</th><th>Cliques</th><th>Scrolls</th><th>Teclas</th><th>Inputs</th><th>Viewport</th><th title="navigator.webdriver">WebDriver</th><th title="Houve mousemove antes do 1º clique?">Mousemove antes</th><th title="Comprimento do caminho / distância em linha reta -- perto de 1.0 = trajetória sintética">Retidão</th><th title="mousedown→mouseup do 1º clique. Negativo = timeStamp inconsistente de evento sintético">Duração clique (ms)</th><th title="keydown→keyup médio, pareado por tecla">Duração tecla (ms)</th><th title="Parada final de scroll / fim rolável do documento. Iguais = leu até o fim; diferentes com múltiplo exato = passo fixo de tela">Scroll (parada/fim)</th><th title="Paradas em múltiplo exato da tela, fora do fim do documento">Paradas múltiplo</th></tr></thead><tbody>';

	if ( ! $linhas ) {
		echo '<tr><td colspan="18">Nenhuma página com sinal nesse período.</td></tr>';
	}

	foreach ( $linhas as $row ) {
		$mousemove     = $row->had_mousemove_before_first_click;
		$mousemove_txt = $mousemove === null ? '—' : ( $mousemove ? 'sim' : 'não' );
		$rotulos       = implode( ', ', array_map( 'dsi_uisensor_motivo_label', explode( ',', $row->heuristic_reasons ) ) );
		$rede          = $row->ip_prefix ?: ( $row->client_ip ?: '—' );

		$scroll_txt = '—';
		if ( $row->max_scroll_px !== null ) {
			$scroll_txt = number_format( (float) $row->max_scroll_px, 0, ',', '.' ) . ' / ' .
				( $row->doc_scroll_max_px !== null ? number_format( (float) $row->doc_scroll_max_px, 0, ',', '.' ) : '?' );
		}

		printf(
				'<tr><td>%s</td><td><code>%s</code></td><td>%s</td><td title="%s">%s</td><td><code>%s</code></td><td>%s</td><td>%d</td><td>%d</td><td>%d</td><td>%d</td><td>%s×%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
			esc_html( $row->recorded_at ),
			esc_html( $row->url_path ),
			esc_html( $rotulos ),
			esc_attr( $row->user_agent ),
			esc_html( $row->bot_label ?? '—' ),
			esc_html( (string) $rede ),
			esc_html( $row->country ?? '—' ),
			(int) $row->n_clicks,
			(int) $row->n_scrolls,
			(int) $row->n_keydowns,
			(int) $row->n_inputs,
			esc_html( (string) ( $row->viewport_w ?? '—' ) ),
			esc_html( (string) ( $row->viewport_h ?? '—' ) ),
			$row->navigator_webdriver ? 'sim' : 'não',
			esc_html( $mousemove_txt ),
			$row->first_click_straightness !== null ? esc_html( number_format( (float) $row->first_click_straightness, 3 ) ) : '—',
			$row->first_click_dwell_ms !== null ? esc_html( number_format( (float) $row->first_click_dwell_ms, 1 ) ) : '—',
			$row->mean_key_dwell_ms !== null ? esc_html( number_format( (float) $row->mean_key_dwell_ms, 1 ) ) : '—',
			esc_html( $scroll_txt ),
			$row->n_scroll_stops_multiplo !== null
				? esc_html( (int) $row->n_scroll_stops_multiplo . ' de ' . (int) $row->n_scroll_stops )
				: '—'
		);
	}

	echo '</tbody></table>';

	printf(
		'<p style="color:#646970;max-width:80ch;margin-top:24px;font-size:13px;line-height:1.6;">
			<strong>Retenção:</strong> IP bruto é apagado da linha após %d dias; <code>ip_hash</code> (HMAC com salt do site) e <code>ip_prefix</code> (/24) seguem até %d dias, quando a linha inteira sai. O salt rotaciona junto com a purga — depois disso os hashes antigos deixam de ser vinculáveis a qualquer IP novo. Comparação por <code>ip_hash</code> só vale dentro do mesmo <code>ip_salt_epoch</code>.
		</p>',
		(int) DSI_UISENSOR_IP_RAW_DIAS,
		(int) DSI_UISENSOR_RETENCAO_DIAS
	);

	echo '</div>';
}
