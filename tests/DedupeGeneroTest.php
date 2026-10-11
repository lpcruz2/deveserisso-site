<?php
/**
 * Genero multiplo e titulos repetidos nas listas do Curador (2026-10-11).
 * Casos do eval: "comedia romantica" trazia comedias sem romance; "Enola Holmes" e "A Familia Addams 2" apareciam duas vezes;
 * titulos de rodadas anteriores voltavam com outro id do catalogo.
 */

use PHPUnit\Framework\TestCase;

final class DedupeGeneroTest extends TestCase {

	public function test_grupos_de_genero_um_por_genero_pedido(): void {
		$this->assertCount( 2, dsi_filtros_grupos_genero( 'comedia, romance' ) );
		$this->assertCount( 1, dsi_filtros_grupos_genero( 'terror' ) );
		$this->assertCount( 0, dsi_filtros_grupos_genero( '' ) );
	}

	public function test_quem_acerta_os_dois_generos_pontua_mais(): void {
		$g = dsi_filtros_grupos_genero( 'comedia, romance' );
		$this->assertSame( 2, dsi_filtros_acertos_genero( [ 'comedia', 'romance' ], $g ) );
		$this->assertSame( 1, dsi_filtros_acertos_genero( [ 'comedia', 'animacao' ], $g ) );
		$this->assertSame( 1, dsi_filtros_acertos_genero( [ 'romance', 'drama' ], $g ) );
		$this->assertSame( 0, dsi_filtros_acertos_genero( [ 'acao', 'terror' ], $g ) );
	}

	public function test_um_genero_pedido_nunca_passa_de_um_acerto(): void {
		$g = dsi_filtros_grupos_genero( 'comedia' );
		$this->assertSame( 1, dsi_filtros_acertos_genero( [ 'comedia', 'comedia dramatica' ], $g ) );
	}

	public function test_titulo_repetido_com_anos_proximos(): void {
		$vistos = [];
		$this->assertFalse( dsi_filtros_repetido( $vistos, 'A Família Addams 2', 2001 ) );
		$this->assertTrue( dsi_filtros_repetido( $vistos, 'A familia Addams 2', 1993 ), 'mesma obra com ano divergente' );
		$this->assertFalse( dsi_filtros_repetido( $vistos, 'Mulan', 1998 ) );
		$this->assertFalse( dsi_filtros_repetido( $vistos, 'Mulan', 2020 ), 'remake e outro filme' );
		$this->assertTrue( dsi_filtros_repetido( $vistos, 'Mulan', null ), 'sem ano conta como repetido' );
	}

	public function test_titulo_vazio_nunca_e_repetido(): void {
		$vistos = [];
		$this->assertFalse( dsi_filtros_repetido( $vistos, '', 2000 ) );
		$this->assertFalse( dsi_filtros_repetido( $vistos, '', 2000 ) );
	}

	public function test_titulos_excluidos_valem_para_qualquer_ano(): void {
		$vistos = dsi_filtros_titulos_excluidos( [ [ 'id' => 1, 'fonte' => 'catalogo', 'titulo' => 'Enola Holmes' ], [ 'id' => 2, 'fonte' => 'externo' ] ] );
		$this->assertTrue( dsi_filtros_repetido( $vistos, 'Enola Holmes', 2020 ) );
		$this->assertFalse( dsi_filtros_repetido( $vistos, 'Outro Filme', 2020 ) );
	}

	public function test_aviso_nao_chama_o_dado_de_confirmado_e_explica_o_criterio(): void {
		$a = dsi_filtros_aviso( [ 'protagonista' => 'feminina' ], 4 );
		$this->assertStringContainsString( 'primeiro nome do elenco no TMDB', $a );
		$this->assertStringNotContainsString( 'confirmado', $a );
	}
}
