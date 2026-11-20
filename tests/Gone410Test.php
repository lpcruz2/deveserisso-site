<?php
/**
 * 410 para URLs antigas de posts removidos (2026-11-20). Lógica pura do mu-plugin dsi-gone-410.php.
 */

use PHPUnit\Framework\TestCase;

final class Gone410Test extends TestCase {

	public function test_slug_antigo_com_sufixo_e_bom_vale_a_pena_e_410(): void {
		$this->assertTrue( dsi_gone410_corresponde( '/shadowhunters-e-bom-e-vale-a-pena-assistir-confira-trailer-sinopse-e-mais/' ) );
	}

	public function test_sufixo_trailer_legendado_sem_barra_final_e_410(): void {
		$this->assertTrue( dsi_gone410_corresponde( '/bruno-trailer-legendado-elenco-sinopse-e-mais' ) );
	}

	public function test_outros_sufixos_legados(): void {
		$this->assertTrue( dsi_gone410_corresponde( '/superbad-e-hoje-vale-a-pena-assistir-trailer-sinopse-e-elenco/' ) );
		$this->assertTrue( dsi_gone410_corresponde( '/x-e-bom-onde-assistir-trailer-sinopse-e-muito-mais/' ) );
	}

	public function test_feed_do_slug_antigo_tambem_e_410(): void {
		$this->assertTrue( dsi_gone410_corresponde( '/atlantis-trailer-legendado-elenco-sinopse-e-mais/feed/' ) );
	}

	public function test_caminho_exato_do_feed_antigo(): void {
		$this->assertTrue( dsi_gone410_corresponde( '/filmes-e-seriados/feed/' ) );
	}

	public function test_so_o_sufixo_sem_titulo_antes_nao_conta(): void {
		$this->assertFalse( dsi_gone410_corresponde( '/-e-bom-e-vale-a-pena-assistir-confira-trailer-sinopse-e-mais/' ) );
	}

	public function test_slug_normal_nao_conta(): void {
		$this->assertFalse( dsi_gone410_corresponde( '/a-origem/' ) );
		$this->assertFalse( dsi_gone410_corresponde( '/filmes-com-a-letra-z/' ) );
		$this->assertFalse( dsi_gone410_corresponde( '/' ) );
		$this->assertFalse( dsi_gone410_corresponde( '' ) );
	}

	public function test_subpasta_nao_conta(): void {
		$this->assertFalse( dsi_gone410_corresponde( '/blog/shadowhunters-e-bom-e-vale-a-pena-assistir-confira-trailer-sinopse-e-mais/' ) );
		$this->assertFalse( dsi_gone410_corresponde( '/filmes/x-trailer-legendado-elenco-sinopse-e-mais/' ) );
	}

	public function test_maiusculas_e_url_codificada(): void {
		$this->assertTrue( dsi_gone410_corresponde( '/Bruno-Trailer-Legendado-Elenco-Sinopse-E-Mais/' ) );
		$this->assertTrue( dsi_gone410_corresponde( '/bruno%2Dtrailer-legendado-elenco-sinopse-e-mais/' ) );
	}
}
