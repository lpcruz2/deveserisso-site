<?php
/**
 * Testes do repositorio do perfil de gosto (dsi-magazine/inc/perfil/repositorio.php).
 *
 * Rodam em SQLite em memoria (CI). Se a extensao pdo_sqlite nao existir, os
 * testes sao pulados em vez de quebrar o deploy. O mesmo arquivo roda contra
 * o MySQL real quando $GLOBALS['dsi_perfil_teste_pdo'] traz [PDO, dialeto,
 * prefixo] (execucao manual no servidor, com prefixo de teste e tabelas
 * apagadas no fim de cada teste).
 */

use PHPUnit\Framework\TestCase;

final class PerfilRepositorioTest extends TestCase {

	private DSI_Perfil_Repositorio $repo;
	private PDO $pdo;
	private string $prefixo;
	private string $relogio = '2026-10-01 12:00:00';

	protected function setUp(): void {
		if ( ! empty( $GLOBALS['dsi_perfil_teste_pdo'] ) ) {
			[ $this->pdo, $dialeto, $this->prefixo ] = $GLOBALS['dsi_perfil_teste_pdo'];
		} else {
			if ( ! extension_loaded( 'pdo_sqlite' ) ) {
				$this->markTestSkipped( 'pdo_sqlite indisponivel' );
			}
			$this->pdo     = new PDO( 'sqlite::memory:' );
			$dialeto       = 'sqlite';
			$this->prefixo = 'perfil_';
		}
		$this->repo = new DSI_Perfil_Repositorio( $this->pdo, $this->prefixo, fn() => $this->relogio );
		$this->repo->criarEsquema( $dialeto );
		$this->repo->criarEsquema( $dialeto ); // idempotente
	}

	protected function tearDown(): void {
		if ( ! empty( $GLOBALS['dsi_perfil_teste_pdo'] ) ) {
			foreach ( dsi_perfil_tabelas( $this->prefixo ) as $t ) {
				$this->pdo->exec( "DROP TABLE IF EXISTS {$t}" );
			}
		}
	}

	private function novaConta( string $sub = 'google-1' ): int {
		return (int) $this->repo->criarConta( $sub, $sub . '@exemplo.com', 'Ana', 'site', '2026-10-01' )['id'];
	}

	private function texto( string $extra = '' ): string {
		return $extra . str_repeat( 'Gostei muito da direção e das atuações do elenco. ', 3 );
	}

	private function contaComConsentimento( string $sub = 'google-1' ): int {
		$id = $this->novaConta( $sub );
		$this->repo->aceitarConsentimento( $id, 'resenha_publica', '2026-10-01' );
		return $id;
	}

	// -- conta ---------------------------------------------------------------

	public function test_conta_nasce_com_termo_e_nao_duplica_pelo_sub(): void {
		$id = $this->novaConta();
		$this->assertTrue( $this->repo->temConsentimento( $id, 'termo_perfil' ) );
		$this->assertSame( $id, $this->novaConta() );
		$this->assertSame( 'google-1', $this->repo->contaPorSub( 'google-1' )['google_sub'] );
	}

	public function test_registrar_acesso_zera_aviso_de_retencao(): void {
		$id = $this->novaConta();
		$this->repo->marcarAvisoRetencao( $id );
		$this->relogio = '2026-10-02 08:00:00';
		$this->repo->registrarAcesso( $id, 'novo@exemplo.com', 'Ana Silva' );
		$c = $this->repo->conta( $id );
		$this->assertNull( $c['aviso_retencao_em'] );
		$this->assertSame( 'novo@exemplo.com', $c['email'] );
		$this->assertSame( '2026-10-02 08:00:00', $c['ultimo_acesso_em'] );
	}

	// -- marcacoes -----------------------------------------------------------

	public function test_marcar_atualizar_e_desmarcar(): void {
		$id = $this->novaConta();
		$this->repo->marcar( $id, 603, 'filme', 'ja_vi', null, 'mcp_claude' );
		$m = $this->repo->marcar( $id, 603, 'filme', 'ja_vi', 'curti', 'site' );
		$this->assertSame( 'curti', $m['avaliacao'] );
		$this->assertCount( 1, $this->repo->marcacoes( $id ) );
		$this->assertNull( $this->repo->marcar( $id, 603, 'filme', null, null ) );
		$this->assertSame( [], $this->repo->marcacoes( $id ) );
	}

	public function test_marcacao_invalida_lanca_erro(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->repo->marcar( $this->novaConta(), 603, 'filme', 'vi', null );
	}

	public function test_mesmo_numero_como_filme_e_serie_sao_titulos_diferentes(): void {
		$id = $this->novaConta();
		$this->repo->marcar( $id, 100, 'filme', 'ja_vi', null );
		$this->repo->marcar( $id, 100, 'serie', 'quero_ver', null );
		$this->assertCount( 2, $this->repo->marcacoes( $id ) );
	}

	public function test_plataformas(): void {
		$id = $this->novaConta();
		$this->assertSame( [], $this->repo->plataformas( $id ) );
		$this->repo->definirPlataformas( $id, [ 'netflix', 'hbo' ] );
		$this->assertSame( [ 'netflix' ], $this->repo->definirPlataformas( $id, [ 'netflix', 'hbo' ] ) );
		$this->assertSame( [ 'netflix' ], $this->repo->plataformas( $id ) );
	}

	// -- resenhas ------------------------------------------------------------

	public function test_resenha_exige_consentimento_link_e_texto_validos(): void {
		$id = $this->novaConta();
		$this->assertSame( 'sem_consentimento', $this->repo->enviarResenha( $id, 603, 'filme', $this->texto(), 'https://instagram.com/ana', false )['erro'] );
		$this->repo->aceitarConsentimento( $id, 'resenha_publica', '2026-10-01' );
		$this->assertSame( 'link_invalido', $this->repo->enviarResenha( $id, 603, 'filme', $this->texto(), 'https://meusite.com/ana', false )['erro'] );
		$this->assertSame( 'texto_curto', $this->repo->enviarResenha( $id, 603, 'filme', 'Bom.', 'https://instagram.com/ana', false )['erro'] );
		$ok = $this->repo->enviarResenha( $id, 603, 'filme', $this->texto(), 'https://instagram.com/ana', false );
		$this->assertTrue( $ok['ok'] );
		$this->assertSame( 'https://instagram.com/ana', $this->repo->conta( $id )['link_social'] );
	}

	public function test_fluxo_de_aprovacao_e_versao_no_ar_durante_edicao(): void {
		$id = $this->contaComConsentimento();
		$this->repo->vincularTituloPost( 70123, 603, 'filme', 'teste' );

		$v1 = $this->repo->enviarResenha( $id, 603, 'filme', $this->texto( 'Primeira. ' ), 'https://instagram.com/ana', false )['versao_id'];
		$this->assertSame( [], $this->repo->resenhasNoAr( 603, 'filme' ) );
		$this->assertTrue( $this->repo->aprovarVersao( $v1 ) );
		$this->assertStringStartsWith( 'Primeira.', $this->repo->resenhasNoAr( 603, 'filme' )[0]['texto'] );

		// Edicao: v1 continua no ar ate v2 ser aprovada.
		$v2 = $this->repo->enviarResenha( $id, 603, 'filme', $this->texto( 'Segunda. ' ), 'https://instagram.com/ana', true )['versao_id'];
		$this->assertStringStartsWith( 'Primeira.', $this->repo->resenhasNoAr( 603, 'filme' )[0]['texto'] );
		$fila = $this->repo->filaAprovacao();
		$this->assertCount( 1, $fila );
		$this->assertStringStartsWith( 'Primeira.', $fila[0]['texto_no_ar'] );

		// Recusar a edicao nao tira a v1 do ar.
		$this->assertTrue( $this->repo->recusarVersao( $v2, 'spoiler' ) );
		$this->assertStringStartsWith( 'Primeira.', $this->repo->resenhasNoAr( 603, 'filme' )[0]['texto'] );
		$minha = $this->repo->resenhasDaConta( $id )[0];
		$this->assertSame( 'recusada', $minha['status'] );
		$this->assertSame( 'spoiler', $minha['motivo_recusa'] );
		$this->assertTrue( $minha['no_ar'] );

		// Nova edicao aprovada substitui a v1.
		$v3 = $this->repo->enviarResenha( $id, 603, 'filme', $this->texto( 'Terceira. ' ), 'https://instagram.com/ana', false )['versao_id'];
		$this->assertTrue( $this->repo->aprovarVersao( $v3 ) );
		$no_ar = $this->repo->resenhasNoAr( 603, 'filme' );
		$this->assertCount( 1, $no_ar );
		$this->assertStringStartsWith( 'Terceira.', $no_ar[0]['texto'] );
		$this->assertSame( '@ana', $no_ar[0]['handle'] );
		$this->assertFalse( $this->repo->aprovarVersao( $v3 ), 'aprovar de novo nao faz nada' );
	}

	public function test_nova_pendente_substitui_a_pendente_anterior(): void {
		$id = $this->contaComConsentimento();
		$this->repo->enviarResenha( $id, 603, 'filme', $this->texto( 'A. ' ), 'https://instagram.com/ana', false );
		$this->repo->enviarResenha( $id, 603, 'filme', $this->texto( 'B. ' ), 'https://instagram.com/ana', false );
		$fila = $this->repo->filaAprovacao();
		$this->assertCount( 1, $fila );
		$this->assertStringStartsWith( 'B.', $fila[0]['texto'] );
	}

	public function test_resenha_sem_critica_espera_e_aparece_quando_critica_sai(): void {
		$id = $this->contaComConsentimento();
		$v  = $this->repo->enviarResenha( $id, 999, 'filme', $this->texto(), 'https://instagram.com/ana', false )['versao_id'];
		$this->assertSame( [ [ 'tmdb_id' => 999, 'tipo' => 'filme', 'resenhas' => 1 ] ], $this->repo->filaPauta() );
		$this->repo->aprovarVersao( $v );
		$this->assertSame( [], $this->repo->resenhasNoAr( 999, 'filme' ) );
		$this->assertTrue( $this->repo->resenhasDaConta( $id )[0]['esperando_critica'] );

		$this->repo->vincularTituloPost( 80000, 999, 'filme', 'teste' );
		$this->assertCount( 1, $this->repo->resenhasNoAr( 999, 'filme' ) );
		$this->assertSame( [], $this->repo->filaPauta() );
	}

	public function test_texto_repetido_de_outra_conta_vira_sinal(): void {
		$a = $this->contaComConsentimento( 'google-a' );
		$b = $this->contaComConsentimento( 'google-b' );
		$this->repo->enviarResenha( $a, 603, 'filme', $this->texto(), 'https://instagram.com/a', false );
		$r = $this->repo->enviarResenha( $b, 604, 'filme', $this->texto(), 'https://instagram.com/b', false );
		$this->assertContains( 'repetido', $r['sinais'] );
	}

	public function test_apagar_resenha_tira_do_ar(): void {
		$id = $this->contaComConsentimento();
		$this->repo->vincularTituloPost( 70123, 603, 'filme', 'teste' );
		$v = $this->repo->enviarResenha( $id, 603, 'filme', $this->texto(), 'https://instagram.com/ana', false )['versao_id'];
		$this->repo->aprovarVersao( $v );
		$this->assertTrue( $this->repo->apagarResenha( $id, 603, 'filme' ) );
		$this->assertSame( [], $this->repo->resenhasNoAr( 603, 'filme' ) );
		$this->assertFalse( $this->repo->apagarResenha( $id, 603, 'filme' ) );
	}

	public function test_motivo_de_recusa_invalido(): void {
		$this->expectException( InvalidArgumentException::class );
		$this->repo->recusarVersao( 1, 'nao_gostei' );
	}

	// -- critica <-> titulo --------------------------------------------------

	public function test_vinculo_critica_titulo(): void {
		$this->repo->vincularTituloPost( 1, 603, 'filme', 'cache_conferido' );
		$this->repo->vincularTituloPost( 2, 603, 'filme', 'busca' );
		$this->repo->vincularTituloPost( 1, 604, 'filme', 'manual' ); // relink
		$this->assertSame( [ 2 ], $this->repo->postsDoTitulo( 603, 'filme' ) );
		$this->assertSame( 604, $this->repo->tituloDoPost( 1 )['tmdb_id'] );
		$this->repo->desvincularPost( 2 );
		$this->assertFalse( $this->repo->criticaExiste( 603, 'filme' ) );
	}

	// -- LGPD ----------------------------------------------------------------

	public function test_exportar_e_apagar_conta_em_cascata(): void {
		$id    = $this->contaComConsentimento();
		$outra = $this->contaComConsentimento( 'google-2' );
		$this->repo->marcar( $id, 603, 'filme', 'ja_vi', 'curti' );
		$this->repo->marcar( $outra, 603, 'filme', 'ja_vi', null );
		$this->repo->definirPlataformas( $id, [ 'netflix' ] );
		$this->repo->enviarResenha( $id, 603, 'filme', $this->texto(), 'https://instagram.com/ana', false );

		$e = $this->repo->exportar( $id );
		$this->assertSame( 'google-1@exemplo.com', $e['conta']['email'] );
		$this->assertCount( 2, $e['consentimentos'] );
		$this->assertSame( [ 'netflix' ], $e['plataformas'] );
		$this->assertCount( 1, $e['marcacoes'] );
		$this->assertCount( 1, $e['resenhas'] );

		$this->repo->apagarConta( $id );
		$this->assertNull( $this->repo->conta( $id ) );
		$this->assertNull( $this->repo->exportar( $id ) );
		$this->assertSame( [], $this->repo->filaAprovacao() );
		foreach ( [ 'marcacao', 'preferencias', 'consentimento', 'resenha' ] as $t ) {
			$n = (int) $this->pdo->query( "SELECT COUNT(*) FROM {$this->prefixo}{$t} WHERE conta_id = {$id}" )->fetchColumn();
			$this->assertSame( 0, $n, $t );
		}
		$this->assertCount( 1, $this->repo->marcacoes( $outra ), 'outra conta intacta' );
	}

	public function test_retencao_lista_contas_para_avisar(): void {
		$id            = $this->novaConta();
		$this->relogio = '2028-09-10 00:00:00';
		$this->assertSame( [ [ 'id' => $id, 'email' => 'google-1@exemplo.com', 'acao' => 'avisar' ] ], $this->repo->contasParaRetencao() );
		$this->repo->marcarAvisoRetencao( $id );
		$this->assertSame( [], $this->repo->contasParaRetencao() );
		$this->relogio = '2028-10-11 00:00:00';
		$this->assertSame( 'apagar', $this->repo->contasParaRetencao()[0]['acao'] );
	}
}
