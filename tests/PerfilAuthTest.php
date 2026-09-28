<?php
/**
 * Testes da verificacao de token (dsi-magazine/inc/perfil/auth.php).
 * Gera uma chave RSA na hora, publica como JWKS e assina tokens de teste.
 */

use PHPUnit\Framework\TestCase;

final class PerfilAuthTest extends TestCase {

	private static $privada;
	private static array $jwks;
	private const EMISSOR = 'https://exemplo.authkit.app';
	private const RECURSO = 'https://staging.deveserisso.com.br/mcp/conta';
	private const AGORA   = 1790000000;

	public static function setUpBeforeClass(): void {
		if ( ! function_exists( 'openssl_pkey_new' ) ) {
			return;
		}
		self::$privada = openssl_pkey_new( [ 'private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA ] );
		$det           = openssl_pkey_get_details( self::$privada );
		self::$jwks    = [ 'keys' => [ [
			'kty' => 'RSA', 'use' => 'sig', 'alg' => 'RS256', 'kid' => 'chave-1',
			'n'   => dsi_perfil_b64url_encode( $det['rsa']['n'] ),
			'e'   => dsi_perfil_b64url_encode( $det['rsa']['e'] ),
		] ] ];
	}

	protected function setUp(): void {
		if ( ! self::$privada ) {
			$this->markTestSkipped( 'openssl indisponivel' );
		}
	}

	private function token( array $claims = [], array $cab = [] ): string {
		$cab    = array_merge( [ 'alg' => 'RS256', 'typ' => 'JWT', 'kid' => 'chave-1' ], $cab );
		$claims = array_merge( [ 'iss' => self::EMISSOR, 'aud' => self::RECURSO, 'sub' => 'user_01ABC', 'exp' => self::AGORA + 300, 'iat' => self::AGORA ], $claims );
		$base   = dsi_perfil_b64url_encode( json_encode( $cab ) ) . '.' . dsi_perfil_b64url_encode( json_encode( $claims ) );
		openssl_sign( $base, $sig, self::$privada, OPENSSL_ALGO_SHA256 );
		return $base . '.' . dsi_perfil_b64url_encode( $sig );
	}

	private function verificar( string $jwt ): array {
		return dsi_perfil_jwt_verificar( $jwt, self::$jwks, self::EMISSOR, self::RECURSO, self::AGORA );
	}

	private function falha( string $jwt, string $motivo ): void {
		try {
			$this->verificar( $jwt );
			$this->assertTrue( false, 'deveria falhar: ' . $motivo );
		} catch ( DSI_Perfil_Token_Invalido $e ) {
			$this->assertSame( $motivo, $e->getMessage() );
		}
	}

	public function test_token_valido(): void {
		$this->assertSame( 'user_01ABC', $this->verificar( $this->token() )['sub'] );
	}

	public function test_aud_em_lista_e_barra_final(): void {
		$this->assertSame( 'user_01ABC', $this->verificar( $this->token( [ 'aud' => [ 'outro', self::RECURSO . '/' ] ] ) )['sub'] );
	}

	public function test_assinatura_adulterada(): void {
		$t      = $this->token();
		[ $h, $c, $s ] = explode( '.', $t );
		$c2     = dsi_perfil_b64url_encode( json_encode( [ 'iss' => self::EMISSOR, 'aud' => self::RECURSO, 'sub' => 'user_INTRUSO', 'exp' => self::AGORA + 300 ] ) );
		$this->falha( "$h.$c2.$s", 'assinatura' );
	}

	public function test_alg_none_e_recusado(): void {
		$this->falha( $this->token( [], [ 'alg' => 'none' ] ), 'algoritmo' );
		$this->falha( $this->token( [], [ 'alg' => 'HS256' ] ), 'algoritmo' );
	}

	public function test_emissor_destinatario_validade(): void {
		$this->falha( $this->token( [ 'iss' => 'https://golpe.example' ] ), 'emissor' );
		$this->falha( $this->token( [ 'aud' => 'https://deveserisso.com.br/mcp/conta' ] ), 'destinatario' );
		$this->falha( $this->token( [ 'exp' => self::AGORA - 120 ] ), 'expirado' );
		$this->falha( $this->token( [ 'nbf' => self::AGORA + 600 ] ), 'ainda nao vale' );
		$this->falha( $this->token( [ 'sub' => '' ] ), 'sem sub' );
	}

	public function test_chave_desconhecida_e_formato(): void {
		$this->falha( $this->token( [], [ 'kid' => 'outra' ] ), 'chave desconhecida' );
		$this->falha( 'abc.def', 'formato' );
		$this->assertSame( 'chave-1', dsi_perfil_jwt_kid( $this->token() ) );
	}

	public function test_bearer(): void {
		$this->assertSame( 'a.b.c', dsi_perfil_bearer( [ 'HTTP_AUTHORIZATION' => 'Bearer a.b.c' ] ) );
		$this->assertSame( 'a.b.c', dsi_perfil_bearer( [ 'REDIRECT_HTTP_AUTHORIZATION' => 'bearer a.b.c' ] ) );
		$this->assertNull( dsi_perfil_bearer( [ 'HTTP_AUTHORIZATION' => 'Basic xyz' ] ) );
	}
}
