<?php
/**
 * Aviso de limitacao uma vez por conversa (2026-10-12).
 * Eval K10/K12: "Esse tipo de filtro (letra do titulo, duracao, ano) eu ainda nao faco" saiu quatro vezes seguidas.
 */

use PHPUnit\Framework\TestCase;

final class AvisoLimitacaoTest extends TestCase {

	public function test_primeira_vez_avisa_e_registra(): void {
		[ $aviso, $ja ] = dsi_curador_aviso_limitacao_uma_vez( 'outro', [] );
		$this->assertStringContainsString( 'ainda não faço', $aviso );
		$this->assertSame( [ 'outro' ], $ja );
	}

	public function test_segunda_vez_do_mesmo_tipo_fica_calado(): void {
		[ $aviso, $ja ] = dsi_curador_aviso_limitacao_uma_vez( 'outro', [ 'outro' ] );
		$this->assertSame( '', $aviso );
		$this->assertSame( [ 'outro' ], $ja );
	}

	public function test_tipo_diferente_ainda_avisa(): void {
		[ $aviso, $ja ] = dsi_curador_aviso_limitacao_uma_vez( 'diretor', [ 'outro' ] );
		$this->assertStringContainsString( 'diretor', $aviso );
		$this->assertSame( [ 'outro', 'diretor' ], $ja );
	}

	public function test_sem_limitacao_nao_muda_nada(): void {
		$this->assertSame( [ '', [ 'outro' ] ], dsi_curador_aviso_limitacao_uma_vez( null, [ 'outro' ] ) );
		$this->assertSame( [ '', [] ], dsi_curador_aviso_limitacao_uma_vez( 'inventado', [] ) );
	}
}
