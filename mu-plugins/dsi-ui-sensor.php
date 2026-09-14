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
 * "só quem parece bot deixa rastro". Para voltar ao regime seletivo, baixe
 * BASELINE_RATE (ex.: 0.2) -- aí sim o pageview humano fora do sorteio não
 * gera requisição nenhuma. `baseline_rate` é gravado por linha e o painel
 * avisa quando um período mistura taxas diferentes.
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
 *
 * ---------------------------------------------------------------------------
 * SEGURANÇA DO ENDPOINT -- corrigido na v4 após revisão externa.
 *
 * O endpoint é público de necessidade (sendBeacon não permite header
 * customizado, então não dá pra exigir nonce). Isso NÃO significa "sem
 * verificação nenhuma": um curl direto pro endpoint, forjando `motivos`,
 * `session_id` e demais campos, conseguia gravar linha arbitrária e -- pior
 * -- se autodeclarar `dev_traffic` pra sumir das contas do painel. Camadas
 * de defesa adicionadas: (1) rejeita quando o header `Origin` está presente
 * e não bate com o domínio do site (não pega curl que nem manda Origin, mas
 * mata o caso ingênuo); (2) `dev_traffic` deixou de ser lido do payload do
 * cliente -- agora vem de um cookie (`dsi_dbg`) que só o PRÓPRIO servidor
 * grava, quando alguém visita com `?dsi_debug=1`. Nenhuma das duas é à prova
 * de um atacante que leia este código-fonte e replique o fluxo exato -- é
 * elevar o custo de abuso trivial, não eliminar toda superfície.
 */

defined( 'ABSPATH' ) || exit;

const DSI_UISENSOR_NAMESPACE     = 'dsi/v1';
const DSI_UISENSOR_ROUTE         = '/uitrace';
const DSI_UISENSOR_RL_LIMITE     = 90;  // beacons
const DSI_UISENSOR_RL_JANELA     = 60;  // segundos
const DSI_UISENSOR_RETENCAO_DIAS = 90;  // vida da linha inteira
const DSI_UISENSOR_IP_RAW_DIAS   = 7;   // vida do client_ip BRUTO dentro da linha
const DSI_UISENSOR_POR_PAGINA    = 100;
const DSI_UISENSOR_RULESET_VERSION = 6; // espelha RULESET_VERSION do JS -- usado nas linhas gravadas direto pelo servidor (header flags)

/**
 * IPs da própria equipe/máquina de teste. Linhas vindas daqui recebem
 * is_dev_traffic = 1 e ficam fora dos cálculos do painel por padrão.
 *
 * Existe pra NÃO precisar guardar IP bruto de todo visitante por 90 dias só
 * pra reconhecer o próprio tráfego de teste depois (foi o que aconteceu no
 * cruzamento H4: 1 dos 3 IPs "sobrepostos" era a máquina de desenvolvimento).
 *
 * Alternativa sem configurar nada, e a recomendada: abra o site uma vez com
 * ?dsi_debug=1 -- o PRÓPRIO SERVIDOR grava um cookie (dsi_dbg, ver
 * dsi_uisensor_debug_cookie), permanente até `?dsi_debug=0` limpar. Não é
 * credencial, não concede nada: só pede pra ser ignorado nas contas. (Na v3
 * isso era um valor enviado pelo cliente no payload do beacon -- qualquer um
 * podia forjar `dev_traffic:true` numa requisição direta ao endpoint e sumir
 * das contas; corrigido na v4 pra depender só do cookie, que o servidor
 * controla.)
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
 * Marca ?dsi_debug=1/0 como cookie de sessão gravado pelo SERVIDOR -- em
 * `init`, cedo o bastante pra `setcookie()` funcionar (wp_footer, onde o
 * sensor é impresso, já é tarde demais: o HTML já começou a sair). O cookie
 * não é lido por JS nenhum (httponly) porque não precisa: só o PHP consulta
 * em `dsi_uisensor_eh_dev_traffic()`.
 */
add_action( 'init', 'dsi_uisensor_debug_cookie' );
function dsi_uisensor_debug_cookie(): void {
	if ( headers_sent() ) {
		return;
	}
	if ( isset( $_GET['dsi_debug'] ) && $_GET['dsi_debug'] === '1' ) {
		setcookie( 'dsi_dbg', '1', time() + 10 * YEAR_IN_SECONDS, COOKIEPATH ?: '/', COOKIE_DOMAIN, is_ssl(), true );
	} elseif ( isset( $_GET['dsi_debug'] ) && $_GET['dsi_debug'] === '0' ) {
		setcookie( 'dsi_dbg', '', time() - HOUR_IN_SECONDS, COOKIEPATH ?: '/', COOKIE_DOMAIN, is_ssl(), true );
	}
}

/** Único lugar que decide se um IP/sessão é tráfego interno -- ver nota de segurança no topo. */
function dsi_uisensor_eh_dev_traffic( string $ip ): bool {
	return ( isset( $_COOKIE['dsi_dbg'] ) && $_COOKIE['dsi_dbg'] === '1' )
		|| in_array( $ip, dsi_uisensor_dev_ips(), true );
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
// visitante pode nascer aqui -- foi exatamente o bug do session_id em v1, e
// a v3 REINCIDIU nisso com os motivos de header (ver
// dsi_uisensor_grava_header_flags logo abaixo, que corrige gravando direto
// no banco em vez de passar pelo HTML cacheável).
// =============================================================================
add_action( 'wp_footer', 'dsi_uisensor_print_inline', 20 );

/**
 * Motivos calculados a partir dos headers da requisição de PÁGINA (não do
 * beacon) -- só é possível avaliar aqui, em wp_footer, porque $_SERVER ainda
 * reflete a requisição original nesse ponto do request.
 *
 * Comparado contra a literatura de fingerprinting de agentes (FP-Agent,
 * arXiv:2605.01247; "Whose Agent Are You?", arXiv:2606.20910). Ainda não
 * verificado ao vivo contra Claude no Chrome, Comet ou Manus especificamente
 * (nenhum mostrou anomalia de header nos testes já feitos); mira uma
 * categoria mais ampla de automação que intercepta rede via CDP e não
 * preserva esses headers com fidelidade.
 */
function dsi_uisensor_header_flags(): array {
	$motivos = [];

	// Sec-Fetch-Mode "navigate" só pode vir pareado com Sec-Fetch-Dest
	// "document" (ou "iframe"/"frame"/"object"/"embed" em conteúdo embutido
	// -- os dois últimos adicionados na v4: um <object>/<embed> legítimo
	// embutindo uma página do site produz essa combinação por spec, e sem
	// eles todo acesso embutido assim virava falso positivo). Um navegador
	// real nunca "navega" pedindo um destino fora dessa lista.
	$modo = $_SERVER['HTTP_SEC_FETCH_MODE'] ?? '';
	$dest = $_SERVER['HTTP_SEC_FETCH_DEST'] ?? '';
	if ( $modo === 'navigate' && $dest !== '' && ! in_array( $dest, [ 'document', 'iframe', 'frame', 'object', 'embed' ], true ) ) {
		$motivos[] = 'fetch_metadata_impossivel';
	}

	// User-Agent e Sec-Ch-Ua nascem do mesmo motor num navegador real -- não
	// podem declarar famílias diferentes. Checagem estreita de propósito
	// (só Chrome vs. Edge, os dois com token bem definido) pra não arriscar
	// falso positivo com navegadores menos comuns.
	$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
	$ch = strtolower( $_SERVER['HTTP_SEC_CH_UA'] ?? '' );
	if ( $ch !== '' ) {
		$ua_e_edge   = strpos( $ua, 'Edg/' ) !== false;
		$ua_e_chrome = ! $ua_e_edge && strpos( $ua, 'Chrome/' ) !== false;
		$ch_tem_edge   = strpos( $ch, 'edge' ) !== false;
		$ch_tem_chrome = strpos( $ch, 'chrom' ) !== false;

		if ( ( $ua_e_edge && ! $ch_tem_edge && $ch_tem_chrome )
			|| ( $ua_e_chrome && $ch_tem_edge && ! $ch_tem_chrome ) ) {
			$motivos[] = 'client_hints_incoerente';
		}
	}

	return $motivos;
}

/**
 * Grava direto no banco os motivos calculados a partir de header -- NUNCA
 * passa pelo cliente/HTML cacheável (ver nota de ATENÇÃO acima). Como só é
 * chamada de dentro de dsi_uisensor_print_inline(), só roda em cache MISS
 * (o único momento em que este hook realmente executa) -- então cada
 * chamada corresponde à requisição de UM visitante real, nunca atribui o
 * header de alguém a outra pessoa que receba a mesma página do cache.
 *
 * session_id fica vazio de propósito: não existe conceito de "sessão de
 * navegação" aqui, é um sinal de UMA requisição específica, sem beacon
 * nenhum do cliente por trás. `sampled=0` pelo mesmo motivo -- não faz parte
 * do sorteio de baseline (que é por sessão comportamental).
 */
function dsi_uisensor_grava_header_flags(): void {
	$motivos = array_values( array_unique( dsi_uisensor_header_flags() ) );
	if ( ! $motivos ) {
		return;
	}

	$ip = dsi_uisensor_client_ip();

	// Balde PRÓPRIO, separado do endpoint público de beacon -- achado em
	// revisão externa (2026-09-13): reaproveitar o mesmo balde permitia
	// esgotar a cota de um edge inteiro só com GETs baratos (query string
	// variando pra forçar cache MISS, sem precisar montar POST nenhum),
	// derrubando a coleta de beacon legítimo de qualquer visitante que caia
	// no mesmo edge.
	if ( dsi_uisensor_rate_limit_excedido( $ip, 'dsi_uis_hdr_rl_' ) ) {
		dsi_uisensor_conta_descarte( 'header' );
		return;
	}

	global $wpdb;
	$salt = dsi_uisensor_ip_salt();
	$ua   = sanitize_text_field( $_SERVER['HTTP_USER_AGENT'] ?? '' );

	// Só o path, sem query string -- REQUEST_URI bruto tem duas semânticas
	// diferentes da mesma coluna: o beacon grava location.pathname (sem
	// query), então misturar os dois quebra agrupamento por URL. Query
	// string é texto livre controlado por quem chama; não precisa disso
	// gravado (achado em revisão externa, 2026-09-13).
	$path_bruto = wp_parse_url( $_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH );

	$wpdb->insert(
		$wpdb->prefix . 'dsi_ui_flagged_traces',
		[
			'recorded_at'       => current_time( 'mysql' ),
			'trace_id'          => wp_generate_uuid4(),
			'session_id'        => '',
			'sampled'           => 0,
			'is_dev_traffic'    => dsi_uisensor_eh_dev_traffic( $ip ) ? 1 : 0,
			'baseline_rate'     => DSI_UISENSOR_BASELINE_RATE,
			'url_path'          => dsi_uisensor_texto( $path_bruto ?? '', 255 ),
			'user_agent'        => mb_substr( $ua, 0, 255 ),
			'client_ip'         => $ip,
			'ip_hash'           => dsi_uisensor_ip_hash( $ip ),
			'ip_prefix'         => dsi_uisensor_ip_prefix( $ip ),
			'ip_salt_epoch'     => (int) ( $salt['epoch'] ?? 1 ),
			'country'           => function_exists( 'dsi_agentmd_country' ) ? dsi_agentmd_country() : null,
			'bot_label'         => function_exists( 'dsi_agentmd_classify_bot' ) ? dsi_agentmd_classify_bot( $ua ) : null,
			'heuristic_reasons' => implode( ',', $motivos ),
			'ruleset_version'   => DSI_UISENSOR_RULESET_VERSION,
		],
		[ '%s', '%s', '%s', '%d', '%d', '%f', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%d' ]
	);
}

function dsi_uisensor_print_inline(): void {
	if ( is_admin() || is_feed() || is_user_logged_in() ) {
		return;
	}

	dsi_uisensor_grava_header_flags();

	$path = __DIR__ . '/assets/dsi-ui-sensor.js';
	$js   = @file_get_contents( $path );
	if ( $js === false ) {
		return;
	}

	// traceId aqui é o id do RENDER, não do pageview nem da sessão: como este
	// HTML é cacheável, ele pode chegar idêntico a muitos visitantes. É
	// gravado como render_id justamente pra tornar isso mensurável (duas
	// linhas com o mesmo render_id e session_id diferentes = cache HIT).
	// NENHUM outro valor aqui pode ser específico do visitante desta
	// requisição -- ver header_flags acima, que por isso é gravado direto,
	// nunca incluído neste config.
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
// dá pra exigir nonce aqui). Rate limit + checagem de Origin fazem esse
// papel (ver nota de SEGURANÇA DO ENDPOINT no topo do arquivo).
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

/**
 * Rejeita quando o header Origin está PRESENTE e não bate com o domínio do
 * site. Não rejeita quando Origin está ausente (nem todo cliente legítimo
 * manda) -- filtra o caso ingênuo (curl direto, sem se preocupar em forjar
 * Origin), não é uma barreira contra um atacante que leia este código e
 * replique o header.
 */
function dsi_uisensor_origin_valida(): bool {
	$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
	if ( $origin === '' ) {
		return true;
	}

	$site  = wp_parse_url( home_url() );
	$vindo = wp_parse_url( $origin );

	return isset( $site['host'], $vindo['host'] ) && strtolower( $site['host'] ) === strtolower( $vindo['host'] );
}

function dsi_uisensor_client_ip(): string {
	return function_exists( 'dsi_agentmd_client_ip' )
		? dsi_agentmd_client_ip()
		: sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? '' );
}

/**
 * Mesmo esquema de balde do dsi-api-ratelimit.php, namespace próprio.
 * $prefixo isola o balde do endpoint de beacon do balde de header flags --
 * são caminhos com custo de disparo bem diferente (POST vs GET puro) e
 * compartilhar o mesmo balde deixava um esgotar o outro (ver
 * dsi_uisensor_grava_header_flags).
 */
function dsi_uisensor_rate_limit_excedido( string $ip, string $prefixo = 'dsi_uis_rl_' ): bool {
	$key   = $prefixo . md5( $ip );
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
	'fetch_metadata_impossivel',
	'client_hints_incoerente',
];

/**
 * sendBeacon envia como text/plain (Blob), não application/json -- por isso
 * lê o corpo bruto em vez de $request->get_json_params(), que exige o
 * Content-Type correto pra popular os parâmetros.
 */
function dsi_uisensor_ingest( WP_REST_Request $request ) {
	if ( ! dsi_uisensor_origin_valida() ) {
		return new WP_REST_Response( null, 204 );
	}

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

	// array_unique: sem isso, um payload malicioso repetindo o mesmo motivo
	// centenas de vezes ({"motivos":["webdriver","webdriver",...]}) pode
	// estourar o VARCHAR(255) de heuristic_reasons (insert rejeitado em modo
	// estrito, ou truncado em silêncio fora dele). Legítimo nunca repete --
	// no máximo os 15 motivos válidos, ~230 chars.
	$motivos = array_values( array_unique( array_intersect(
		array_map( 'sanitize_text_field', (array) ( $dados['motivos'] ?? [] ) ),
		DSI_UISENSOR_MOTIVOS_VALIDOS
	) ) );

	$sampled = ! empty( $dados['sampled'] );

	// Linha sem motivo só é aceita se vier da amostra de baseline -- é
	// exatamente o "tráfego normal" que forma o denominador. Sem motivo e sem
	// ser amostra significa cliente adulterado ou beacon de outra origem.
	if ( ! $motivos && ! $sampled ) {
		return new WP_REST_Response( null, 204 );
	}

	$user_agent = sanitize_text_field( $_SERVER['HTTP_USER_AGENT'] ?? '' );
	$salt       = dsi_uisensor_ip_salt();

	// dev_traffic NÃO vem mais do payload do cliente (ver nota de SEGURANÇA
	// DO ENDPOINT no topo) -- qualquer um podia se autodeclarar tráfego
	// interno numa requisição forjada direto ao endpoint e sumir das contas
	// do painel. Decidido só pelo que o SERVIDOR sabe (cookie + lista de IP).
	$is_dev = dsi_uisensor_eh_dev_traffic( $ip );

	$trace_id_valor = dsi_uisensor_texto( $dados['trace_id'] ?? '', 36 );

	// Mapa coluna => [formato, valor]. Em v1 isto era um array de valores e um
	// array de formatos paralelos, com 47 posições que precisavam bater na
	// ordem -- classe de bug caro e silencioso. Aqui os dois nascem juntos.
	$campos = [
		'recorded_at'                      => [ '%s', current_time( 'mysql' ) ],
		'trace_id'                         => [ '%s', $trace_id_valor ],
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
		'total_mouse_dist_px'              => [ '%d', dsi_uisensor_int( $dados['total_mouse_dist_px'] ?? null, 0, 100000000 ) ],
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
	$table = $wpdb->prefix . 'dsi_ui_flagged_traces';

	// Reenvio da MESMA pageview (visibilitychange não-terminal, ver flush()
	// no JS) substitui a linha anterior em vez de duplicar -- fica só a mais
	// recente. trace_id é gerado por pageview (crypto.randomUUID no
	// cliente), então nunca colide entre pageviews diferentes; não usamos
	// UNIQUE KEY + upsert pra não arriscar a coluna, que já tem linhas
	// antigas com trace_id vazio (v1) que colidiriam entre si.
	//
	// INSERE PRIMEIRO, remove depois (ordem invertida na v5, revisão
	// externa 2026-09-13) -- a ordem antiga (delete depois insert) tinha
	// dois problemas reais: (1) se o insert falhasse depois do delete já ter
	// rodado, a linha boa anterior desaparecia e nenhuma nova entrava --
	// perda de dado numa falha que antes era só "não grava a atualização";
	// (2) duas requisições concorrentes pro mesmo trace_id (dois envios de
	// sendBeacon quase simultâneos) podiam ambas fazer DELETE sem achar nada
	// (nenhuma ainda tinha committado) e ambas inserir -- duas linhas pro
	// mesmo pageview, inflando o denominador por página.
	$ok = $wpdb->insert( $table, $valores, $formatos );

	if ( $ok === false ) {
		// Falha de insert era silenciosa: se a tabela não existe (instalação
		// que não rodou o schema.sql) ou uma coluna falta (migração
		// pendente), o dado desaparecia sem sinal nenhum.
		dsi_uisensor_log_falha( $wpdb->last_error );
	} else {
		// Gravação nova teve sucesso -- qualquer falha anterior já não
		// reflete o estado atual do endpoint. get_option() é cacheado
		// (praticamente de graça); delete_option() sempre roda um SELECT sem
		// cache no wpdb -- só chamamos quando de fato existe algo a apagar,
		// em vez de pagar essa query em TODO beacon com interação (achado em
		// revisão externa, 2026-09-14: estava condicionado a ter trace_id,
		// então um insert sem trace_id nunca limpava o alarme).
		if ( get_option( 'dsi_uisensor_ultima_falha' ) ) {
			delete_option( 'dsi_uisensor_ultima_falha' );
		}

		if ( $trace_id_valor !== '' ) {
			$novo_id = (int) $wpdb->insert_id;

			// v5 apagava por ORDEM de chegada (id menor que o recém-inserido)
			// -- reproduzido ao vivo em produção (2026-09-14): forçando dois
			// beacons do mesmo trace_id chegarem fora de ordem, o mais
			// completo (5 cliques, 40 scrolls, 9 teclas) foi apagado pelo
			// menos completo que chegou depois, reintroduzindo a truncagem
			// que o reenvio da v4 existia pra eliminar. Os contadores só
			// crescem dentro de um pageview, então a soma de atividade já é
			// um "número de versão" de graça -- comparamos por isso, não por
			// ordem de chegada: apaga só quem tem atividade MENOR OU IGUAL à
			// que acabamos de gravar (nunca a mais completa), e se sobrar
			// outra linha depois disso, ela é que era mais completa -- a
			// obsoleta então é esta que acabamos de inserir. Funciona
			// independente de qual requisição chega primeiro. Escopado
			// também por session_id -- sem isso, o trace_id de uma linha
			// legada (v1, quando o valor vinha impresso no HTML público e se
			// repete em dezenas de linhas diferentes) seria uma chave de
			// DELETE não autenticada.
			$atividade = (int) $campos['n_clicks'][1] + (int) $campos['n_scrolls'][1]
				+ (int) $campos['n_keydowns'][1] + (int) $campos['n_inputs'][1];

			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$table} WHERE trace_id = %s AND session_id = %s AND id <> %d
					   AND ( COALESCE(n_clicks,0) + COALESCE(n_scrolls,0) + COALESCE(n_keydowns,0) + COALESCE(n_inputs,0) ) <= %d",
					$trace_id_valor,
					$campos['session_id'][1],
					$novo_id,
					$atividade
				)
			);

			$sobrou = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$table} WHERE trace_id = %s AND session_id = %s AND id <> %d",
					$trace_id_valor,
					$campos['session_id'][1],
					$novo_id
				)
			);
			if ( $sobrou > 0 ) {
				$wpdb->delete( $table, [ 'id' => $novo_id ], [ '%d' ] );
			}
		}
	}

	return new WP_REST_Response( null, 204 );
}

/**
 * Contador de descartes por rate limit, por dia (só números). $tipo separa o
 * balde do beacon (essas linhas afetam o denominador de prevalência) do
 * balde do header flag (nunca afeta -- essas linhas são sampled=0 e nunca
 * entram em nenhuma taxa). Somar os dois no mesmo número superestimava o
 * dano ao denominador no aviso do painel (achado em revisão externa,
 * 2026-09-14). Prefixos com o MESMO formato (base + data) em famílias
 * separadas -- dsi_uisensor_purga() precisa varrer as duas.
 */
function dsi_uisensor_conta_descarte( string $tipo = 'beacon' ): void {
	$prefixo = $tipo === 'header' ? 'dsi_uisensor_desc_hdr_' : 'dsi_uisensor_descartes_';
	$chave   = $prefixo . current_time( 'Y-m-d' );
	$atual   = (int) get_option( $chave, 0 );
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

	// Limpa os contadores diários de descarte (uma option nova por dia,
	// pra sempre, sem isso) -- mantém só os últimos DSI_UISENSOR_RETENCAO_DIAS.
	// delete_option() em vez de DELETE direto na tabela (achado em revisão
	// externa, 2026-09-13): a query direta não invalida o object cache --
	// com cache persistente ativo, a option apagada continuava servida do
	// cache até expirar sozinha. Impacto prático era baixo aqui (autoload=no,
	// só a option do dia é lida), mas delete_option() é o caminho suportado
	// e custa o mesmo.
	$corte_opcao = 'dsi_uisensor_descartes_' . dsi_uisensor_corte( DSI_UISENSOR_RETENCAO_DIAS );
	$antigas     = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name < %s",
			$wpdb->esc_like( 'dsi_uisensor_descartes_' ) . '%',
			$corte_opcao
		)
	);
	foreach ( $antigas as $nome_opcao ) {
		delete_option( $nome_opcao );
	}

	// Mesma limpeza pro balde de descarte do header flag (prefixo separado
	// desde a v6, ver dsi_uisensor_conta_descarte).
	$corte_opcao_hdr = 'dsi_uisensor_desc_hdr_' . dsi_uisensor_corte( DSI_UISENSOR_RETENCAO_DIAS );
	$antigas_hdr     = $wpdb->get_col(
		$wpdb->prepare(
			"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name < %s",
			$wpdb->esc_like( 'dsi_uisensor_desc_hdr_' ) . '%',
			$corte_opcao_hdr
		)
	);
	foreach ( $antigas_hdr as $nome_opcao ) {
		delete_option( $nome_opcao );
	}

	// Salt novo depois da purga: os hashes que sobraram deixam de ser
	// vinculáveis a qualquer IP observado daqui pra frente.
	dsi_uisensor_rotaciona_salt();

	// Prova de que o cron rodou de verdade -- sem isso, a promessa de
	// retenção do README/painel depende silenciosamente do wp-cron nunca
	// morrer, e não havia como notar se ele parasse.
	update_option( 'dsi_uisensor_ultima_purga', current_time( 'mysql' ), false );
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

/**
 * Proporção de cada tipo de ação sobre o total da página -- não é contagem
 * bruta (já exibida em colunas separadas), é o "formato" da interação.
 * Achado na literatura (FP-Agent/"Whose Agent Are You?"): agentes diferentes
 * mantêm uma proporção característica entre clique/scroll/tecla/input que se
 * repete conforme o tipo de página muda -- não exige coleta nova, só olhar as
 * contagens que já existem de outro jeito.
 */
function dsi_uisensor_perfil_acao( int $clicks, int $scrolls, int $keys, int $inputs ): string {
	$total = $clicks + $scrolls + $keys + $inputs;
	if ( $total === 0 ) {
		return '—';
	}

	$partes = [];
	foreach ( [ 'clique' => $clicks, 'scroll' => $scrolls, 'tecla' => $keys, 'input' => $inputs ] as $rotulo => $valor ) {
		if ( $valor > 0 ) {
			$partes[] = $rotulo . ' ' . round( ( $valor / $total ) * 100 ) . '%';
		}
	}

	return implode( ' · ', $partes );
}

function dsi_uisensor_motivo_label( string $motivo ): string {
	return [
		'webdriver'                       => 'navigator.webdriver',
		'clique_sem_mousemove'            => 'clique sem mousemove antes',
		'timing_regular_demais'           => 'timing regular demais (sem scroll)',
		'viewport_automacao_sem_plugins'  => 'viewport de automação + sem plugins',
		'sem_idiomas'                     => 'sem idiomas declarados',
		'movimento_mouse_sintetico'       => 'movimento de mouse sintético (poucos pontos/reto demais)',
		'clique_duracao_impossivel'       => 'clique rápido demais (mousedown→mouseup, exceto toque)',
		'digitacao_impossivel'            => 'digitação rápida demais (keydown→keyup, exceto auto-repeat)',
		'scroll_multiplo_viewport'        => 'parada de scroll em múltiplo exato da tela (fora do fim do documento)',
		'clique_nao_confiavel'            => 'clique com isTrusted=false (disparado via JavaScript)',
		'tecla_nao_confiavel'             => 'tecla com isTrusted=false',
		'input_nao_confiavel'             => 'input com isTrusted=false (ambíguo: agente OU gerenciador de senha/tradutor)',
		'evento_nao_confiavel'            => 'evento com isTrusted=false (motivo legado, ruleset v1)',
		'fetch_metadata_impossivel'       => 'Sec-Fetch-Mode/Dest inconsistentes (calculado no servidor)',
		'client_hints_incoerente'         => 'User-Agent e Sec-Ch-Ua declaram navegadores diferentes (calculado no servidor)',
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
 * LIMITE CONHECIDO (não corrigido, documentado): o Wilson assume amostras
 * i.i.d. Bernoulli com probabilidade constante, mas aqui P(sessão flagrada)
 * cresce com o número de páginas da sessão (mais páginas = mais chances de
 * QUALQUER uma das heurísticas acender por acaso). O IC abaixo é mais
 * estreito do que a incerteza real, e a prevalência é sensível ao
 * comprimento típico de sessão do período -- dois períodos com engajamento
 * diferente não são diretamente comparáveis mesmo com mesmo ruleset e mesmo
 * baseline_rate. Ver a prevalência POR PÁGINA abaixo, que não tem esse
 * acúmulo, como contraponto.
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
 *
 * LIMITE CONHECIDO (aceito, baixo risco): agrupa por ip_hash sem filtrar
 * ip_salt_epoch, o que em tese pode juntar duas origens diferentes cujo hash
 * colidiu entre épocas distintas do salt. Na prática uma sessão dura minutos
 * e a rotação do salt acompanha a retenção (dias), então o risco real é
 * desprezível.
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
 *  - tráfego marcado como dev fica fora;
 *  - sessão degradada (sem sessionStorage -- janela privada, WebView) vira
 *    uma "sessão" de 1 página cada vez, inflando o denominador por sessão
 *    sem a mesma chance de acender sinal que uma sessão real de várias
 *    páginas teria -- excluída aqui.
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
			   AND session_degradada = 0
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

	// Prevalência POR PÁGINA, em paralelo -- não acumula chance de acender
	// sinal com o comprimento da sessão (ver limite documentado em
	// dsi_uisensor_wilson), então serve de contraponto quando os dois
	// números divergem bastante. Precisa da MESMA quarentena da consulta por
	// sessão (achado em revisão externa, 2026-09-13: sem isso, a sessão
	// contaminada de 30 páginas/28 IPs do bug de v1 entrava com 30 páginas
	// no denominador e 0 no numerador, diluindo o número pra baixo e fazendo
	// o card divergir por um motivo diferente do que seu próprio texto
	// sugere -- quem lesse concluiria o oposto do certo).
	$rp = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT
			   COUNT(*) amostradas,
			   SUM(CASE WHEN heuristic_reasons <> '' THEN 1 ELSE 0 END) flagradas
			 FROM {$table}
			 WHERE sampled = 1
			   AND is_dev_traffic = 0
			   AND session_degradada = 0
			   AND recorded_at BETWEEN %s AND %s
			   AND {$quarentena}",
			$inicio_sql,
			$fim_sql
		)
	);
	$pag_amostradas = (int) ( $rp->amostradas ?? 0 );
	$pag_flagradas  = (int) ( $rp->flagradas ?? 0 );
	$pag_pct        = $pag_amostradas > 0 ? ( $pag_flagradas / $pag_amostradas ) * 100 : null;

	echo '<div style="display:flex;gap:16px;margin:20px 0;flex-wrap:wrap;">';
	printf(
		'<div style="background:#fff;border:1px solid #ccd0d4;padding:20px;min-width:300px;box-sizing:border-box;">
			<div style="font-size:13px;color:#646970;">Sessões com sinal de automação observável</div>
			<div style="font-size:36px;font-weight:600;line-height:1.2;color:%s;">%s</div>
			<div style="font-size:13px;color:#646970;">%s</div>
			<div style="font-size:12px;color:#646970;margin-top:8px;line-height:1.5;">
				Entre sessões sorteadas <strong>com interação</strong>. Agente que apenas lê não gera beacon e não entra em nenhum dos dois lados da conta — este número subestima. Sensível ao comprimento típico de sessão do período (ver "por página" ao lado).
			</div>
		</div>
		<div style="background:#fff;border:1px solid #ccd0d4;padding:20px;min-width:260px;box-sizing:border-box;">
			<div style="font-size:13px;color:#646970;">Páginas com sinal de automação (não agrupado por sessão)</div>
			<div style="font-size:36px;font-weight:600;line-height:1.2;">%s</div>
			<div style="font-size:13px;color:#646970;">%d de %d páginas sorteadas</div>
			<div style="font-size:12px;color:#646970;margin-top:8px;line-height:1.5;">
				Não acumula chance de acender sinal com sessões longas — se divergir muito do número por sessão, o comprimento de sessão do período está distorcendo a outra métrica.
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
			) ),
		$pag_pct === null ? '—' : esc_html( sprintf( '%.1f%%', $pag_pct ) ),
		$pag_flagradas,
		$pag_amostradas
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

	// Contador separado do de beacon desde a v6 (ver dsi_uisensor_conta_descarte):
	// linha de header flag é sampled=0 e nunca entra em nenhum denominador --
	// misturar os dois números no mesmo aviso superestimava o dano.
	$descartes_hdr = (int) get_option( 'dsi_uisensor_desc_hdr_' . current_time( 'Y-m-d' ), 0 );
	if ( $descartes_hdr > 0 ) {
		printf(
			'<div style="background:#fcf9e8;border:1px solid #dba617;padding:20px;max-width:46ch;box-sizing:border-box;font-size:13px;line-height:1.5;">
				<strong>%d requisições de header descartadas hoje por rate limit.</strong> Não afeta nenhum denominador (essas linhas não fazem parte da amostra) -- é só sinal de que GETs com header hostil estão sendo bloqueados antes de gravar.
			</div>',
			$descartes_hdr
		);
	}

	$falha = get_option( 'dsi_uisensor_ultima_falha' );
	if ( is_array( $falha ) && ! empty( $falha['erro'] ) ) {
		// Aviso expira sozinho depois de alguns dias -- sem isso, uma falha
		// já corrigida (ex.: migração aplicada) deixava o box vermelho na
		// tela indefinidamente, porque nada nunca "limpa" esta option.
		$idade_falha = current_time( 'timestamp' ) - strtotime( (string) $falha['quando'] );
		if ( $idade_falha <= 3 * DAY_IN_SECONDS ) {
			printf(
				'<div style="background:#fcf0f1;border:1px solid #d63638;padding:20px;max-width:46ch;box-sizing:border-box;font-size:13px;line-height:1.5;">
					<strong>Última falha de gravação:</strong> %s<br><code>%s</code><br>Migração de schema pendente?
				</div>',
				esc_html( (string) $falha['quando'] ),
				esc_html( (string) $falha['erro'] )
			);
		}
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

	// group_concat_max_len padrão do MySQL é 1024 bytes -- uma sessão longa
	// com várias combinações distintas de motivo estoura isso em silêncio, e
	// o corte cai no meio de um nome de motivo (explode(',') e
	// dsi_uisensor_motivo_label() depois exibem lixo cru na coluna "Motivos").
	// Nunca reportado em 6 rodadas de revisão, mas a sessão de 30 páginas já
	// conhecida é candidata (achado em revisão externa, 2026-09-14).
	$wpdb->query( 'SET SESSION group_concat_max_len = 8192' );

	$sessoes = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT session_id,
			        MIN(recorded_at) inicio,
			        COUNT(*) paginas,
			        TIMESTAMPDIFF(SECOND, MIN(recorded_at), MAX(recorded_at)) duracao_s,
			        SUM(n_clicks) cliques, SUM(n_scrolls) scrolls,
			        AVG(ms_since_prev_page) intervalo_medio,
			        STDDEV_SAMP(ms_since_prev_page) intervalo_desvio,
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
	echo '<p style="color:#646970;max-width:80ch;">Só sessões com 2+ páginas — é onde o ritmo entre páginas existe e pode ser medido. <strong>Ritmo</strong> é o desvio-padrão AMOSTRAL do intervalo dividido pela média (STDDEV_SAMP, não populacional -- com poucos intervalos a versão populacional subestima a variância real): perto de zero significa cadência de máquina (humano varia muito mais), só destacado com 4+ páginas (3+ intervalos). <strong>Scroll em múltiplo</strong> conta em quantas páginas da sessão houve parada em múltiplo exato da tela — uma página isolada é fraca, repetição é que vira evidência. Sessões com mais de uma origem distinta estão em quarentena e não aparecem aqui.</p>';
	echo '<table class="widefat striped"><thead><tr><th>Início</th><th>Páginas</th><th>Duração</th><th title="Páginas por minuto">Pág/min</th><th title="Desvio-padrão amostral do intervalo entre páginas / média. Baixo = cadência constante">Ritmo</th><th title="(cliques + scrolls) por página">Ações/pág</th><th title="Páginas da sessão com parada em múltiplo exato da tela">Scroll em múltiplo</th><th>Motivos</th><th>Amostra</th><th title="Regra vigente quando a linha foi gravada">Ruleset</th><th>Rede</th><th>País</th></tr></thead><tbody>';

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
		// significar algo -- exige 4+ páginas (3+ intervalos): com 3 páginas
		// (2 intervalos), STDDEV_SAMP ainda é instável demais pra confiar.
		$cv_suspeito = $cv !== null && $cv < 0.35 && $paginas >= 4;

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

/** Quantas linhas de cada versão de regra no período -- versões diferentes não são comparáveis. */
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
	echo '<strong>Período mistura versões de regra.</strong> O significado de alguns sinais muda entre versões do ruleset (ver Changelog do repositório) -- não compare taxa entre versões diferentes: ';
	$partes = [];
	foreach ( $linhas as $l ) {
		$partes[] = sprintf( 'v%d: %d linhas', (int) $l->rv, (int) $l->total );
	}
	echo esc_html( implode( ' · ', $partes ) );
	echo '</div>';
}

/**
 * Texto "amostra de X% das sessões" pro parágrafo de topo -- derivado do que
 * o PERÍODO realmente tem gravado, não da constante vigente agora (achado em
 * revisão externa, 2026-09-13: o aviso de mistura só aparece com 2+ taxas
 * distintas no período; um período inteiro sob uma taxa antiga não dispara
 * aviso nenhum e ficava rotulado com a taxa de hoje, mesmo se BASELINE_RATE
 * já tivesse mudado depois). Cai pra constante atual só quando o período não
 * tem nenhuma linha amostrada ainda (nada pra derivar).
 */
function dsi_uisensor_baseline_rate_texto( string $table, string $inicio_sql, string $fim_sql ): string {
	global $wpdb;

	$r = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT MIN(baseline_rate) mn, MAX(baseline_rate) mx
			 FROM {$table}
			 WHERE sampled = 1 AND recorded_at BETWEEN %s AND %s",
			$inicio_sql,
			$fim_sql
		)
	);

	if ( $r === null || $r->mn === null ) {
		return round( DSI_UISENSOR_BASELINE_RATE * 100 ) . '%';
	}

	$mn = round( (float) $r->mn * 100 );
	$mx = round( (float) $r->mx * 100 );

	return $mn === $mx ? "{$mn}%" : "entre {$mn}% e {$mx}%";
}

/** Mesma ideia de dsi_uisensor_render_rulesets, mas pra baseline_rate -- a coluna era gravada e nunca lida. */
function dsi_uisensor_render_baseline_rates( string $table, string $inicio_sql, string $fim_sql ): void {
	global $wpdb;

	$linhas = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT baseline_rate br, COUNT(*) total
			 FROM {$table}
			 WHERE sampled = 1 AND recorded_at BETWEEN %s AND %s
			 GROUP BY br ORDER BY br",
			$inicio_sql,
			$fim_sql
		)
	);

	if ( count( $linhas ) < 2 ) {
		return;
	}

	echo '<div style="background:#fcf9e8;border:1px solid #dba617;padding:16px;margin:16px 0;max-width:80ch;font-size:13px;line-height:1.5;">';
	echo '<strong>Período mistura taxas de amostragem diferentes.</strong> A prevalência acima usa todas as linhas sorteadas do período, mas elas foram coletadas sob <code>BASELINE_RATE</code> distinto -- dois períodos com taxas diferentes não são diretamente comparáveis, mesmo com o mesmo ruleset: ';
	$partes = [];
	foreach ( $linhas as $l ) {
		$pct        = $l->br === null ? null : round( (float) $l->br * 100, 1 );
		$partes[]   = ( $pct === null ? '?' : rtrim( rtrim( (string) $pct, '0' ), '.' ) ) . '%: ' . (int) $l->total . ' linhas';
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
	echo '<p style="color:#646970;max-width:80ch;">Navegador agêntico (Claude no Chrome, ChatGPT Atlas, Perplexity Comet) manda <strong>User-Agent de Chrome puro</strong> — não existe detecção por header, só por comportamento. Esta tela mede isso de duas formas: <strong>detecção</strong> (sessões que dispararam algum sinal de automação, registradas 100%) e <strong>baseline</strong> (amostra de ' . esc_html( dsi_uisensor_baseline_rate_texto( $table, $inicio_sql, $fim_sql ) ) . ' das sessões do período, sorteada às cegas, que serve de denominador). Nenhum sinal aqui prova que há um agente ou que não há uma pessoa: são evidências observáveis de interação programática.</p>';

	dsi_uisensor_aviso_backfill( $table );
	dsi_uisensor_render_rulesets( $table, $inicio_sql, $fim_sql );
	dsi_uisensor_render_baseline_rates( $table, $inicio_sql, $fim_sql );
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
	echo '<table class="widefat striped"><thead><tr><th>Data</th><th>URL</th><th>Motivos</th><th>Cliente (UA)</th><th>Rede</th><th>País</th><th>Cliques</th><th>Scrolls</th><th>Teclas</th><th>Inputs</th><th title="Fração de cada tipo de ação sobre o total da página, não contagem bruta">Perfil de ação</th><th>Viewport</th><th title="navigator.webdriver">WebDriver</th><th title="Houve mousemove antes do 1º clique?">Mousemove antes</th><th title="Comprimento do caminho / distância em linha reta -- perto de 1.0 = trajetória sintética">Retidão</th><th title="Distância total percorrida pelo mouse na sessão inteira, em pixels">Mouse (px)</th><th title="mousedown→mouseup do 1º clique. Negativo = timeStamp inconsistente de evento sintético">Duração clique (ms)</th><th title="keydown→keyup médio, pareado por tecla">Duração tecla (ms)</th><th title="Parada final de scroll / fim rolável do documento. Iguais = leu até o fim; diferentes com múltiplo exato = passo fixo de tela">Scroll (parada/fim)</th><th title="Paradas em múltiplo exato da tela, fora do fim do documento">Paradas múltiplo</th></tr></thead><tbody>';

	if ( ! $linhas ) {
		echo '<tr><td colspan="20">Nenhuma página com sinal nesse período.</td></tr>';
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
				'<tr><td>%s</td><td><code>%s</code></td><td>%s</td><td title="%s">%s</td><td><code>%s</code></td><td>%s</td><td>%d</td><td>%d</td><td>%d</td><td>%d</td><td>%s</td><td>%s×%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
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
			esc_html( dsi_uisensor_perfil_acao( (int) $row->n_clicks, (int) $row->n_scrolls, (int) $row->n_keydowns, (int) $row->n_inputs ) ),
			esc_html( (string) ( $row->viewport_w ?? '—' ) ),
			esc_html( (string) ( $row->viewport_h ?? '—' ) ),
			$row->navigator_webdriver ? 'sim' : 'não',
			esc_html( $mousemove_txt ),
			$row->first_click_straightness !== null ? esc_html( number_format( (float) $row->first_click_straightness, 3 ) ) : '—',
			isset( $row->total_mouse_dist_px ) && $row->total_mouse_dist_px !== null ? esc_html( number_format( (float) $row->total_mouse_dist_px, 0, ',', '.' ) ) : '—',
			$row->first_click_dwell_ms !== null ? esc_html( number_format( (float) $row->first_click_dwell_ms, 1 ) ) : '—',
			$row->mean_key_dwell_ms !== null ? esc_html( number_format( (float) $row->mean_key_dwell_ms, 1 ) ) : '—',
			esc_html( $scroll_txt ),
			$row->n_scroll_stops_multiplo !== null
				? esc_html( (int) $row->n_scroll_stops_multiplo . ' de ' . (int) $row->n_scroll_stops )
				: '—'
		);
	}

	echo '</tbody></table>';

	$ultima_purga = get_option( 'dsi_uisensor_ultima_purga' );

	printf(
		'<p style="color:#646970;max-width:80ch;margin-top:24px;font-size:13px;line-height:1.6;">
			<strong>Retenção:</strong> IP bruto é apagado da linha após %d dias; <code>ip_hash</code> (HMAC com salt do site) e <code>ip_prefix</code> (/24) seguem até %d dias, quando a linha inteira sai. O salt rotaciona junto com a purga — depois disso os hashes antigos deixam de ser vinculáveis a qualquer IP novo. Comparação por <code>ip_hash</code> só vale dentro do mesmo <code>ip_salt_epoch</code>. Última purga automática: %s (depende do wp-cron continuar rodando -- se essa data ficar velha, a promessa de retenção acima não está mais sendo cumprida).
		</p>',
		(int) DSI_UISENSOR_IP_RAW_DIAS,
		(int) DSI_UISENSOR_RETENCAO_DIAS,
		$ultima_purga ? esc_html( (string) $ultima_purga ) : 'nunca rodou ainda'
	);

	echo '</div>';
}
