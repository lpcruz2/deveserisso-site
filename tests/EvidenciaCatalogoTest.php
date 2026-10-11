<?php
/**
 * Classificacao indicativa e protagonista nos itens e no contexto do Curador (2026-10-11).
 * Caso real do eval (K03): o sistema avisava "Filtrei por classificacao livre" e, na pergunta seguinte, o Curador
 * respondia "nao tenho a faixa etaria", porque o dado nao chegava a ele.
 */

use PHPUnit\Framework\TestCase;

final class EvidenciaCatalogoTest extends TestCase {

	public function test_evidencia_sem_dado_e_toda_nula(): void {
		$this->assertSame( [ 'classificacao_br' => null, 'protagonista_feminina' => null ], dsi_filtros_evidencia( null ) );
	}

	public function test_evidencia_traz_classificacao_e_protagonista(): void {
		$e = dsi_filtros_evidencia( [ 'protagonista_g' => 1, 'mulheres_top3' => 1, 'cert_idade' => 0, 'cert_br' => 'L' ] );
		$this->assertSame( 'L', $e['classificacao_br'] );
		$this->assertTrue( $e['protagonista_feminina'] );
		$e = dsi_filtros_evidencia( [ 'protagonista_g' => 2, 'mulheres_top3' => 2, 'cert_idade' => 12, 'cert_br' => '12' ] );
		$this->assertSame( '12', $e['classificacao_br'] );
		$this->assertFalse( $e['protagonista_feminina'] );
	}

	public function test_evidencia_ignora_classificacao_fora_da_lista(): void {
		$this->assertNull( dsi_filtros_evidencia( [ 'protagonista_g' => 1, 'cert_br' => 'R' ] )['classificacao_br'] );
		$this->assertNull( dsi_filtros_evidencia( [ 'protagonista_g' => 1, 'cert_br' => '' ] )['classificacao_br'] );
	}

	public function test_normalizador_aceita_so_valores_validos(): void {
		$item = dsi_bilheteiro_normalizar_item_pergunta( [ 'titulo' => 'X', 'classificacao' => '10', 'protagonista_feminina' => true ] );
		$this->assertSame( '10', $item['classificacao'] );
		$this->assertTrue( $item['protagonista_feminina'] );
		$item = dsi_bilheteiro_normalizar_item_pergunta( [ 'titulo' => 'X', 'classificacao' => '<script>', 'protagonista_feminina' => 'sim' ] );
		$this->assertNull( $item['classificacao'] );
		$this->assertNull( $item['protagonista_feminina'] );
		$item = dsi_bilheteiro_normalizar_item_pergunta( [ 'titulo' => 'X' ] );
		$this->assertNull( $item['classificacao'] );
		$this->assertNull( $item['protagonista_feminina'] );
	}

	public function test_contexto_do_curador_inclui_os_dados_quando_existem(): void {
		$c = dsi_bilheteiro_montar_contexto_filmes( [
			dsi_bilheteiro_normalizar_item_pergunta( [ 'titulo' => 'Totoro', 'classificacao' => 'L', 'protagonista_feminina' => true ] ),
			dsi_bilheteiro_normalizar_item_pergunta( [ 'titulo' => 'Matrix', 'classificacao' => '16', 'protagonista_feminina' => false ] ),
		] );
		$this->assertStringContainsString( 'Classificação indicativa (Brasil): livre para todos os públicos', $c );
		$this->assertStringContainsString( 'Classificação indicativa (Brasil): 16 anos', $c );
		$this->assertStringContainsString( 'Protagonista mulher (primeiro nome do elenco no TMDB): sim', $c );
		$this->assertStringContainsString( 'Protagonista mulher (primeiro nome do elenco no TMDB): não', $c );
	}

	public function test_contexto_sem_dado_nao_inventa_linhas(): void {
		$c = dsi_bilheteiro_montar_contexto_filmes( [ dsi_bilheteiro_normalizar_item_pergunta( [ 'titulo' => 'Sem dado' ] ) ] );
		$this->assertStringNotContainsString( 'Classificação indicativa', $c );
		$this->assertStringNotContainsString( 'Protagonista mulher', $c );
	}
}
