<?php
/**
 * Testes dos filtros por dados do TMDB (dsi-magazine/inc/filtros-catalogo.php), 2026-10-11.
 * Casos reais: "comedia com protagonista mulher" (pagina de O Advogado do Diabo) e "meus filhos tem 6 anos".
 */

use PHPUnit\Framework\TestCase;

final class FiltrosCatalogoTest extends TestCase {

	public function test_protagonista_feminina_e_reconhecida_em_varias_formas(): void {
		foreach ( [
			'Quero filmes de comedia quero com protagonista mulher',
			'comédia com protagonista feminina',
			'quero uma mulher protagonista',
			'filme protagonizado por mulheres',
			'algo com uma mulher no centro da história',
			'quero heroínas',
		] as $m ) {
			$this->assertSame( 'feminina', dsi_filtros_extrair_do_texto( $m )['protagonista'], $m );
		}
	}

	public function test_sem_pedido_de_protagonista_nada_e_extraido(): void {
		foreach ( [ 'Quero um filme de terror', 'com Charlize Theron', 'o protagonista é bom?', 'filmes de ação' ] as $m ) {
			$r = dsi_filtros_extrair_do_texto( $m );
			$this->assertNull( $r['protagonista'], $m );
			$this->assertNull( $r['idade'], $m );
		}
	}

	public function test_idade_de_crianca_e_extraida(): void {
		$this->assertSame( 7, dsi_filtros_extrair_do_texto( 'Quero algo pra ver com minha filha de 7 anos.' )['idade'] );
		$this->assertSame( 6, dsi_filtros_extrair_do_texto( 'Meus filhos têm 6 anos, o que tem de bom?' )['idade'] );
		$this->assertSame( 9, dsi_filtros_extrair_do_texto( 'para meu sobrinho de 9 anos' )['idade'] );
		$this->assertSame( 10, dsi_filtros_extrair_do_texto( 'algo para crianças' )['idade'] );
	}

	public function test_idade_do_adulto_nao_vira_filtro(): void {
		$this->assertNull( dsi_filtros_extrair_do_texto( 'Tenho 30 anos e gosto de terror' )['idade'] );
		$this->assertNull( dsi_filtros_extrair_do_texto( 'Tenho 30 anos e meus filhos adoram terror' )['idade'] );
	}

	public function test_classificacao_maxima_por_idade(): void {
		$this->assertSame( 0, dsi_filtros_classificacao_maxima( 7 ) );
		$this->assertSame( 0, dsi_filtros_classificacao_maxima( 9 ) );
		$this->assertSame( 10, dsi_filtros_classificacao_maxima( 10 ) );
		$this->assertSame( 12, dsi_filtros_classificacao_maxima( 13 ) );
		$this->assertSame( 16, dsi_filtros_classificacao_maxima( 17 ) );
	}

	public function test_filtros_do_pedido_valida_valores(): void {
		$this->assertSame( [ 'protagonista' => 'feminina', 'idade' => 7 ], dsi_filtros_do_pedido( 'feminina', '7' ) );
		$this->assertSame( [], dsi_filtros_do_pedido( 'masculina', 0 ) );
		$this->assertSame( [], dsi_filtros_do_pedido( null, 30 ) );
		$this->assertSame( [ 'idade' => 12 ], dsi_filtros_do_pedido( null, 12 ) );
	}

	public function test_passa_exige_dado_confirmado(): void {
		$f = [ 'protagonista' => 'feminina' ];
		$this->assertTrue( dsi_filtros_passa( [ 'protagonista_g' => 1, 'mulheres_top3' => 1, 'cert_idade' => null ], $f ) );
		$this->assertTrue( dsi_filtros_passa( [ 'protagonista_g' => 2, 'mulheres_top3' => 3, 'cert_idade' => null ], $f ), 'os tres primeiros sao mulheres' );
		$this->assertFalse( dsi_filtros_passa( [ 'protagonista_g' => 2, 'mulheres_top3' => 2, 'cert_idade' => null ], $f ), 'protagonista homem com duas mulheres no elenco nao e protagonista mulher (Yesterday)' );
		$this->assertFalse( dsi_filtros_passa( [ 'protagonista_g' => 2, 'mulheres_top3' => 1, 'cert_idade' => null ], $f ) );
		$this->assertFalse( dsi_filtros_passa( [ 'protagonista_g' => 0, 'mulheres_top3' => 0, 'cert_idade' => null ], $f ), 'genero desconhecido nao passa' );
		$this->assertFalse( dsi_filtros_passa( null, $f ), 'sem dado nao passa' );
		$this->assertTrue( dsi_filtros_passa( null, [] ), 'sem filtro tudo passa' );
	}

	public function test_passa_faixa_etaria_nunca_aceita_classificacao_desconhecida(): void {
		$f = [ 'idade' => 7 ];
		$this->assertTrue( dsi_filtros_passa( [ 'cert_idade' => 0 ], $f ) );
		$this->assertFalse( dsi_filtros_passa( [ 'cert_idade' => 10 ], $f ) );
		$this->assertFalse( dsi_filtros_passa( [ 'cert_idade' => null ], $f ), 'classificacao desconhecida fica de fora' );
		$this->assertTrue( dsi_filtros_passa( [ 'cert_idade' => 12 ], [ 'idade' => 13 ] ) );
	}

	public function test_passa_com_os_dois_filtros_exige_os_dois(): void {
		$f = [ 'protagonista' => 'feminina', 'idade' => 10 ];
		$this->assertTrue( dsi_filtros_passa( [ 'protagonista_g' => 1, 'mulheres_top3' => 1, 'cert_idade' => 10 ], $f ) );
		$this->assertFalse( dsi_filtros_passa( [ 'protagonista_g' => 1, 'mulheres_top3' => 1, 'cert_idade' => 14 ], $f ) );
		$this->assertFalse( dsi_filtros_passa( [ 'protagonista_g' => 2, 'mulheres_top3' => 0, 'cert_idade' => 0 ], $f ) );
	}

	public function test_pede_refinar_so_para_pedido_de_protagonista_sem_pergunta(): void {
		$this->assertTrue( dsi_filtros_pede_refinar( 'Mas quero com protagonista mulher' ) );
		$this->assertFalse( dsi_filtros_pede_refinar( 'O protagonista mulher é bom?' ) );
		$this->assertFalse( dsi_filtros_pede_refinar( 'esse filme tem protagonista mulher?' ) );
		$this->assertFalse( dsi_filtros_pede_refinar( 'quem dirige?' ) );
	}

	public function test_aviso_descreve_o_filtro_aplicado(): void {
		$a = dsi_filtros_aviso( [ 'protagonista' => 'feminina', 'idade' => 7 ], 3 );
		$this->assertStringContainsString( 'protagonista mulher', $a );
		$this->assertStringContainsString( 'classificação livre', $a );
		$this->assertStringContainsString( 'pode ser menor', $a );
		$this->assertStringContainsString( 'Não achei', dsi_filtros_aviso( [ 'protagonista' => 'feminina' ], 0 ) );
		$this->assertSame( '', dsi_filtros_aviso( [], 5 ) );
	}
}
