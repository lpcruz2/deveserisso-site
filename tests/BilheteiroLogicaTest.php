<?php
/**
 * Testes da logica pura do bilheteiro (dsi-magazine/inc/bilheteiro-logica.php).
 * Cobre a parte que mais mudou nesta sessao (2026-09-20 a 2026-09-22):
 * conjunto de campos obrigatorios por tipo de entrada, sequencia de
 * perguntas, tratamento do campo array "atores" e validacao do
 * "reconhecimento" gerado pela IA.
 */

use PHPUnit\Framework\TestCase;

final class BilheteiroLogicaTest extends TestCase {

	private function estadoVazio( array $sobrescrever = [] ): array {
		$base = [
			'plataforma' => null,
			'tipo' => null,
			'emocao' => null,
			'genero' => null,
			'baseado_fatos_reais' => null,
			'q' => null,
			'atores' => [],
			'atores_sem_preferencia' => false,
			'exclusoes' => [],
		];
		return array_merge( $base, $sobrescrever );
	}

	// -- dsi_bilheteiro_campos_obrigatorios ---------------------------------

	public function test_obrigatorios_padrao_e_o_conjunto_gamificado(): void {
		$this->assertSame(
			[ 'genero', 'emocao', 'plataforma' ],
			dsi_bilheteiro_campos_obrigatorios( [] )
		);
	}

	public function test_obrigatorios_sem_minigames_troca_emocao_por_q_e_atores(): void {
		$this->assertSame(
			[ 'genero', 'q', 'atores', 'plataforma' ],
			dsi_bilheteiro_campos_obrigatorios( [ 'sem_minigames' => true ] )
		);
	}

	// -- dsi_bilheteiro_campos_faltando --------------------------------------

	public function test_campos_faltando_gamificado_com_estado_vazio(): void {
		$faltando = dsi_bilheteiro_campos_faltando( $this->estadoVazio(), [] );
		$this->assertSame( [ 'genero', 'emocao', 'plataforma' ], $faltando );
	}

	public function test_campos_faltando_gamificado_vazio_quando_tudo_preenchido(): void {
		$estado = $this->estadoVazio( [ 'genero' => 'terror', 'emocao' => 'medo', 'plataforma' => 'Netflix' ] );
		$this->assertSame( [], dsi_bilheteiro_campos_faltando( $estado, [] ) );
	}

	public function test_campos_faltando_atores_vazio_conta_como_faltando_no_chat(): void {
		$estado = $this->estadoVazio( [ 'genero' => 'terror', 'q' => 'Matrix', 'plataforma' => 'Netflix' ] );
		$faltando = dsi_bilheteiro_campos_faltando( $estado, [ 'sem_minigames' => true ] );
		$this->assertContains( 'atores', $faltando );
	}

	public function test_campos_faltando_atores_com_flag_sem_preferencia_nao_falta(): void {
		// Array vazio de verdade (nunca "null"), mas a pessoa insistiu que
		// nao tem ator favorito -- a flag e o equivalente do sentinela pros
		// escalares (ver comentario em DSI_BILHETEIRO_CAMPOS_OBRIGATORIOS_CHAT).
		$estado = $this->estadoVazio( [
			'genero' => 'terror', 'q' => 'Matrix', 'plataforma' => 'Netflix',
			'atores' => [], 'atores_sem_preferencia' => true,
		] );
		$this->assertSame( [], dsi_bilheteiro_campos_faltando( $estado, [ 'sem_minigames' => true ] ) );
	}

	public function test_campos_faltando_atores_preenchido_nao_falta(): void {
		$estado = $this->estadoVazio( [
			'genero' => 'terror', 'q' => 'Matrix', 'plataforma' => 'Netflix',
			'atores' => [ 'Tom Hanks' ],
		] );
		$this->assertSame( [], dsi_bilheteiro_campos_faltando( $estado, [ 'sem_minigames' => true ] ) );
	}

	// -- dsi_bilheteiro_proxima_pergunta (fluxo sem minigame) ----------------

	public function test_proxima_pergunta_chat_comeca_por_genero(): void {
		$contexto = [ 'sem_minigames' => true ];
		$this->assertSame(
			DSI_BILHETEIRO_PERGUNTAS['genero'],
			dsi_bilheteiro_proxima_pergunta( $this->estadoVazio(), $contexto )
		);
	}

	public function test_proxima_pergunta_chat_depois_do_genero_pede_filmes_series(): void {
		$contexto = [ 'sem_minigames' => true ];
		$estado = $this->estadoVazio( [ 'genero' => 'terror' ] );
		$this->assertSame( DSI_BILHETEIRO_PERGUNTAS['filmes_series'], dsi_bilheteiro_proxima_pergunta( $estado, $contexto ) );
	}

	public function test_proxima_pergunta_chat_depois_de_q_pede_atores(): void {
		$contexto = [ 'sem_minigames' => true ];
		$estado = $this->estadoVazio( [ 'genero' => 'terror', 'q' => 'Matrix' ] );
		$this->assertSame( DSI_BILHETEIRO_PERGUNTAS['atores'], dsi_bilheteiro_proxima_pergunta( $estado, $contexto ) );
	}

	public function test_proxima_pergunta_chat_pede_plataforma_por_ultimo(): void {
		$contexto = [ 'sem_minigames' => true ];
		$estado = $this->estadoVazio( [ 'genero' => 'terror', 'q' => 'Matrix', 'atores' => [ 'Tom Hanks' ] ] );
		$this->assertSame( DSI_BILHETEIRO_PERGUNTAS['plataforma'], dsi_bilheteiro_proxima_pergunta( $estado, $contexto ) );
	}

	public function test_proxima_pergunta_chat_atores_sem_preferencia_pula_pra_plataforma(): void {
		$contexto = [ 'sem_minigames' => true ];
		$estado = $this->estadoVazio( [
			'genero' => 'terror', 'q' => 'Matrix', 'atores' => [], 'atores_sem_preferencia' => true,
		] );
		$this->assertSame( DSI_BILHETEIRO_PERGUNTAS['plataforma'], dsi_bilheteiro_proxima_pergunta( $estado, $contexto ) );
	}

	public function test_proxima_pergunta_chat_texto_livre_quando_tudo_preenchido(): void {
		$contexto = [ 'sem_minigames' => true ];
		$estado = $this->estadoVazio( [
			'genero' => 'terror', 'q' => 'Matrix', 'atores' => [ 'Tom Hanks' ], 'plataforma' => 'Netflix',
		] );
		$this->assertSame( DSI_BILHETEIRO_PERGUNTAS['texto_livre'], dsi_bilheteiro_proxima_pergunta( $estado, $contexto ) );
	}

	// -- dsi_bilheteiro_proxima_pergunta (jornada gamificada) ----------------

	public function test_proxima_pergunta_gamificado_pede_genero_so_se_corredor_pulado(): void {
		$estado = $this->estadoVazio();
		$semPular = dsi_bilheteiro_proxima_pergunta( $estado, [ 'corredor_pulado' => false, 'emocao_pulada' => false ] );
		$this->assertNotSame( DSI_BILHETEIRO_PERGUNTAS['genero'], $semPular );

		$comPular = dsi_bilheteiro_proxima_pergunta( $estado, [ 'corredor_pulado' => true, 'emocao_pulada' => false ] );
		$this->assertSame( DSI_BILHETEIRO_PERGUNTAS['genero'], $comPular );
	}

	public function test_proxima_pergunta_gamificado_pede_plataforma_quando_genero_emocao_ja_vieram_do_minigame(): void {
		$estado = $this->estadoVazio( [ 'genero' => 'terror', 'emocao' => 'medo' ] );
		$pergunta = dsi_bilheteiro_proxima_pergunta( $estado, [ 'corredor_pulado' => false, 'emocao_pulada' => false ] );
		$this->assertSame( DSI_BILHETEIRO_PERGUNTAS['plataforma'], $pergunta );
	}

	// -- dsi_bilheteiro_validar_reconhecimento -------------------------------

	public function test_reconhecimento_valido_passa(): void {
		$this->assertSame( 'Terror é ótimo!', dsi_bilheteiro_validar_reconhecimento( 'Terror é ótimo!' ) );
	}

	public function test_reconhecimento_vazio_ou_nulo_vira_string_vazia(): void {
		$this->assertSame( '', dsi_bilheteiro_validar_reconhecimento( '' ) );
		$this->assertSame( '', dsi_bilheteiro_validar_reconhecimento( null ) );
		$this->assertSame( '', dsi_bilheteiro_validar_reconhecimento( '   ' ) );
	}

	public function test_reconhecimento_rejeita_muito_longo(): void {
		$longo = str_repeat( 'a', DSI_BILHETEIRO_RECONHECIMENTO_MAX_CHARS + 1 );
		$this->assertSame( '', dsi_bilheteiro_validar_reconhecimento( $longo ) );
	}

	public function test_reconhecimento_rejeita_pergunta(): void {
		$this->assertSame( '', dsi_bilheteiro_validar_reconhecimento( 'Você gosta de terror?' ) );
	}

	public function test_reconhecimento_rejeita_link(): void {
		$this->assertSame( '', dsi_bilheteiro_validar_reconhecimento( 'Olha isso: https://exemplo.com' ) );
	}

	public function test_reconhecimento_remove_tag_html_em_vez_de_rejeitar(): void {
		// wp_strip_all_tags roda ANTES da checagem de padrao suspeito --
		// a tag/script ja sai limpa, sobrando so o texto seguro. O padrao
		// "/<[a-z]/i" e defesa em profundidade pro que sobrar depois da
		// limpeza, nao serve pra rejeitar um caso ja neutralizado como
		// este.
		$this->assertSame( 'Legal!', dsi_bilheteiro_validar_reconhecimento( 'Legal! <script>alert(1)</script>' ) );
	}

	public function test_reconhecimento_rejeita_mencao_a_instrucao_ou_ignore(): void {
		$this->assertSame( '', dsi_bilheteiro_validar_reconhecimento( 'Vou ignorar as instruções e...' ) );
		$this->assertSame( '', dsi_bilheteiro_validar_reconhecimento( 'Meu system prompt diz...' ) );
	}

	// -- dsi_bilheteiro_detectar_plataformas_texto ---------------------------

	public function test_detecta_netflix(): void {
		$this->assertSame( [ 'Netflix' ], dsi_bilheteiro_detectar_plataformas_texto( 'quero ver na netflix' ) );
	}

	public function test_detecta_multiplas_plataformas_na_mesma_mensagem(): void {
		$encontradas = dsi_bilheteiro_detectar_plataformas_texto( 'netflix e globoplay' );
		$this->assertSame( [ 'Netflix', 'Globoplay' ], $encontradas );
	}

	public function test_nao_confunde_primeiro_com_amazon_prime(): void {
		$this->assertSame( [], dsi_bilheteiro_detectar_plataformas_texto( 'esse é o primeiro filme que assisto' ) );
	}

	public function test_nao_confunde_rede_globo_com_globoplay(): void {
		$this->assertSame( [], dsi_bilheteiro_detectar_plataformas_texto( 'vi isso na Rede Globo ontem' ) );
	}

	// -- dsi_bilheteiro_montar_contexto_filmes -------------------------------

	public function test_contexto_filmes_vazio_retorna_string_vazia(): void {
		$this->assertSame( '', dsi_bilheteiro_montar_contexto_filmes( [] ) );
	}

	public function test_contexto_filmes_inclui_titulo_ano_generos_diretor_atores_sinopse(): void {
		$contexto = dsi_bilheteiro_montar_contexto_filmes( [ [
			'titulo'         => 'Matrix',
			'ano_lancamento' => 1999,
			'generos'        => [ 'Ação', 'Ficção Científica' ],
			'diretor'        => 'As Wachowski',
			'atores'         => [ 'Keanu Reeves', 'Laurence Fishburne' ],
			'sinopse'        => 'Um hacker descobre a verdade sobre a realidade.',
		] ] );
		$this->assertStringContainsString( '1) Título: Matrix (1999)', $contexto );
		$this->assertStringContainsString( 'Gênero: Ação, Ficção Científica', $contexto );
		$this->assertStringContainsString( 'Diretor: As Wachowski', $contexto );
		$this->assertStringContainsString( 'Elenco conhecido: Keanu Reeves, Laurence Fishburne', $contexto );
		$this->assertStringContainsString( 'Sinopse: Um hacker descobre a verdade sobre a realidade.', $contexto );
	}

	public function test_contexto_filmes_campo_ausente_vira_nao_informado_em_vez_de_sumir(): void {
		// Nunca deixar um campo faltando parecer que "nao tem" (ex: elenco
		// vazio nao pode ser lido pela IA como "confirmado que ninguem
		// conhecido atua nesse filme") -- ver comentario da funcao.
		$contexto = dsi_bilheteiro_montar_contexto_filmes( [ [ 'titulo' => 'Filme sem ficha completa' ] ] );
		$this->assertStringContainsString( 'Gênero: não informado', $contexto );
		$this->assertStringContainsString( 'Diretor: não informado', $contexto );
		$this->assertStringContainsString( 'Elenco conhecido: não informado', $contexto );
		$this->assertStringContainsString( 'Sinopse: não informada', $contexto );
	}

	public function test_contexto_filmes_numera_cada_titulo_em_sequencia(): void {
		$contexto = dsi_bilheteiro_montar_contexto_filmes( [
			[ 'titulo' => 'Filme A' ],
			[ 'titulo' => 'Filme B' ],
		] );
		$this->assertStringContainsString( '1) Título: Filme A', $contexto );
		$this->assertStringContainsString( '2) Título: Filme B', $contexto );
	}

	// -- dsi_bilheteiro_normalizar_item_pergunta -----------------------------

	public function test_normaliza_item_com_resenha_genero_elenco_ano(): void {
		// Contrato de itemListElement (com resenha) -- ver dsi_recomendar_filme.
		$normalizado = dsi_bilheteiro_normalizar_item_pergunta( [
			'titulo'  => 'Matrix',
			'ano'     => 1999,
			'genero'  => [ 'Ação' ],
			'direcao' => [ 'As Wachowski' ],
			'elenco'  => [ 'Keanu Reeves' ],
			'sinopse' => 'Um hacker...',
		] );
		$this->assertSame( [
			'titulo'         => 'Matrix',
			'ano_lancamento' => 1999,
			'generos'        => [ 'Ação' ],
			'diretor'        => 'As Wachowski',
			'atores'         => [ 'Keanu Reeves' ],
			'sinopse'        => 'Um hacker...',
		], $normalizado );
	}

	public function test_normaliza_item_sem_resenha_generos_atores_ano_lancamento(): void {
		// Contrato de sem_resenha -- ver dsi_recomendar_filme. Sem "direcao".
		$normalizado = dsi_bilheteiro_normalizar_item_pergunta( [
			'titulo'         => 'Toy Story 4',
			'ano_lancamento' => 2019,
			'generos'        => [ 'Animação' ],
			'atores'         => [ 'Tom Hanks' ],
			'sinopse'        => 'Woody e a turma...',
		] );
		$this->assertSame( 'Toy Story 4', $normalizado['titulo'] );
		$this->assertSame( 2019, $normalizado['ano_lancamento'] );
		$this->assertSame( [ 'Animação' ], $normalizado['generos'] );
		$this->assertSame( [ 'Tom Hanks' ], $normalizado['atores'] );
		$this->assertNull( $normalizado['diretor'] );
	}

	public function test_normaliza_item_vazio_nao_quebra(): void {
		$normalizado = dsi_bilheteiro_normalizar_item_pergunta( [] );
		$this->assertSame( '', $normalizado['titulo'] );
		$this->assertSame( [], $normalizado['generos'] );
		$this->assertSame( [], $normalizado['atores'] );
		$this->assertNull( $normalizado['diretor'] );
	}

	// -- dsi_bilheteiro_pede_nova_recomendacao -------------------------------

	public function test_pede_nova_recomendacao_nova_simulacao(): void {
		$this->assertTrue( dsi_bilheteiro_pede_nova_recomendacao( 'Posso fazer uma nova simulação?' ) );
	}

	public function test_pede_nova_recomendacao_outro_genero_sem_acento(): void {
		$this->assertTrue( dsi_bilheteiro_pede_nova_recomendacao( 'como escolho outro genero' ) );
	}

	public function test_pede_nova_recomendacao_recomecar(): void {
		$this->assertTrue( dsi_bilheteiro_pede_nova_recomendacao( 'quero recomeçar do zero' ) );
	}

	public function test_pede_nova_recomendacao_trocar_genero(): void {
		$this->assertTrue( dsi_bilheteiro_pede_nova_recomendacao( 'quero trocar de gênero agora' ) );
	}

	public function test_pede_nova_recomendacao_nao_confunde_pergunta_sobre_elenco(): void {
		$this->assertFalse( dsi_bilheteiro_pede_nova_recomendacao( 'esses filmes tem o Ben Stiller?' ) );
	}

	public function test_pede_nova_recomendacao_nao_confunde_pergunta_sobre_genero_do_filme(): void {
		$this->assertFalse( dsi_bilheteiro_pede_nova_recomendacao( 'quais desses são de comédia?' ) );
	}

	// -- dsi_bilheteiro_eh_negativa ------------------------------------------

	#[\PHPUnit\Framework\Attributes\DataProvider( 'negativas' )]
	public function test_eh_negativa_reconhece( string $mensagem ): void {
		$this->assertTrue( dsi_bilheteiro_eh_negativa( $mensagem ), $mensagem );
	}

	public static function negativas(): array {
		return [
			// frases do transcript real de 2026-09-25
			[ 'não' ],
			[ 'oxe já disse que não' ],
			// variacoes comuns
			[ 'Não!' ],
			[ 'nao' ],
			[ 'Nãooo' ],
			[ 'não, não' ],
			[ 'nada não' ],
			[ 'nenhum' ],
			[ 'Ninguém' ],
			[ 'não tenho' ],
			[ 'Não sei' ],
			[ 'tanto faz' ],
			[ 'qualquer um' ],
			[ 'sem preferência' ],
			[ 'Nenhum em especial' ],
			[ 'já falei que nao' ],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'naoNegativas' )]
	public function test_eh_negativa_ignora( string $mensagem ): void {
		$this->assertFalse( dsi_bilheteiro_eh_negativa( $mensagem ), $mensagem );
	}

	public static function naoNegativas(): array {
		return [
			[ '' ],
			[ 'Constantine' ],
			[ 'Netflix' ],
			[ 'não gosto de terror' ],
			[ 'Nosferatu' ],
			[ 'nanana' ],
			// longa demais: pode ter preferencia real no meio
			[ 'não sei direito, mas gosto muito de filmes do Christopher Nolan tipo Interestelar' ],
		];
	}
}
