<?php
/**
 * Intencao "nova_recomendacao" na rota de perguntas sobre a lista (2026-10-11).
 * Caso real do eval: depois da lista, "manda outros de terror tipo Invocacao do Mal" era recusado tres vezes com
 * "aqui so falo dos titulos ja indicados". Agora o modelo sinaliza o pedido e o sistema reabre a busca.
 */

use PHPUnit\Framework\TestCase;

final class NovaRecomendacaoTest extends TestCase {

	public function test_nova_recomendacao_e_reconhecida_mesmo_sem_texto_de_resposta(): void {
		$r = dsi_a2ui_interpretar_resposta( '{"resposta":"","intencao":"nova_recomendacao","titulo":null}', 5 );
		$this->assertSame( 'nova_recomendacao', $r['intencao'] );
		$this->assertNull( $r['titulo'] );
		$this->assertSame( '', $r['resposta'] );
	}

	public function test_nova_recomendacao_ignora_titulo_enviado_por_engano(): void {
		$r = dsi_a2ui_interpretar_resposta( '{"resposta":"Vou buscar.","intencao":"nova_recomendacao","titulo":3}', 5 );
		$this->assertSame( 'nova_recomendacao', $r['intencao'] );
		$this->assertNull( $r['titulo'] );
	}

	public function test_as_outras_intencoes_continuam_iguais(): void {
		$r = dsi_a2ui_interpretar_resposta( '{"resposta":"Tem crítica no site.","intencao":"avaliar_titulo","titulo":2}', 5 );
		$this->assertSame( 'avaliar_titulo', $r['intencao'] );
		$this->assertSame( 2, $r['titulo'] );
		$r = dsi_a2ui_interpretar_resposta( '{"resposta":"Dirigido por Jordan Peele.","intencao":"outra","titulo":null}', 5 );
		$this->assertSame( 'outra', $r['intencao'] );
		$this->assertSame( 'Dirigido por Jordan Peele.', $r['resposta'] );
	}

	public function test_texto_puro_sem_json_continua_sendo_resposta_comum(): void {
		$r = dsi_a2ui_interpretar_resposta( 'Isso eu não sei.', 5 );
		$this->assertSame( 'outra', $r['intencao'] );
	}
}
