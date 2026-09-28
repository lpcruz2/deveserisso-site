<?php
/**
 * Testes das regras puras do perfil de gosto (dsi-magazine/inc/perfil/regras.php).
 * Especificacao: projeto WebMCP-deveserisso, docs/prd-perfil-de-gosto.md.
 */

use PHPUnit\Framework\TestCase;

final class PerfilRegrasTest extends TestCase {

	// -- dsi_perfil_rede_social ---------------------------------------------

	public function test_instagram_vira_handle_e_url_limpa(): void {
		$this->assertSame(
			[ 'rede' => 'instagram', 'handle' => '@ana_cine', 'url' => 'https://instagram.com/ana_cine' ],
			dsi_perfil_rede_social( 'https://www.instagram.com/ana_cine/?igsh=abc' )
		);
	}

	public function test_sem_protocolo_e_aceito(): void {
		$this->assertSame( '@ana', dsi_perfil_rede_social( 'instagram.com/ana' )['handle'] );
	}

	public function test_twitter_vira_x(): void {
		$r = dsi_perfil_rede_social( 'https://twitter.com/ana' );
		$this->assertSame( 'x', $r['rede'] );
		$this->assertSame( 'https://x.com/ana', $r['url'] );
	}

	public function test_redes_com_arroba_no_caminho(): void {
		$this->assertSame( '@ana', dsi_perfil_rede_social( 'https://www.tiktok.com/@ana' )['handle'] );
		$this->assertSame( '@ana', dsi_perfil_rede_social( 'https://www.threads.net/@ana' )['handle'] );
		$this->assertSame( '@ana', dsi_perfil_rede_social( 'https://www.threads.com/@ana' )['handle'] );
		$this->assertSame( '@canal', dsi_perfil_rede_social( 'https://youtube.com/@canal' )['handle'] );
		$this->assertSame( '@canal', dsi_perfil_rede_social( 'https://youtube.com/c/canal' )['handle'] );
		$this->assertSame( 'https://youtube.com/c/canal', dsi_perfil_rede_social( 'https://youtube.com/c/canal' )['url'] );
	}

	public function test_bluesky_aceita_handle_com_ponto(): void {
		$r = dsi_perfil_rede_social( 'https://bsky.app/profile/ana.bsky.social' );
		$this->assertSame( '@ana.bsky.social', $r['handle'] );
		$this->assertSame( 'https://bsky.app/profile/ana.bsky.social', $r['url'] );
	}

	public function test_letterboxd_e_facebook(): void {
		$this->assertSame( 'letterboxd', dsi_perfil_rede_social( 'https://letterboxd.com/ana/' )['rede'] );
		$this->assertSame( 'facebook', dsi_perfil_rede_social( 'https://m.facebook.com/ana.silva' )['rede'] );
	}

	public function test_dominio_fora_da_lista_e_recusado(): void {
		$this->assertNull( dsi_perfil_rede_social( 'https://meusite.com/ana' ) );
		$this->assertNull( dsi_perfil_rede_social( 'https://instagram.com.golpe.ru/ana' ) );
		$this->assertNull( dsi_perfil_rede_social( 'https://golpe-instagram.com/ana' ) );
	}

	public function test_link_de_post_ou_sem_perfil_e_recusado(): void {
		$this->assertNull( dsi_perfil_rede_social( 'https://instagram.com/p/Cx123/' ) );
		$this->assertNull( dsi_perfil_rede_social( 'https://instagram.com/' ) );
		$this->assertNull( dsi_perfil_rede_social( 'https://youtube.com/watch?v=abc' ) );
		$this->assertNull( dsi_perfil_rede_social( 'https://tiktok.com/ana' ) );
		$this->assertNull( dsi_perfil_rede_social( 'https://bsky.app/ana' ) );
	}

	public function test_esquema_credencial_ou_porta_sao_recusados(): void {
		$this->assertNull( dsi_perfil_rede_social( 'javascript:alert(1)' ) );
		$this->assertNull( dsi_perfil_rede_social( 'https://user:senha@instagram.com/ana' ) );
		$this->assertNull( dsi_perfil_rede_social( 'https://instagram.com:8080/ana' ) );
		$this->assertNull( dsi_perfil_rede_social( 'https://instagram.com/<script>' ) );
		$this->assertNull( dsi_perfil_rede_social( '' ) );
	}

	// -- dsi_perfil_texto_resenha -------------------------------------------

	private function textoLongo( string $inicio = '' ): string {
		return $inicio . str_repeat( 'Gostei muito da direção e das atuações do elenco. ', 3 );
	}

	public function test_texto_valido_passa_sem_sinais(): void {
		$r = dsi_perfil_texto_resenha( $this->textoLongo() );
		$this->assertNull( $r['erro'] );
		$this->assertSame( [], $r['sinais'] );
	}

	public function test_texto_curto_e_longo(): void {
		$this->assertSame( 'curto', dsi_perfil_texto_resenha( 'Muito bom.' )['erro'] );
		$this->assertSame( 'longo', dsi_perfil_texto_resenha( str_repeat( 'a', 3001 ) )['erro'] );
	}

	public function test_links_saem_e_viram_sinal(): void {
		$r = dsi_perfil_texto_resenha( $this->textoLongo( 'Vejam https://golpe.com/x e www.spam.net agora. ' ) );
		$this->assertContains( 'link', $r['sinais'] );
		$this->assertStringNotContainsString( 'golpe', $r['texto'] );
		$this->assertStringNotContainsString( 'spam.net', $r['texto'] );
	}

	public function test_frase_colada_nao_e_confundida_com_link(): void {
		$r = dsi_perfil_texto_resenha( $this->textoLongo( 'Gostei muito.Como sempre, adorei.me emocionei. ' ) );
		$this->assertNotContains( 'link', $r['sinais'] );
		$this->assertStringContainsString( 'muito.Como', $r['texto'] );
	}

	public function test_tags_saem_e_espacos_sao_normalizados(): void {
		$r = dsi_perfil_texto_resenha( $this->textoLongo( "<b>Ótimo</b>   filme.\n\n\n\nMuito bom. " ) );
		$this->assertStringStartsWith( "Ótimo filme.\n\nMuito bom.", $r['texto'] );
	}

	public function test_palavrao_e_maiusculas_so_marcam(): void {
		$r = dsi_perfil_texto_resenha( $this->textoLongo( 'Que filme da porra, desgraçado de bom. ' ) );
		$this->assertNull( $r['erro'] );
		$this->assertContains( 'palavrao', $r['sinais'] );
		$this->assertContains( 'maiusculas', dsi_perfil_texto_resenha( strtoupper( $this->textoLongo() ) )['sinais'] );
	}

	public function test_palavra_que_contem_palavrao_nao_marca(): void {
		$r = dsi_perfil_texto_resenha( $this->textoLongo( 'A computação gráfica e a disputa final são ótimas. ' ) );
		$this->assertNotContains( 'palavrao', $r['sinais'] );
	}

	// -- marcacao, plataformas, canal ---------------------------------------

	public function test_marcacao_valida_e_invalida(): void {
		$this->assertSame(
			[ 'tmdb_id' => 603, 'tipo' => 'filme', 'visto' => 'ja_vi', 'avaliacao' => 'curti' ],
			dsi_perfil_marcacao_valida( '603', 'filme', 'ja_vi', 'curti' )
		);
		$this->assertSame( null, dsi_perfil_marcacao_valida( 603, 'filme', '', '' )['visto'] );
		$this->assertNull( dsi_perfil_marcacao_valida( 0, 'filme', null, null ) );
		$this->assertNull( dsi_perfil_marcacao_valida( 603, 'documentario', null, null ) );
		$this->assertNull( dsi_perfil_marcacao_valida( 603, 'filme', 'vi', null ) );
		$this->assertNull( dsi_perfil_marcacao_valida( 603, 'filme', null, 'amei' ) );
	}

	public function test_plataformas_filtra_e_tira_repetidas(): void {
		$this->assertSame( [ 'netflix', 'globoplay' ], dsi_perfil_plataformas_validas( [ 'netflix', 'hbo', 'globoplay', 'netflix', 5 ] ) );
	}

	public function test_canal_desconhecido_vira_site(): void {
		$this->assertSame( 'mcp_claude', dsi_perfil_canal( 'mcp_claude' ) );
		$this->assertSame( 'site', dsi_perfil_canal( 'outro' ) );
	}

	// -- resenha no ar e retencao -------------------------------------------

	public function test_resenha_no_ar_precisa_de_aprovada_e_critica(): void {
		$this->assertTrue( dsi_perfil_resenha_no_ar( 7, true ) );
		$this->assertFalse( dsi_perfil_resenha_no_ar( 7, false ) );
		$this->assertFalse( dsi_perfil_resenha_no_ar( null, true ) );
	}

	public function test_retencao_avisa_aos_23_meses_e_apaga_depois_do_aviso(): void {
		$acesso = '2026-01-01 00:00:00';
		$this->assertSame( 'nada', dsi_perfil_retencao_acao( $acesso, null, '2027-11-15 00:00:00' ) );
		$this->assertSame( 'avisar', dsi_perfil_retencao_acao( $acesso, null, '2027-12-05 00:00:00' ) );
		$this->assertSame( 'nada', dsi_perfil_retencao_acao( $acesso, '2027-12-05 00:00:00', '2028-01-02 00:00:00' ) );
		$this->assertSame( 'apagar', dsi_perfil_retencao_acao( $acesso, '2027-12-05 00:00:00', '2028-01-05 00:00:00' ) );
	}

	public function test_retencao_sem_aviso_nao_apaga_mesmo_passado_o_prazo(): void {
		$this->assertSame( 'avisar', dsi_perfil_retencao_acao( '2026-01-01 00:00:00', null, '2029-01-01 00:00:00' ) );
	}

	public function test_retencao_aviso_antigo_perde_valor_se_voltou_a_acessar(): void {
		$this->assertSame( 'nada', dsi_perfil_retencao_acao( '2028-01-01 00:00:00', '2027-12-05 00:00:00', '2028-02-01 00:00:00' ) );
	}

	// -- dsi_perfil_tmdb_candidatos -----------------------------------------

	public function test_tmdb_escolhe_por_nome_e_ano(): void {
		$res = [
			[ 'id' => 10674, 'title' => 'Mulan', 'original_title' => 'Mulan', 'release_date' => '1998-06-18' ],
			[ 'id' => 337401, 'title' => 'Mulan', 'original_title' => 'Mulan', 'release_date' => '2020-09-04' ],
		];
		$this->assertSame( [ 337401 ], dsi_perfil_tmdb_candidatos( $res, 'Mulan', '', 2020 ) );
	}

	public function test_tmdb_aceita_um_ano_de_diferenca_e_titulo_original(): void {
		$res = [ [ 'id' => 157847, 'title' => 'Joe', 'original_title' => 'Joe', 'release_date' => '2014-04-11' ] ];
		$this->assertSame( [ 157847 ], dsi_perfil_tmdb_candidatos( $res, 'Joe', '', 2013 ) );
		$res = [ [ 'id' => 1, 'name' => 'O Poço', 'original_name' => 'El Hoyo', 'first_air_date' => '2019-09-06' ] ];
		$this->assertSame( [ 1 ], dsi_perfil_tmdb_candidatos( $res, 'O Buraco', 'El hoyo', 2019 ) );
	}

	public function test_tmdb_ambiguo_devolve_todos(): void {
		$res = [
			[ 'id' => 377273, 'title' => 'Aquarius', 'release_date' => '2016-09-01' ],
			[ 'id' => 470876, 'title' => 'Aquarius', 'release_date' => '2016-11-27' ],
		];
		$this->assertCount( 2, dsi_perfil_tmdb_candidatos( $res, 'Aquarius', '', 2016 ) );
	}

	public function test_tmdb_nome_diferente_nao_entra(): void {
		$res = [ [ 'id' => 5, 'title' => 'Aquarius 2', 'release_date' => '2016-01-01' ] ];
		$this->assertSame( [], dsi_perfil_tmdb_candidatos( $res, 'Aquarius', '', 2016 ) );
	}

	// -- esquema -------------------------------------------------------------

	public function test_esquema_mysql_tem_indices_dentro_da_tabela(): void {
		$sql = implode( "\n", dsi_perfil_esquema_sql( 'perfil_', 'mysql' ) );
		$this->assertStringNotContainsString( 'CREATE INDEX', $sql );
		$this->assertStringContainsString( 'KEY fila (status, enviada_em)', $sql );
		$this->assertCount( 7, dsi_perfil_esquema_sql( 'perfil_', 'mysql' ) );
	}

	public function test_tabelas_com_prefixo(): void {
		$this->assertContains( 'x_resenha_versao', dsi_perfil_tabelas( 'x_' ) );
		$this->assertCount( 7, dsi_perfil_tabelas( 'x_' ) );
	}
}
