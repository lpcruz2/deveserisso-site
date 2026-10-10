<?php
/**
 * Foco da conversa e pedido "sem assinar" no "onde assistir" (dsi-magazine/inc/onde-assistir.php), 2026-10-10.
 * Conversa real que motivou: depois de "onde assistir O Iluminado?", a pessoa perguntou "e dá pra alugar ou
 * comprar?" e o Curador respondeu que precisava saber o título.
 */

use PHPUnit\Framework\TestCase;

final class OndeAssistirFocoTest extends TestCase {

	public function test_pergunta_pede_reconhece_onde_ver_alugar_e_comprar(): void {
		foreach ( [ 'Onde dá pra assistir?', 'Tem onde assistir hoje à noite?', 'E dá pra alugar ou comprar?', 'Qual streaming tem?', 'Quero ver sem assinar nada, de graça', 'Quero ver sem assinar nada. Tem?', 'em que plataforma está' ] as $q ) {
			$this->assertTrue( dsi_onde_assistir_pergunta_pede( $q ), $q );
		}
		foreach ( [ 'Quem dirige Us?', 'Qual o melhor?', 'Tem outros?', 'É bom?' ] as $q ) {
			$this->assertFalse( dsi_onde_assistir_pergunta_pede( $q ), $q );
		}
	}

	public function test_sem_assinatura_so_para_alugar_comprar_ou_gratis(): void {
		$this->assertTrue( dsi_onde_assistir_sem_assinatura( 'E dá pra alugar ou comprar?' ) );
		$this->assertTrue( dsi_onde_assistir_sem_assinatura( 'quero ver sem assinar nada' ) );
		$this->assertFalse( dsi_onde_assistir_sem_assinatura( 'Onde dá pra assistir?' ) );
	}

	public function test_achar_item_prefere_titulo_citado_e_cai_no_foco(): void {
		$itens = [ [ 'titulo' => 'Us' ], [ 'titulo' => 'Alien: O Oitavo Passageiro' ], [ 'titulo' => 'O Iluminado' ] ];
		$this->assertSame( 3, dsi_onde_assistir_achar_item( $itens, 'Onde assistir O Iluminado?', 'Us' ) );
		$this->assertSame( 2, dsi_onde_assistir_achar_item( $itens, 'E dá pra alugar?', 'Alien: O Oitavo Passageiro' ) );
		$this->assertNull( dsi_onde_assistir_achar_item( $itens, 'E dá pra alugar?', '' ) );
		// "us" dentro de outra palavra nao conta como o titulo "Us"
		$this->assertNull( dsi_onde_assistir_achar_item( $itens, 'Tem em algum lugar para ver, tipo Jesus?', '' ) );
	}

	public function test_texto_sem_assinatura_mostra_so_aluguel_e_compra(): void {
		$br = [ 'flatrate' => [ [ 'provider_name' => 'Max' ] ], 'rent' => [ [ 'provider_name' => 'Apple TV' ] ] ];
		$t  = dsi_onde_assistir_texto( 'O Iluminado', $br, true );
		$this->assertStringContainsString( 'sem assinar', $t );
		$this->assertStringContainsString( 'Aluguel: Apple TV.', $t );
		$this->assertStringNotContainsString( 'Assinatura', $t );
	}

	public function test_texto_sem_assinatura_quando_so_ha_assinatura_diz_isso(): void {
		$t = dsi_onde_assistir_texto( 'Filme X', [ 'flatrate' => [ [ 'provider_name' => 'Max' ] ] ], true );
		$this->assertStringContainsString( 'só em assinatura: Max.', $t );
	}

	public function test_aplicar_devolve_o_foco_e_nao_mexe_quando_nao_ha_titulo(): void {
		$itens = [ [ 'titulo' => 'Us' ], [ 'titulo' => 'O Iluminado' ] ];
		$r     = dsi_onde_assistir_aplicar( [ 'resposta' => 'x', 'intencao' => 'avaliar_titulo', 'titulo' => 2, 'frase' => '' ], $itens, 'O Iluminado é bom?', '' );
		$this->assertSame( 'O Iluminado', $r['titulo_foco'] );
		$this->assertSame( 'x', $r['resposta'] );
		$r = dsi_onde_assistir_aplicar( [ 'resposta' => 'y', 'intencao' => 'outra', 'titulo' => null, 'frase' => '' ], $itens, 'Quem dirige?', 'Us' );
		$this->assertSame( 'y', $r['resposta'] );
		$this->assertArrayNotHasKey( 'titulo_foco', $r );
	}
}
