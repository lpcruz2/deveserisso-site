<?php
/**
 * Perfil de gosto -- verificacao do token de acesso (JWT) do provedor de
 * login (WorkOS AuthKit hoje). PHP puro com OpenSSL, sem biblioteca e sem
 * WordPress: o /mcp/conta valida o token em toda chamada (PRD, R7).
 *
 * Aceita so RS256. Confere assinatura (chave publica do JWKS pelo "kid"),
 * emissor, destinatario (aud = endereco do recurso MCP) e validade.
 */

final class DSI_Perfil_Token_Invalido extends RuntimeException {}

function dsi_perfil_b64url_decode( string $s ): string {
	$s = strtr( $s, '-_', '+/' );
	$r = base64_decode( $s . str_repeat( '=', ( 4 - strlen( $s ) % 4 ) % 4 ), true );
	if ( $r === false ) {
		throw new DSI_Perfil_Token_Invalido( 'base64 invalido' );
	}
	return $r;
}

function dsi_perfil_b64url_encode( string $s ): string {
	return rtrim( strtr( base64_encode( $s ), '+/', '-_' ), '=' );
}

/** Tamanho em DER (ASN.1). */
function dsi_perfil_der_tamanho( int $n ): string {
	if ( $n < 0x80 ) {
		return chr( $n );
	}
	$bytes = ltrim( pack( 'N', $n ), "\0" );
	return chr( 0x80 | strlen( $bytes ) ) . $bytes;
}

function dsi_perfil_der_inteiro( string $bytes ): string {
	$bytes = ltrim( $bytes, "\0" );
	if ( $bytes === '' || ord( $bytes[0] ) > 0x7f ) {
		$bytes = "\0" . $bytes; // inteiro positivo
	}
	return "\x02" . dsi_perfil_der_tamanho( strlen( $bytes ) ) . $bytes;
}

/** Chave RSA do JWKS (n, e) -> PEM "PUBLIC KEY" que o OpenSSL entende. */
function dsi_perfil_jwk_para_pem( array $jwk ): string {
	if ( ( $jwk['kty'] ?? '' ) !== 'RSA' || empty( $jwk['n'] ) || empty( $jwk['e'] ) ) {
		throw new DSI_Perfil_Token_Invalido( 'chave nao e RSA' );
	}
	$rsa  = dsi_perfil_der_inteiro( dsi_perfil_b64url_decode( $jwk['n'] ) ) . dsi_perfil_der_inteiro( dsi_perfil_b64url_decode( $jwk['e'] ) );
	$rsa  = "\x30" . dsi_perfil_der_tamanho( strlen( $rsa ) ) . $rsa;
	$alg  = "\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00"; // rsaEncryption
	$bit  = "\x03" . dsi_perfil_der_tamanho( strlen( $rsa ) + 1 ) . "\0" . $rsa;
	$spki = "\x30" . dsi_perfil_der_tamanho( strlen( $alg . $bit ) ) . $alg . $bit;
	return "-----BEGIN PUBLIC KEY-----\n" . chunk_split( base64_encode( $spki ), 64, "\n" ) . "-----END PUBLIC KEY-----\n";
}

/** kid do cabecalho, pra escolher a chave (e buscar o JWKS de novo se sumir). */
function dsi_perfil_jwt_kid( string $jwt ): ?string {
	$partes = explode( '.', $jwt );
	if ( count( $partes ) !== 3 ) {
		return null;
	}
	$h = json_decode( dsi_perfil_b64url_decode( $partes[0] ), true );
	return is_array( $h ) && isset( $h['kid'] ) ? (string) $h['kid'] : null;
}

/**
 * @param array $jwks    ['keys' => [...]] do /oauth2/jwks do emissor
 * @return array claims  (sub = id do usuario no provedor)
 * @throws DSI_Perfil_Token_Invalido
 */
function dsi_perfil_jwt_verificar( string $jwt, array $jwks, string $emissor, string $destinatario, int $agora, int $folga = 60 ): array {
	$partes = explode( '.', $jwt );
	if ( count( $partes ) !== 3 ) {
		throw new DSI_Perfil_Token_Invalido( 'formato' );
	}
	[ $h64, $c64, $s64 ] = $partes;
	$cab   = json_decode( dsi_perfil_b64url_decode( $h64 ), true );
	$claim = json_decode( dsi_perfil_b64url_decode( $c64 ), true );
	if ( ! is_array( $cab ) || ! is_array( $claim ) ) {
		throw new DSI_Perfil_Token_Invalido( 'json' );
	}
	if ( ( $cab['alg'] ?? '' ) !== 'RS256' ) {
		throw new DSI_Perfil_Token_Invalido( 'algoritmo' );
	}

	$chave = null;
	foreach ( $jwks['keys'] ?? [] as $k ) {
		if ( ( $k['kid'] ?? null ) === ( $cab['kid'] ?? null ) && ( $k['use'] ?? 'sig' ) === 'sig' ) {
			$chave = $k;
			break;
		}
	}
	if ( ! $chave ) {
		throw new DSI_Perfil_Token_Invalido( 'chave desconhecida' );
	}
	$ok = openssl_verify( $h64 . '.' . $c64, dsi_perfil_b64url_decode( $s64 ), dsi_perfil_jwk_para_pem( $chave ), OPENSSL_ALGO_SHA256 );
	if ( $ok !== 1 ) {
		throw new DSI_Perfil_Token_Invalido( 'assinatura' );
	}

	if ( rtrim( (string) ( $claim['iss'] ?? '' ), '/' ) !== rtrim( $emissor, '/' ) ) {
		throw new DSI_Perfil_Token_Invalido( 'emissor' );
	}
	$aud = (array) ( $claim['aud'] ?? [] );
	$alvo = rtrim( $destinatario, '/' );
	if ( ! in_array( $alvo, array_map( fn( $a ) => rtrim( (string) $a, '/' ), $aud ), true ) ) {
		throw new DSI_Perfil_Token_Invalido( 'destinatario' );
	}
	if ( ! isset( $claim['exp'] ) || (int) $claim['exp'] + $folga < $agora ) {
		throw new DSI_Perfil_Token_Invalido( 'expirado' );
	}
	if ( isset( $claim['nbf'] ) && (int) $claim['nbf'] - $folga > $agora ) {
		throw new DSI_Perfil_Token_Invalido( 'ainda nao vale' );
	}
	if ( empty( $claim['sub'] ) ) {
		throw new DSI_Perfil_Token_Invalido( 'sem sub' );
	}
	return $claim;
}

/** Token "Bearer" do cabecalho Authorization (varia conforme o servidor). */
function dsi_perfil_bearer( array $server ): ?string {
	$h = $server['HTTP_AUTHORIZATION'] ?? $server['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
	if ( $h === '' && function_exists( 'getallheaders' ) ) {
		foreach ( (array) getallheaders() as $k => $v ) {
			if ( strtolower( $k ) === 'authorization' ) {
				$h = (string) $v;
			}
		}
	}
	return preg_match( '/^Bearer\s+([A-Za-z0-9._~+\/=-]+)$/i', trim( $h ), $m ) ? $m[1] : null;
}
