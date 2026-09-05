<?php
/**
 * Plugin Name: DSI — Verificação Web Bot Auth (RFC 9421 / RFC 9651)
 * Description: Verifica a assinatura HTTP Message Signatures (headers Signature,
 *              Signature-Input, Signature-Agent) quando presente numa requisição,
 *              pra identificar agentes verificados (ex: ChatGPT Operator) sem
 *              depender de Cloudflare Enterprise + Bot Management -- confirmado
 *              com a própria IA de suporte da Cloudflare que esse é um fluxo
 *              suportado e independente de plano (os headers passam intactos
 *              pelo proxy até a origem).
 */

defined( 'ABSPATH' ) || exit;

const DSI_WBA_JWKS_CACHE_TTL = 3600; // 1h de cache do diretorio de chaves por dominio
const DSI_WBA_CLOCK_SKEW     = 60;   // tolerancia de relogio, em segundos

/**
 * Ponto de entrada para uso em produção: verifica a requisição HTTP atual.
 * Retorna o domínio do agente (valor normalizado do header Signature-Agent)
 * se a assinatura for válida e estiver dentro da janela de validade, ou
 * null se não houver assinatura, ela for inválida, expirada, ou faltar a
 * extensão sodium (libsodium, built-in desde PHP 7.2) no servidor.
 *
 * Falha sempre pro lado seguro: qualquer ambiguidade de parsing retorna
 * null (nao verificado), nunca lanca excecao nem quebra a pagina. So um
 * unico label de assinatura por requisicao e suportado (o formato mais
 * comum do Web Bot Auth) -- multiplas assinaturas na mesma requisicao nao
 * sao tratadas.
 */
function dsi_wba_verify_current_request(): ?string {
	if ( ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
		return null;
	}

	$sig_header       = $_SERVER['HTTP_SIGNATURE'] ?? '';
	$sig_input_header = $_SERVER['HTTP_SIGNATURE_INPUT'] ?? '';
	$sig_agent_raw    = $_SERVER['HTTP_SIGNATURE_AGENT'] ?? '';

	if ( $sig_header === '' || $sig_input_header === '' || $sig_agent_raw === '' ) {
		return null;
	}

	$parsed = dsi_wba_parse_signature_input( $sig_input_header );
	if ( ! $parsed ) {
		return null;
	}
	[ $label, $components, $params, $params_raw ] = $parsed;

	$now     = time();
	$created = isset( $params['created'] ) ? (int) $params['created'] : null;
	$expires = isset( $params['expires'] ) ? (int) $params['expires'] : null;
	if ( $created !== null && $created > $now + DSI_WBA_CLOCK_SKEW ) {
		return null; // assinado no futuro -- suspeito
	}
	if ( $expires !== null && $expires < $now - DSI_WBA_CLOCK_SKEW ) {
		return null; // expirada
	}

	$signature_b64 = dsi_wba_parse_signature( $sig_header, $label );
	if ( ! $signature_b64 ) {
		return null;
	}
	$signature_bytes = base64_decode( $signature_b64, true );
	if ( $signature_bytes === false ) {
		return null;
	}

	$signature_base = dsi_wba_build_signature_base( $components, $params_raw );
	if ( $signature_base === null ) {
		return null;
	}

	$agent_host  = dsi_wba_normalize_agent_host( $sig_agent_raw );
	$public_key  = dsi_wba_resolve_public_key( $sig_agent_raw, $params['keyid'] ?? '' );
	if ( ! $public_key ) {
		return null;
	}

	$valid = dsi_wba_verify_signature_base( $signature_base, $signature_bytes, $public_key );

	return $valid ? $agent_host : null;
}

/**
 * Crypto pura: assinatura + base ja construidas + chave publica bruta (32
 * bytes). Separada do resto pra poder ser testada isoladamente contra o
 * vetor de teste oficial da RFC 9421 (Apendice B.1.4/B.2.6), sem precisar
 * de uma requisicao real assinada por um agente de verdade.
 */
function dsi_wba_verify_signature_base( string $signature_base, string $signature_bytes, string $public_key_raw ): bool {
	if ( ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
		return false;
	}
	try {
		return sodium_crypto_sign_verify_detached( $signature_bytes, $signature_base, $public_key_raw );
	} catch ( SodiumException $e ) {
		return false;
	}
}

/**
 * Parseia "Signature-Input: label=("comp1" "comp2" ...);param=valor;..."
 * Retorna [label, componentes (ordem preservada), params (assoc), raw_params]
 * onde raw_params e o trecho exato depois do "label=" -- reaproveitado
 * verbatim como valor de "@signature-params" na base da assinatura (RFC
 * 9421 secao 2.5), em vez de re-serializar os parametros manualmente
 * (evita bug sutil de formatacao/ordem que produziria uma base errada).
 */
function dsi_wba_parse_signature_input( string $header ): ?array {
	if ( ! preg_match( '/^\s*([A-Za-z0-9_-]+)=(\([^)]*\).*)$/s', trim( $header ), $m ) ) {
		return null;
	}
	[ , $label, $raw_params ] = $m;

	if ( ! preg_match( '/^\(([^)]*)\)/', $raw_params, $inner_m ) ) {
		return null;
	}
	preg_match_all( '/"([^"]*)"/', $inner_m[1], $comp_matches );
	$components = $comp_matches[1];
	if ( empty( $components ) ) {
		return null;
	}

	$rest   = substr( $raw_params, strlen( $inner_m[0] ) );
	$params = [];
	foreach ( explode( ';', trim( $rest, "; \t" ) ) as $pair ) {
		$pair = trim( $pair );
		if ( $pair === '' || ! str_contains( $pair, '=' ) ) {
			continue;
		}
		[ $key, $val ] = explode( '=', $pair, 2 );
		$params[ trim( $key ) ] = trim( trim( $val ), '"' );
	}

	return [ $label, $components, $params, $raw_params ];
}

/** Extrai a assinatura em base64 de "Signature: label=:base64bytes:" pelo label */
function dsi_wba_parse_signature( string $header, string $label ): ?string {
	$label_q = preg_quote( $label, '/' );
	if ( ! preg_match( '/' . $label_q . '=:([A-Za-z0-9+\/=]+):/', $header, $m ) ) {
		return null;
	}
	return $m[1];
}

/**
 * Monta a "signature base" (RFC 9421 secao 2.5): uma linha por componente
 * coberto, na ordem declarada, mais a linha final "@signature-params".
 */
function dsi_wba_build_signature_base( array $components, string $params_raw ): ?string {
	$lines = [];
	foreach ( $components as $name ) {
		$value = dsi_wba_resolve_component( $name );
		if ( $value === null ) {
			return null;
		}
		$lines[] = '"' . $name . '": ' . $value;
	}
	$lines[] = '"@signature-params": ' . $params_raw;
	return implode( "\n", $lines );
}

/** Resolve o valor de um componente (derivado, tipo @method, ou header comum) */
function dsi_wba_resolve_component( string $name ): ?string {
	if ( $name === '' ) {
		return null;
	}

	if ( $name[0] === '@' ) {
		switch ( $name ) {
			case '@method':
				return strtoupper( $_SERVER['REQUEST_METHOD'] ?? '' );
			case '@authority':
				return strtolower( $_SERVER['HTTP_HOST'] ?? '' );
			case '@scheme':
				return 'https';
			case '@path':
				$path = parse_url( $_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH );
				return is_string( $path ) ? $path : null;
			case '@target-uri':
				return 'https://' . ( $_SERVER['HTTP_HOST'] ?? '' ) . ( $_SERVER['REQUEST_URI'] ?? '' );
			case '@request-target':
				return strtolower( $_SERVER['REQUEST_METHOD'] ?? '' ) . ' ' . ( $_SERVER['REQUEST_URI'] ?? '' );
			default:
				return null; // componente derivado nao suportado -- falha segura
		}
	}

	// PHP nao prefixa Content-Type/Content-Length com HTTP_ (excecao do SAPI)
	$special = [ 'content-type' => 'CONTENT_TYPE', 'content-length' => 'CONTENT_LENGTH' ];
	if ( isset( $special[ $name ] ) ) {
		return $_SERVER[ $special[ $name ] ] ?? null;
	}

	$server_key = 'HTTP_' . strtoupper( str_replace( '-', '_', $name ) );
	return $_SERVER[ $server_key ] ?? null;
}

/** "chatgpt.com" ou "https://chatgpt.com" -> "chatgpt.com" */
function dsi_wba_normalize_agent_host( string $value ): string {
	$value = trim( $value, '"' );
	if ( str_contains( $value, '://' ) ) {
		$host = parse_url( $value, PHP_URL_HOST );
		return $host ?: $value;
	}
	return $value;
}

/** base64url (RFC 4648 sec 5, usado em JWK) -> bytes */
function dsi_wba_base64url_decode( string $data ): string {
	$data = strtr( $data, '-_', '+/' );
	$pad  = strlen( $data ) % 4;
	if ( $pad ) {
		$data .= str_repeat( '=', 4 - $pad );
	}
	$decoded = base64_decode( $data, true );
	return $decoded === false ? '' : $decoded;
}

/**
 * Busca o diretorio JWKS em /.well-known/http-message-signatures-directory
 * do dominio indicado em Signature-Agent, cacheado por 1h (ou 5 min em
 * caso de falha, pra nao martelar um dominio fora do ar a cada requisicao).
 * Retorna a chave publica Ed25519 (32 bytes brutos) que casa com o keyid.
 */
function dsi_wba_resolve_public_key( string $sig_agent_raw, string $keyid ): ?string {
	if ( $keyid === '' ) {
		return null;
	}

	$base = trim( $sig_agent_raw, '"' );
	if ( ! str_contains( $base, '://' ) ) {
		$base = 'https://' . $base;
	}
	$directory_url = rtrim( $base, '/' ) . '/.well-known/http-message-signatures-directory';

	$cache_key = 'dsi_wba_jwks_' . md5( $directory_url );
	$jwks      = get_transient( $cache_key );

	if ( $jwks === false ) {
		$resp = wp_remote_get( $directory_url, [ 'timeout' => 5 ] );
		if ( is_wp_error( $resp ) || wp_remote_retrieve_response_code( $resp ) !== 200 ) {
			set_transient( $cache_key, [ 'keys' => [] ], 300 );
			return null;
		}
		$decoded = json_decode( wp_remote_retrieve_body( $resp ), true );
		$jwks    = is_array( $decoded ) ? $decoded : [ 'keys' => [] ];
		set_transient( $cache_key, $jwks, DSI_WBA_JWKS_CACHE_TTL );
	}

	foreach ( $jwks['keys'] ?? [] as $key ) {
		if ( ( $key['kid'] ?? '' ) === $keyid
			&& ( $key['kty'] ?? '' ) === 'OKP'
			&& ( $key['crv'] ?? '' ) === 'Ed25519'
			&& isset( $key['x'] ) ) {
			return dsi_wba_base64url_decode( $key['x'] );
		}
	}
	return null;
}
