<?php
/**
 * Testes da logica pura de "onde assistir" (dsi-magazine/inc/onde-assistir.php), 2026-10-10.
 * A parte que fala com o TMDB e com o cache nao e testada aqui (depende do WordPress).
 */

use PHPUnit\Framework\TestCase;

final class OndeAssistirTest extends TestCase {

	public function test_normalizar_tira_acento_caixa_e_pontuacao(): void {
		$this->assertSame( 'alien o oitavo passageiro', dsi_onde_assistir_normalizar( ' Alien: O Oitavo Passageiro ' ) );
		$this->assertSame( 'a forma da agua', dsi_onde_assistir_normalizar( 'A Forma da Água' ) );
	}

	public function test_intencao_onde_assistir_e_reconhecida_com_titulo_valido(): void {
		$r = dsi_a2ui_interpretar_resposta( '{"resposta":"Vou conferir.","intencao":"onde_assistir","titulo":2}', 5 );
		$this->assertSame( 'onde_assistir', $r['intencao'] );
		$this->assertSame( 2, $r['titulo'] );
	}

	public function test_intencao_onde_assistir_com_titulo_fora_da_lista_vira_outra(): void {
		$r = dsi_a2ui_interpretar_resposta( '{"resposta":"Vou conferir.","intencao":"onde_assistir","titulo":9}', 5 );
		$this->assertSame( 'outra', $r['intencao'] );
		$this->assertNull( $r['titulo'] );
	}

	public function test_escolher_exige_titulo_e_ano_iguais(): void {
		$res = [
			[ 'media_type' => 'movie', 'id' => 1, 'title' => 'Us', 'release_date' => '2019-03-14', 'popularity' => 50 ],
			[ 'media_type' => 'movie', 'id' => 2, 'title' => 'Us', 'release_date' => '1990-01-01', 'popularity' => 99 ],
			[ 'media_type' => 'person', 'id' => 3, 'name' => 'Us', 'popularity' => 999 ],
		];
		$this->assertSame( [ 'id' => 1, 'tipo' => 'filme' ], dsi_onde_assistir_escolher( $res, 'Us', 2019 ) );
		$this->assertNull( dsi_onde_assistir_escolher( $res, 'Us', 2005 ) );
	}

	public function test_escolher_sem_ano_so_aceita_obra_unica(): void {
		$um = [ [ 'media_type' => 'tv', 'id' => 7, 'name' => 'Breaking Bad', 'first_air_date' => '2008-01-20' ] ];
		$this->assertSame( [ 'id' => 7, 'tipo' => 'serie' ], dsi_onde_assistir_escolher( $um, 'Breaking Bad', 0 ) );
		$dois = array_merge( $um, [ [ 'media_type' => 'movie', 'id' => 8, 'title' => 'Breaking Bad', 'release_date' => '2020-01-01' ] ] );
		$this->assertNull( dsi_onde_assistir_escolher( $dois, 'Breaking Bad', 0 ) );
	}

	public function test_escolher_aceita_titulo_original(): void {
		$res = [ [ 'media_type' => 'movie', 'id' => 5, 'title' => 'O Iluminado', 'original_title' => 'The Shining', 'release_date' => '1980-05-23' ] ];
		$this->assertSame( [ 'id' => 5, 'tipo' => 'filme' ], dsi_onde_assistir_escolher( $res, 'The Shining', 1980 ) );
	}

	public function test_texto_lista_cada_grupo_sem_repetir(): void {
		$br = [
			'flatrate' => [ [ 'provider_name' => 'Max' ], [ 'provider_name' => 'Prime Video' ], [ 'provider_name' => 'Max' ] ],
			'rent'     => [ [ 'provider_name' => 'Apple TV' ] ],
			'buy'      => [ [ 'provider_name' => 'Apple TV' ], [ 'provider_name' => 'Google Play' ] ],
		];
		$t = dsi_onde_assistir_texto( 'O Iluminado', $br );
		$this->assertStringContainsString( 'Assinatura: Max, Prime Video.', $t );
		$this->assertStringContainsString( 'Aluguel: Apple TV.', $t );
		$this->assertStringContainsString( 'Compra: Apple TV, Google Play.', $t );
		$this->assertStringContainsString( 'JustWatch', $t );
		$this->assertStringNotContainsString( 'Grátis', $t );
	}

	public function test_texto_sem_disponibilidade_nao_inventa(): void {
		$t = dsi_onde_assistir_texto( 'Filme X', [ 'link' => 'https://www.themoviedb.org/movie/1/watch?locale=BR' ] );
		$this->assertStringContainsString( 'Não encontrei "Filme X"', $t );
		$this->assertStringNotContainsString( 'Assinatura', $t );
	}

	public function test_texto_com_falha_na_consulta_diz_que_nao_conseguiu(): void {
		$t = dsi_onde_assistir_texto( 'Filme X', null );
		$this->assertStringContainsString( 'Não consegui consultar agora', $t );
	}
}
