<?php
/**
 * Plugin Name: DSI — Agente A2A (recomendação de filmes e séries)
 * Description: Servidor mínimo do protocolo A2A (JSON-RPC 2.0, v0.3) em
 *              /wp-json/a2a/v1/rpc. Uma habilidade: recomendar filmes e séries
 *              com crítica publicada. Somente leitura, sem LLM, sem estado.
 *
 * Segurança (decisões de 2026-10-15):
 * - Só lê. Reaproveita o endpoint interno dsi/v1/recomendar-filme SEM os
 *   parâmetros que escrevem em banco ou chamam serviço externo: não repassa
 *   `q` (busca ao vivo + cache na TMDB) nem `sessao_id` (grava no log do
 *   bilheteiro).
 * - Entradas em lista fechada (emoção, tipo, plataforma) ou saneadas com
 *   limite de tamanho (gênero). Texto livre só vira palavra-chave; nunca vai
 *   direto para consulta nem para prompt.
 * - Corpo limitado a 8 KB, só application/json, só POST.
 * - Limite por IP (20/min) e teto global de cálculos reais (30/min), com
 *   cache de 1 h por combinação de filtros. A recomendação varre todo o
 *   catálogo, e o servidor já devolve 500 sob rajada.
 * - Sem CORS: agente fala servidor a servidor.
 * - Sem autenticação e sem dado pessoal: tudo que sai já é público no site.
 */

defined( 'ABSPATH' ) || exit;

const DSI_A2A_NAMESPACE       = 'a2a/v1';
const DSI_A2A_MAX_BODY        = 8192;
const DSI_A2A_MAX_TEXT        = 500;
const DSI_A2A_LIMITE_IP       = 20;  // chamadas por minuto, por IP
const DSI_A2A_LIMITE_GLOBAL   = 30;  // cálculos reais (cache miss) por minuto
const DSI_A2A_CACHE_TTL       = HOUR_IN_SECONDS;
const DSI_A2A_MAX_ITENS       = 5;

add_action( 'rest_api_init', function (): void {
	register_rest_route( DSI_A2A_NAMESPACE, '/rpc', [
		'methods'             => 'POST',
		'callback'            => 'dsi_a2a_handle',
		'permission_callback' => '__return_true',
	] );
} );

/** Resposta JSON-RPC de erro. $http só difere de 200 em limite/tamanho. */
function dsi_a2a_error( $id, int $code, string $message, int $http = 200, array $headers = [] ): WP_REST_Response {
	$res = new WP_REST_Response( [
		'jsonrpc' => '2.0',
		'id'      => $id,
		'error'   => [ 'code' => $code, 'message' => $message ],
	], $http );
	foreach ( $headers as $k => $v ) {
		$res->header( $k, $v );
	}
	return $res;
}

function dsi_a2a_client_ip(): string {
	if ( function_exists( 'dsi_rl_client_ip' ) ) {
		return dsi_rl_client_ip();
	}
	return sanitize_text_field( $_SERVER['REMOTE_ADDR'] ?? 'desconhecido' );
}

/** Contador por janela de 60 s guardado em transient. Devolve [count, reset]. */
function dsi_a2a_hit( string $key ): array {
	$state = get_transient( $key );
	$now   = time();
	if ( ! is_array( $state ) || $state['reset'] <= $now ) {
		$state = [ 'count' => 0, 'reset' => $now + 60 ];
	}
	$state['count']++;
	set_transient( $key, $state, 60 );
	return [ $state['count'], $state['reset'] ];
}

function dsi_a2a_handle( WP_REST_Request $request ) {
	// Só JSON.
	if ( stripos( (string) $request->get_header( 'content-type' ), 'application/json' ) === false ) {
		return dsi_a2a_error( null, -32600, 'Content-Type deve ser application/json', 415 );
	}

	// Limite por IP antes de qualquer processamento.
	[ $count, $reset ] = dsi_a2a_hit( 'dsi_a2a_ip_' . md5( dsi_a2a_client_ip() ) );
	if ( $count > DSI_A2A_LIMITE_IP ) {
		return dsi_a2a_error( null, -32000, 'Limite de chamadas excedido, tente em instantes', 429, [ 'Retry-After' => (string) max( 1, $reset - time() ) ] );
	}

	$body = $request->get_body();
	if ( strlen( $body ) > DSI_A2A_MAX_BODY ) {
		return dsi_a2a_error( null, -32600, 'Corpo maior que o permitido', 413 );
	}

	$rpc = json_decode( $body, true );
	if ( ! is_array( $rpc ) || array_is_list( $rpc ) ) {
		// Lote (array) não é suportado: evita multiplicar custo numa chamada.
		return dsi_a2a_error( null, -32700, 'JSON inválido ou lote não suportado' );
	}

	$id = $rpc['id'] ?? null;
	if ( ! is_int( $id ) && ! is_string( $id ) ) {
		$id = null;
	}
	if ( ( $rpc['jsonrpc'] ?? '' ) !== '2.0' || ! is_string( $rpc['method'] ?? null ) ) {
		return dsi_a2a_error( $id, -32600, 'Requisição JSON-RPC 2.0 inválida' );
	}

	$method = $rpc['method'];
	$params = is_array( $rpc['params'] ?? null ) ? $rpc['params'] : [];

	switch ( $method ) {
		case 'message/send':
			$result = dsi_a2a_message_send( $params );
			if ( $result instanceof WP_REST_Response ) {
				return $result; // limite global
			}
			dsi_a2a_log( 'message/send' );
			return new WP_REST_Response( [ 'jsonrpc' => '2.0', 'id' => $id, 'result' => $result ], 200 );

		case 'tasks/get':
		case 'tasks/cancel':
			// Sem estado: toda tarefa termina na própria resposta.
			return dsi_a2a_error( $id, -32001, 'Tarefa não encontrada: este agente conclui tudo em message/send e não guarda tarefas' );

		case 'message/stream':
		case 'tasks/resubscribe':
		case 'tasks/pushNotificationConfig/set':
		case 'tasks/pushNotificationConfig/get':
			return dsi_a2a_error( $id, -32004, 'Operação não suportada por este agente' );

		default:
			return dsi_a2a_error( $id, -32601, 'Método não encontrado' );
	}
}

function dsi_a2a_log( string $method ): void {
	if ( function_exists( 'dsi_agentmd_log_request' ) ) {
		$ua = sanitize_text_field( $_SERVER['HTTP_USER_AGENT'] ?? '' );
		dsi_agentmd_log_request( 0, $ua, 'mcp', 'a2a:' . $method, 200, null );
	}
}

/** Remove acento e caixa para casar palavra-chave. */
function dsi_a2a_norm( string $s ): string {
	$s = mb_strtolower( $s, 'UTF-8' );
	$s = remove_accents( $s );
	return preg_replace( '/[^a-z0-9 ]+/', ' ', $s );
}

/**
 * Extrai filtros da mensagem A2A. Aceita partes `text` (palavras-chave) e
 * `data` (campos estruturados). Tudo que não está nas listas fechadas é
 * descartado.
 */
function dsi_a2a_extract_filters( array $message ): array {
	$emocoes     = [
		'rir'        => [ 'rir', 'comedia', 'engracado', 'divertido', 'humor' ],
		'medo'       => [ 'medo', 'terror', 'assustador', 'horror', 'susto' ],
		'chorar'     => [ 'chorar', 'emocionante', 'drama', 'triste', 'emocao' ],
		'adrenalina' => [ 'adrenalina', 'acao', 'aventura', 'tensao', 'suspense' ],
		'paixao'     => [ 'paixao', 'romance', 'romantico', 'amor' ],
	];
	$plataformas = [
		'netflix'      => [ 'netflix' ],
		'amazon-prime' => [ 'prime', 'amazon' ],
		'globoplay'    => [ 'globoplay', 'globo play' ],
		'disney'       => [ 'disney' ],
		'telecine'     => [ 'telecine' ],
		'appletv'      => [ 'apple tv', 'appletv', 'apple' ],
	];

	$filtros = [];
	$texto   = '';
	$partes  = is_array( $message['parts'] ?? null ) ? array_slice( $message['parts'], 0, 5 ) : [];

	foreach ( $partes as $parte ) {
		if ( ! is_array( $parte ) ) {
			continue;
		}
		$kind = $parte['kind'] ?? ( $parte['type'] ?? '' );
		if ( $kind === 'text' && is_string( $parte['text'] ?? null ) ) {
			$texto .= ' ' . mb_substr( $parte['text'], 0, DSI_A2A_MAX_TEXT, 'UTF-8' );
		} elseif ( $kind === 'data' && is_array( $parte['data'] ?? null ) ) {
			$d = $parte['data'];
			if ( is_string( $d['emocao'] ?? null ) && isset( $emocoes[ $d['emocao'] ] ) ) {
				$filtros['emocao'] = $d['emocao'];
			}
			if ( is_string( $d['plataforma'] ?? null ) && isset( $plataformas[ $d['plataforma'] ] ) ) {
				$filtros['plataforma'] = $d['plataforma'];
			}
			if ( in_array( $d['tipo'] ?? null, [ 'filme', 'serie' ], true ) ) {
				$filtros['tipo'] = $d['tipo'];
			}
			if ( is_string( $d['genero'] ?? null ) ) {
				$g = trim( preg_replace( '/[^\p{L} ]+/u', '', mb_substr( $d['genero'], 0, 40, 'UTF-8' ) ) );
				if ( $g !== '' ) {
					$filtros['genero'] = $g;
				}
			}
			if ( isset( $d['limite'] ) && is_numeric( $d['limite'] ) ) {
				$filtros['limite'] = max( 1, min( DSI_A2A_MAX_ITENS, (int) $d['limite'] ) );
			}
		}
	}

	$norm = ' ' . dsi_a2a_norm( $texto ) . ' ';
	if ( trim( $norm ) !== '' ) {
		$achar = static function ( array $mapa ) use ( $norm ): ?string {
			foreach ( $mapa as $valor => $palavras ) {
				foreach ( $palavras as $p ) {
					if ( str_contains( $norm, ' ' . $p . ' ' ) ) {
						return $valor;
					}
				}
			}
			return null;
		};
		if ( ! isset( $filtros['emocao'] ) && ( $v = $achar( $emocoes ) ) ) {
			$filtros['emocao'] = $v;
		}
		if ( ! isset( $filtros['plataforma'] ) && ( $v = $achar( $plataformas ) ) ) {
			$filtros['plataforma'] = $v;
		}
		if ( ! isset( $filtros['tipo'] ) ) {
			if ( str_contains( $norm, ' serie ' ) || str_contains( $norm, ' series ' ) ) {
				$filtros['tipo'] = 'serie';
			} elseif ( str_contains( $norm, ' filme ' ) || str_contains( $norm, ' filmes ' ) ) {
				$filtros['tipo'] = 'filme';
			}
		}
	}

	return $filtros;
}

function dsi_a2a_task( string $state, array $artifacts, string $mensagem ): array {
	$task = [
		'kind'      => 'task',
		'id'        => wp_generate_uuid4(),
		'contextId' => wp_generate_uuid4(),
		'status'    => [
			'state'     => $state,
			'timestamp' => gmdate( 'c' ),
			'message'   => [
				'kind'      => 'message',
				'role'      => 'agent',
				'messageId' => wp_generate_uuid4(),
				'parts'     => [ [ 'kind' => 'text', 'text' => $mensagem ] ],
			],
		],
	];
	if ( $artifacts ) {
		$task['artifacts'] = $artifacts;
	}
	return $task;
}

/** @return array|WP_REST_Response Task, ou resposta de erro de limite global. */
function dsi_a2a_message_send( array $params ) {
	$message = is_array( $params['message'] ?? null ) ? $params['message'] : [];
	$filtros = dsi_a2a_extract_filters( $message );

	// Sem nenhum filtro reconhecido, pede informação em vez de varrer o catálogo.
	if ( ! array_intersect_key( $filtros, array_flip( [ 'emocao', 'plataforma', 'tipo', 'genero' ] ) ) ) {
		return dsi_a2a_task(
			'input-required',
			[],
			'Diga o que você quer assistir. Exemplos: "filme de terror na Netflix", "série de comédia no Globoplay". Também aceito dados estruturados: emocao (rir, medo, chorar, adrenalina, paixao), tipo (filme, serie), plataforma (netflix, amazon-prime, globoplay, disney, telecine, appletv) e genero.'
		);
	}

	$limite = $filtros['limite'] ?? 3;
	unset( $filtros['limite'] );
	ksort( $filtros );
	$cache_key = 'dsi_a2a_c_' . md5( wp_json_encode( [ $filtros, $limite ] ) );
	$itens     = get_transient( $cache_key );

	if ( ! is_array( $itens ) ) {
		// Cache miss: cálculo real, sujeito ao teto global.
		[ $count, $reset ] = dsi_a2a_hit( 'dsi_a2a_global' );
		if ( $count > DSI_A2A_LIMITE_GLOBAL ) {
			return dsi_a2a_error( null, -32000, 'Agente ocupado, tente em instantes', 429, [ 'Retry-After' => (string) max( 1, $reset - time() ) ] );
		}

		// Só filtros da lista; sem `q` e sem `sessao_id` (ver cabeçalho).
		$interno = new WP_REST_Request( 'GET', '/dsi/v1/recomendar-filme' );
		$interno->set_query_params( $filtros + [ 'limite' => $limite ] );
		$resposta = rest_do_request( $interno );
		// A rota interna manda CORS aberto; este endpoint não deve herdar.
		if ( ! headers_sent() ) {
			header_remove( 'Access-Control-Allow-Origin' );
		}

		$dados = $resposta->get_data();
		$itens = [];
		if ( $resposta->get_status() === 200 && is_array( $dados['itemListElement'] ?? null ) ) {
			foreach ( array_slice( $dados['itemListElement'], 0, $limite ) as $f ) {
				if ( empty( $f['titulo'] ) || empty( $f['link'] ) ) {
					continue;
				}
				$itens[] = [
					'titulo'  => (string) $f['titulo'],
					'tipo'    => (string) ( $f['tipo'] ?? '' ),
					'ano'     => (string) ( $f['ano'] ?? '' ),
					'generos' => array_values( array_map( 'strval', (array) ( $f['genero'] ?? [] ) ) ),
					'nota'    => isset( $f['nota'] ) ? (float) $f['nota'] : null,
					'sinopse' => (string) ( $f['sinopse'] ?? '' ),
					'link'    => esc_url_raw( (string) $f['link'] ),
				];
			}
		}
		set_transient( $cache_key, $itens, DSI_A2A_CACHE_TTL );
	}

	if ( ! $itens ) {
		return dsi_a2a_task( 'completed', [], 'Não encontrei título com crítica publicada para esses filtros. Tente outra emoção, plataforma ou tipo.' );
	}

	$linhas = [];
	foreach ( $itens as $i => $it ) {
		$linhas[] = ( $i + 1 ) . '. ' . $it['titulo'] . ( $it['ano'] !== '' ? ' (' . $it['ano'] . ')' : '' ) . ' — ' . $it['link'];
	}

	return dsi_a2a_task(
		'completed',
		[ [
			'artifactId' => wp_generate_uuid4(),
			'name'       => 'recomendacoes',
			'parts'      => [
				[ 'kind' => 'text', 'text' => implode( "\n", $linhas ) ],
				[ 'kind' => 'data', 'data' => [ 'filtros' => $filtros, 'recomendacoes' => $itens ] ],
			],
		] ],
		'Encontrei ' . count( $itens ) . ' título(s) com crítica publicada no Deveserisso.'
	);
}
