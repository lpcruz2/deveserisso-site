<?php
/**
 * Plugin Name: DSI — Rate limit da API WebMCP
 * Description: Limita requisições por IP na API pública (/wp-json/webmcp/v1/*)
 *              e anuncia isso via headers RateLimit (draft-ietf-httpapi-ratelimit-headers).
 */

defined( 'ABSPATH' ) || exit;

const DSI_RL_NAMESPACE = 'webmcp/v1';
const DSI_RL_LIMIT     = 60;   // requisicoes
const DSI_RL_WINDOW    = 60;   // segundos

add_filter( 'rest_pre_dispatch', 'dsi_rl_check', 10, 3 );
add_filter( 'rest_post_dispatch', 'dsi_rl_add_headers', 10, 3 );

function dsi_rl_is_target_route( string $route ): bool {
	return str_starts_with( ltrim( $route, '/' ), DSI_RL_NAMESPACE );
}

/**
 * Mesmo IP que o log de bots usa -- ver dsi_agentmd_client_ip() em
 * dsi-ai-markdown.php pro porque de nao dar pra usar REMOTE_ADDR aqui.
 * Ate 2026-09-07 esta funcao devolvia o IP do edge do Cloudflare, o que
 * tornava o balde abaixo COLETIVO por edge em vez de por cliente: barrava
 * agente legitimo por culpa de terceiro e era burlavel por quem caisse
 * em outro edge.
 */
function dsi_rl_client_ip(): string {
	if ( function_exists( 'dsi_agentmd_client_ip' ) ) {
		$ip = dsi_agentmd_client_ip();
		if ( $ip !== '' ) {
			return $ip;
		}
	}

	return sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? 'desconhecido' );
}

function dsi_rl_state_key( string $ip ): string {
	return 'dsi_rl_' . md5( $ip );
}

/**
 * Le/incrementa o contador da janela atual pra esse IP. Guarda em transient
 * (nao precisa de tabela propria -- volume baixo, TTL curto).
 */
function dsi_rl_hit( string $ip ): array {
	$key   = dsi_rl_state_key( $ip );
	$state = get_transient( $key );
	$now   = time();

	if ( ! is_array( $state ) || $state['reset'] <= $now ) {
		$state = [ 'count' => 0, 'reset' => $now + DSI_RL_WINDOW ];
	}

	$state['count']++;
	set_transient( $key, $state, DSI_RL_WINDOW );

	return $state;
}

/**
 * Registra a chamada na mesma tabela/painel "Ferramentas -> Bots de IA" que
 * ja existe pra .md e HTML (dsi-ai-markdown.php, tipo='mcp' novo). Sem isso
 * nao havia nenhuma forma de saber se o MCP realmente foi chamado por algum
 * agente -- so dava pra confirmar que o endpoint respondia, testando na mao.
 * Reaproveita dsi_agentmd_classify_bot()/dsi_agentmd_log_request(), definidas
 * em dsi-ai-markdown.php (carrega antes, ordem alfabetica de mu-plugins).
 */
function dsi_rl_log( WP_REST_Request $request, WP_REST_Response $response ): void {
	if ( ! function_exists( 'dsi_agentmd_log_request' ) ) {
		return;
	}

	// Sem isso toda chamada de tool caia em post_id=0 e ficava
	// indistinguivel das outras no painel -- 263 chamadas colapsadas numa
	// linha so, sem dizer qual tool foi chamada nem se deu certo.
	$tool_name = preg_match( '#/tools/([^/?]+)#', $request->get_route(), $m ) ? $m[1] : null;

	$post_id = 0;
	if ( $tool_name === 'get_post' ) {
		$slug = (string) $request->get_param( 'slug' );
		$post = $slug !== '' ? get_page_by_path( $slug, OBJECT, [ 'post', 'page' ] ) : null;
		if ( $post instanceof WP_Post ) {
			$post_id = $post->ID;
		}
	}

	$user_agent = sanitize_text_field( $_SERVER['HTTP_USER_AGENT'] ?? '' );
	dsi_agentmd_log_request( $post_id, $user_agent, 'mcp', $tool_name, $response->get_status(), dsi_rl_response_body_for_log( $response ) );
}

/**
 * Serializa o corpo devolvido ao agente pra registro no log. Truncado pra
 * nao inflar a tabela -- get_post/list_posts devolvem conteudo inteiro de
 * post, e gravar isso sem limite multiplicaria o tamanho da tabela varias
 * vezes so pra chamadas de sucesso, que raramente precisam de auditoria
 * detalhada. Erro (4xx/5xx) ganha um limite maior porque e exatamente o
 * caso em que vale entender o que a API devolveu pro agente.
 */
function dsi_rl_response_body_for_log( WP_REST_Response $response ): ?string {
	$data = $response->get_data();
	if ( $data === null ) {
		return null;
	}

	$json = wp_json_encode( $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	if ( $json === false ) {
		return null;
	}

	$limite = $response->get_status() >= 400 ? 2000 : 500;
	return mb_substr( $json, 0, $limite );
}

/**
 * rest_pre_dispatch: roda antes do handler da rota. Retornar algo != null
 * interrompe o dispatch normal -- usado aqui so pra barrar com 429 quando
 * o limite estoura. Guarda o estado num static pra o hook de headers
 * (rest_post_dispatch) reaproveitar sem contar a requisicao 2x.
 */
function dsi_rl_check( $result, $server, $request ) {
	if ( $result !== null || ! dsi_rl_is_target_route( $request->get_route() ) ) {
		return $result;
	}

	$ip    = dsi_rl_client_ip();
	$state = dsi_rl_hit( $ip );

	$GLOBALS['dsi_rl_state'] = $state;

	// O log acontece no rest_post_dispatch, nao aqui: so la o status HTTP
	// final da chamada e conhecido (inclusive o 429 devolvido logo abaixo).

	if ( $state['count'] > DSI_RL_LIMIT ) {
		$response = new WP_REST_Response(
			[
				'code'    => 'rate_limit_exceeded',
				'message' => 'Limite de requisições excedido. Tente novamente em instantes.',
				'data'    => [ 'status' => 429 ],
			],
			429
		);
		$response->header( 'Retry-After', (string) max( 1, $state['reset'] - time() ) );
		return $response;
	}

	return $result;
}

/**
 * rest_post_dispatch: roda depois do dispatch (sucesso ou erro convertido
 * em WP_REST_Response), anexa os headers RateLimit na resposta final.
 */
function dsi_rl_add_headers( $response, $server, $request ) {
	if ( ! dsi_rl_is_target_route( $request->get_route() ) || ! ( $response instanceof WP_REST_Response ) ) {
		return $response;
	}

	$state = $GLOBALS['dsi_rl_state'] ?? null;
	if ( ! $state ) {
		return $response;
	}

	dsi_rl_log( $request, $response );

	$remaining = max( 0, DSI_RL_LIMIT - $state['count'] );
	$response->header( 'RateLimit-Limit', (string) DSI_RL_LIMIT );
	$response->header( 'RateLimit-Remaining', (string) $remaining );
	$response->header( 'RateLimit-Reset', (string) max( 0, $state['reset'] - time() ) );

	return $response;
}
