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
			'nota'           => null,
			'tem_critica'    => false,
			'link'           => '',
			'poster'         => '',
		], $normalizado );
	}

	public function test_normaliza_item_com_critica_guarda_nota_e_link(): void {
		$normalizado = dsi_bilheteiro_normalizar_item_pergunta( [
			'titulo' => 'O Máskara',
			'fonte'  => 'catalogo',
			'nota'   => 6.94,
			'link'   => 'https://deveserisso.com.br/blog/o-maskara-critica/',
		] );
		$this->assertSame( 6.9, $normalizado['nota'] );
		$this->assertTrue( $normalizado['tem_critica'] );
		$this->assertSame( 'https://deveserisso.com.br/blog/o-maskara-critica/', $normalizado['link'] );
	}

	public function test_normaliza_item_descarta_link_de_fora_do_site(): void {
		$normalizado = dsi_bilheteiro_normalizar_item_pergunta( [
			'titulo' => 'Qualquer',
			'fonte'  => 'catalogo',
			'link'   => 'https://exemplo.com/phishing',
		] );
		$this->assertSame( '', $normalizado['link'] );
		$this->assertFalse( $normalizado['tem_critica'] );
	}

	public function test_contexto_filmes_inclui_nota_e_critica(): void {
		$contexto = dsi_bilheteiro_montar_contexto_filmes( [ [
			'titulo'      => 'O Máskara',
			'nota'        => 6.9,
			'tem_critica' => true,
			'link'        => 'https://deveserisso.com.br/blog/o-maskara-critica/',
		] ] );
		$this->assertStringContainsString( 'Nota: 6,9 de 10', $contexto );
		$this->assertStringContainsString( 'Crítica no site: sim, publicada no Deveserisso: https://deveserisso.com.br/blog/o-maskara-critica/', $contexto );
	}

	// -- dsi_bilheteiro_pede_mais_opcoes -------------------------------------

	#[\PHPUnit\Framework\Attributes\DataProvider( 'pedidosMaisOpcoes' )]
	public function test_pede_mais_opcoes_reconhece( string $mensagem ): void {
		$this->assertTrue( dsi_bilheteiro_pede_mais_opcoes( $mensagem ), $mensagem );
	}

	public static function pedidosMaisOpcoes(): array {
		return [
			// frases do relatorio semanal 2026-09-28
			[ 'queria mais' ],
			[ 'tem outros?' ],
			// variacoes
			[ 'mais' ],
			[ 'Quero mais opções' ],
			[ 'tem outras sugestões?' ],
			[ 'mostra mais filmes' ],
			[ 'legal, tem mais outros?' ],
			[ 'mais opções por favor' ],
			[ 'quero outros filmes' ],
		];
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'naoPedidosMaisOpcoes' )]
	public function test_pede_mais_opcoes_ignora( string $mensagem ): void {
		$this->assertFalse( dsi_bilheteiro_pede_mais_opcoes( $mensagem ), $mensagem );
	}

	public static function naoPedidosMaisOpcoes(): array {
		return [
			[ 'quero mais detalhes do segundo' ],
			[ 'tem mais cenas de ação?' ],
			[ 'qual é mais engraçado?' ],
			[ 'esses filmes tem o Ben Stiller?' ],
			[ 'o maskara é bom?' ],
		];
	}

	// -- dsi_recomendacao_modo_filtro ----------------------------------------

	public function test_modo_ator_e_genero_com_titulo_em_comum(): void {
		// "comédia com Ben Stiller": so comedias com ele, sem filme generico.
		$this->assertSame( 'ator_genero', dsi_recomendacao_modo_filtro( true, true, true, true, true ) );
		$this->assertSame( 'ator_genero', dsi_recomendacao_modo_filtro( true, false, true, true, true ) );
	}

	public function test_modo_ator_principal_sem_titulo_no_genero_ator_manda(): void {
		$this->assertSame( 'ator', dsi_recomendacao_modo_filtro( true, true, true, false, true ) );
	}

	public function test_modo_ator_de_gosto_sem_titulo_no_genero_genero_manda(): void {
		// Sessao real 2026-09-28: comedia + 5 atores de drama citados como gosto.
		$this->assertSame( 'genero', dsi_recomendacao_modo_filtro( true, false, true, false, true ) );
	}

	public function test_modo_ator_sem_genero_pedido(): void {
		$this->assertSame( 'ator', dsi_recomendacao_modo_filtro( true, false, false, false, true ) );
	}

	public function test_modo_sem_ator_no_catalogo_genero_filtra(): void {
		$this->assertSame( 'genero', dsi_recomendacao_modo_filtro( false, false, true, false, true ) );
		$this->assertSame( 'livre', dsi_recomendacao_modo_filtro( false, false, true, false, false ) );
		$this->assertSame( 'livre', dsi_recomendacao_modo_filtro( false, false, false, false, true ) );
	}

	// -- dsi_curador_papel_ator ------------------------------------------------

	public function test_papel_ator_so_principal_passa(): void {
		$this->assertSame( 'principal', dsi_curador_papel_ator( 'principal' ) );
		$this->assertSame( 'gosto', dsi_curador_papel_ator( 'gosto' ) );
	}

	public function test_papel_ator_sem_resposta_ou_invalido_vira_gosto(): void {
		$this->assertSame( 'gosto', dsi_curador_papel_ator( null ) );
		$this->assertSame( 'gosto', dsi_curador_papel_ator( 'qualquer' ) );
		$this->assertSame( 'gosto', dsi_curador_papel_ator( [ 'principal' ] ) );
	}

	// -- dsi_generos_chaves_busca / dsi_genero_bate ----------------------------

	public function test_genero_acao_nao_casa_com_animacao(): void {
		$chaves = dsi_generos_chaves_busca( 'acao' );
		$this->assertFalse( dsi_genero_bate( 'animacao', $chaves ) );
		$this->assertTrue( dsi_genero_bate( 'acao', $chaves ) );
		$this->assertTrue( dsi_genero_bate( 'action & adventure', $chaves ) );
	}

	public function test_genero_suspense_e_thriller_sao_o_mesmo(): void {
		$this->assertTrue( dsi_genero_bate( 'suspense', dsi_generos_chaves_busca( 'thriller' ) ) );
		$this->assertTrue( dsi_genero_bate( 'thriller', dsi_generos_chaves_busca( 'suspense' ) ) );
	}

	public function test_genero_ficcao_cientifica_casa_com_nome_da_tmdb(): void {
		$this->assertTrue( dsi_genero_bate( 'sci-fi & fantasy', dsi_generos_chaves_busca( 'ficcao cientifica' ) ) );
	}

	public function test_genero_composto_ainda_casa_pela_palavra(): void {
		$chaves = dsi_generos_chaves_busca( 'comedia' );
		$this->assertTrue( dsi_genero_bate( 'comedia dramatica', $chaves ) );
		$this->assertFalse( dsi_genero_bate( 'comedia dramatica', dsi_generos_chaves_busca( 'drama' ) ) );
		$this->assertTrue( dsi_genero_bate( 'terror psicologico', dsi_generos_chaves_busca( 'terror' ) ) );
	}

	public function test_mais_de_um_genero_vale_qualquer_um(): void {
		// Caso real 2026-09-28: "comédia ou documentários".
		$chaves = dsi_generos_chaves_busca( 'comedia, documentario' );
		$this->assertTrue( dsi_genero_bate( 'documentario', $chaves ) );
		$this->assertTrue( dsi_genero_bate( 'comedia', $chaves ) );
		$this->assertFalse( dsi_genero_bate( 'terror', $chaves ) );
	}

	// -- dsi_bilheteiro_sem_email ---------------------------------------------

	public function test_sem_email_troca_o_endereco(): void {
		$this->assertSame( 'cadastra meu email [e-mail removido] por favor', dsi_bilheteiro_sem_email( 'cadastra meu email fulano.teste@gmail.com por favor' ) );
		$this->assertSame( 'quero comédia', dsi_bilheteiro_sem_email( 'quero comédia' ) );
	}

	// -- dsi_bilheteiro_lista_nomes ------------------------------------------

	public function test_lista_nomes(): void {
		$this->assertSame( '', dsi_bilheteiro_lista_nomes( [] ) );
		$this->assertSame( 'Meryl Streep', dsi_bilheteiro_lista_nomes( [ 'Meryl Streep' ] ) );
		$this->assertSame( 'Meryl Streep e Gary Oldman', dsi_bilheteiro_lista_nomes( [ 'Meryl Streep', 'Gary Oldman' ] ) );
		$this->assertSame( 'Meryl Streep, Gary Oldman e mais 3', dsi_bilheteiro_lista_nomes( [ 'Meryl Streep', 'Gary Oldman', 'Keanu Reeves', 'Robert De Niro', 'Al Pacino' ] ) );
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

	// -- dsi_ambientacao_acertos -----------------------------------------------

	public function test_ambientacao_palavra_forte_basta(): void {
		$this->assertGreaterThan( 0, dsi_ambientacao_acertos( 'idade media', 'rei arthur: a lenda da espada', 'o jovem arthur descobre seu destino' ) );
		$this->assertGreaterThan( 0, dsi_ambientacao_acertos( 'idade media', 'o ultimo rei', 'noruega medieval, 1206' ) );
	}

	public function test_ambientacao_palavras_fracas_precisam_de_duas_e_nao_valem_no_titulo(): void {
		// Casos reais do primeiro teste (2026-09-28).
		$this->assertSame( 0, dsi_ambientacao_acertos( 'idade media', 'historias cruzadas', 'no mississippi dos anos 60, uma jovem escritora' ) );
		$this->assertSame( 0, dsi_ambientacao_acertos( 'idade media', 'batman: o cavaleiro das trevas', 'batman enfrenta o coringa em gotham' ) );
		$this->assertGreaterThan( 0, dsi_ambientacao_acertos( 'idade media', 'cruzada', 'balian junta-se ao pai nas cruzadas a caminho de jerusalem' ) );
	}

	public function test_ambientacao_desconhecida(): void {
		$this->assertFalse( dsi_ambientacao_existe( 'qualquer' ) );
		$this->assertTrue( dsi_ambientacao_existe( 'idade media' ) );
		$this->assertSame( 0, dsi_ambientacao_acertos( 'qualquer', 'x', 'medieval' ) );
	}

	// -- dsi_bilheteiro_escolher_opcao -----------------------------------------

	private function opcoesOReino(): array {
		return [
			[ 'titulo' => 'O Reino', 'ano' => 1994, 'tipo' => 'serie' ],
			[ 'titulo' => 'O Reino', 'ano' => 2012, 'tipo' => 'filme' ],
			[ 'titulo' => 'O Reino', 'ano' => 2007, 'tipo' => 'filme' ],
		];
	}

	public function test_escolher_opcao_pelo_rotulo_do_botao(): void {
		$this->assertSame( 2, dsi_bilheteiro_escolher_opcao( 'o reino (filme, 2007)', $this->opcoesOReino() ) );
		$this->assertSame( 0, dsi_bilheteiro_escolher_opcao( 'o reino (serie, 1994)', $this->opcoesOReino() ) );
	}

	public function test_escolher_opcao_por_numero_ano_ou_tipo(): void {
		$this->assertSame( 1, dsi_bilheteiro_escolher_opcao( '2', $this->opcoesOReino() ) );
		$this->assertSame( 1, dsi_bilheteiro_escolher_opcao( 'o de 2012', $this->opcoesOReino() ) );
		$this->assertSame( 0, dsi_bilheteiro_escolher_opcao( 'a serie', $this->opcoesOReino() ) );
	}

	public function test_escolher_opcao_nenhum_ou_outra_mensagem(): void {
		$this->assertSame( -1, dsi_bilheteiro_escolher_opcao( 'nenhum desses', $this->opcoesOReino() ) );
		$this->assertNull( dsi_bilheteiro_escolher_opcao( 'cruzada', $this->opcoesOReino() ) );
		// "filme" sozinho nao decide: ha dois filmes
		$this->assertNull( dsi_bilheteiro_escolher_opcao( 'o filme', $this->opcoesOReino() ) );
	}

	// -- dsi_epoca_valida -------------------------------------------------------

	public function test_epoca_valida_aceita_chaves_e_rotulos(): void {
		$this->assertSame( 'idade media', dsi_epoca_valida( 'idade media' ) );
		$this->assertSame( 'idade media', dsi_epoca_valida( 'Idade Média' ) );
		$this->assertSame( 'espaco', dsi_epoca_valida( 'Espaço' ) );
		$this->assertSame( 'nenhuma', dsi_epoca_valida( 'nenhuma' ) );
	}

	public function test_epoca_valida_recusa_o_resto(): void {
		$this->assertNull( dsi_epoca_valida( 'anos 90' ) );
		$this->assertNull( dsi_epoca_valida( null ) );
		$this->assertNull( dsi_epoca_valida( [ 'idade media' ] ) );
	}

	// -- A2UI: cliente, urls, resposta do modelo, cartao, acao ------------------

	public function test_a2ui_cliente_so_suporta_se_declarar_o_catalogo(): void {
		$this->assertTrue( dsi_a2ui_cliente_suporta( [ 'supportedCatalogIds' => [ DSI_A2UI_CATALOGO ] ] ) );
		$this->assertFalse( dsi_a2ui_cliente_suporta( [ 'supportedCatalogIds' => [ 'https://outro/catalogo' ] ] ) );
		$this->assertFalse( dsi_a2ui_cliente_suporta( [] ) );
		$this->assertFalse( dsi_a2ui_cliente_suporta( null ) );
		$this->assertFalse( dsi_a2ui_cliente_suporta( 'texto' ) );
	}

	public function test_a2ui_url_permitida(): void {
		$img = DSI_A2UI_HOSTS_IMAGEM;
		$this->assertTrue( dsi_a2ui_url_permitida( 'https://image.tmdb.org/t/p/w500/x.jpg', $img ) );
		$this->assertTrue( dsi_a2ui_url_permitida( 'https://www.deveserisso.com.br/a/b.webp', $img ) );
		$this->assertFalse( dsi_a2ui_url_permitida( 'http://image.tmdb.org/x.jpg', $img ) );
		$this->assertFalse( dsi_a2ui_url_permitida( 'https://deveserisso.com.br.evil.com/x', $img ) );
		$this->assertFalse( dsi_a2ui_url_permitida( 'https://evil.com/deveserisso.com.br', $img ) );
		$this->assertFalse( dsi_a2ui_url_permitida( 'https://user:senha@deveserisso.com.br/x', $img ) );
		$this->assertFalse( dsi_a2ui_url_permitida( 'javascript:alert(1)', $img ) );
		$this->assertFalse( dsi_a2ui_url_permitida( '', $img ) );
		$this->assertFalse( dsi_a2ui_url_permitida( null, $img ) );
		$this->assertFalse( dsi_a2ui_url_permitida( 'https://image.tmdb.org/x.jpg', DSI_A2UI_HOSTS_LINK ) );
	}

	public function test_a2ui_interpreta_resposta_valida(): void {
		$r = dsi_a2ui_interpretar_resposta( '{"resposta":"Tem crítica no site.","intencao":"avaliar_titulo","titulo":2}', 5 );
		$this->assertSame( [ 'resposta' => 'Tem crítica no site.', 'intencao' => 'avaliar_titulo', 'titulo' => 2 ], $r );
	}

	public function test_a2ui_titulo_fora_da_lista_vira_outra(): void {
		foreach ( [ 0, 6, -1, 'x' ] as $numero ) {
			$r = dsi_a2ui_interpretar_resposta( json_encode( [ 'resposta' => 'ok', 'intencao' => 'avaliar_titulo', 'titulo' => $numero ] ), 5 );
			$this->assertSame( 'outra', $r['intencao'], (string) $numero );
			$this->assertNull( $r['titulo'] );
			$this->assertSame( 'ok', $r['resposta'] );
		}
	}

	public function test_a2ui_intencao_desconhecida_vira_outra(): void {
		$r = dsi_a2ui_interpretar_resposta( '{"resposta":"ok","intencao":"comprar","titulo":1}', 5 );
		$this->assertSame( 'outra', $r['intencao'] );
	}

	public function test_a2ui_sem_json_usa_o_texto_puro(): void {
		$r = dsi_a2ui_interpretar_resposta( "  O Zoolander tem crítica no site.  ", 5 );
		$this->assertSame( [ 'resposta' => 'O Zoolander tem crítica no site.', 'intencao' => 'outra', 'titulo' => null ], $r );
		$this->assertSame( 'outra', dsi_a2ui_interpretar_resposta( '{"resposta":"","intencao":"avaliar_titulo","titulo":1}', 5 )['intencao'] );
	}

	private function itemCartao(): array {
		return [
			'titulo' => 'Zoolander', 'ano_lancamento' => 2001, 'nota' => 6.2, 'tem_critica' => true,
			'link' => 'https://deveserisso.com.br/zoolander-critica/', 'poster' => 'https://image.tmdb.org/t/p/w500/z.jpg',
		];
	}

	private function componentesDe( array $mensagens ): array {
		$por_id = [];
		foreach ( $mensagens[1]['updateComponents']['components'] as $c ) {
			$por_id[ $c['id'] ] = $c;
		}
		return $por_id;
	}

	public function test_a2ui_cartao_tem_tres_mensagens_no_formato_da_especificacao(): void {
		$m = dsi_a2ui_cartao_avaliacao( $this->itemCartao(), 'Tem crítica publicada.', 'avaliacao-1-zoolander-abc123' );
		$this->assertCount( 3, $m );
		$this->assertSame( 'v0.9', $m[0]['version'] );
		$this->assertSame( DSI_A2UI_CATALOGO, $m[0]['createSurface']['catalogId'] );
		$this->assertSame( 'avaliacao-1-zoolander-abc123', $m[1]['updateComponents']['surfaceId'] );
		$this->assertSame( '/', $m[2]['updateDataModel']['path'] );
		$this->assertSame( 'Zoolander (2001)', $m[2]['updateDataModel']['value']['titulo'] );
		$this->assertSame( '6,2 · média do público no TMDB', $m[2]['updateDataModel']['value']['nota'] );
		$this->assertSame( 'Tem crítica publicada.', $m[2]['updateDataModel']['value']['frase'] );
	}

	public function test_a2ui_cartao_so_usa_componentes_do_catalogo_e_arvore_valida(): void {
		$permitidos = [ 'Card', 'Column', 'Row', 'Image', 'Text', 'Button' ];
		$c          = $this->componentesDe( dsi_a2ui_cartao_avaliacao( $this->itemCartao(), 'x', 's1' ) );
		$this->assertArrayHasKey( 'root', $c );
		foreach ( $c as $comp ) {
			$this->assertContains( $comp['component'], $permitidos );
			foreach ( array_merge( (array) ( $comp['children'] ?? [] ), isset( $comp['child'] ) ? [ $comp['child'] ] : [] ) as $filho ) {
				$this->assertArrayHasKey( $filho, $c, 'filho inexistente: ' . $filho );
			}
		}
	}

	public function test_a2ui_cartao_botoes(): void {
		$c = $this->componentesDe( dsi_a2ui_cartao_avaliacao( $this->itemCartao(), 'x', 's1' ) );
		$this->assertSame( 'openUrl', $c['ler_critica']['action']['functionCall']['call'] );
		$this->assertSame( 'https://deveserisso.com.br/zoolander-critica/', $c['ler_critica']['action']['functionCall']['args']['url'] );
		$this->assertSame( 'mais_parecidos', $c['parecidos']['action']['event']['name'] );
		$this->assertSame( 'Zoolander', $c['parecidos']['action']['event']['context']['titulo'] );
	}

	public function test_a2ui_cartao_sem_critica_nao_tem_botao_de_critica(): void {
		$item = $this->itemCartao();
		$item['tem_critica'] = false;
		$c = $this->componentesDe( dsi_a2ui_cartao_avaliacao( $item, 'x', 's1' ) );
		$this->assertArrayNotHasKey( 'ler_critica', $c );
		$this->assertArrayHasKey( 'parecidos', $c );
		$this->assertSame( [ 'parecidos' ], $c['botoes']['children'] );
	}

	public function test_a2ui_cartao_link_de_fora_do_site_nao_vira_botao(): void {
		$item = $this->itemCartao();
		$item['link'] = 'https://exemplo.com/phishing';
		$c = $this->componentesDe( dsi_a2ui_cartao_avaliacao( $item, 'x', 's1' ) );
		$this->assertArrayNotHasKey( 'ler_critica', $c );
	}

	public function test_a2ui_cartao_sem_nota_e_sem_poster_sai_enxuto(): void {
		$item = [ 'titulo' => 'Filme Qualquer', 'poster' => 'https://exemplo.com/p.jpg' ];
		$m    = dsi_a2ui_cartao_avaliacao( $item, 'Sem dados.', 's1' );
		$c    = $this->componentesDe( $m );
		$this->assertArrayNotHasKey( 'poster', $c );
		$this->assertArrayNotHasKey( 'nota', $c );
		$this->assertSame( 'Filme Qualquer', $m[2]['updateDataModel']['value']['titulo'] );
		$this->assertSame( '', $m[2]['updateDataModel']['value']['poster'] );
		$this->assertSame( [ 'textos' ], $c['topo']['children'] );
	}

	public function test_a2ui_validar_acao(): void {
		$this->assertSame( [ 'mais_parecidos', 'Zoolander' ], dsi_a2ui_validar_acao( [ 'name' => 'mais_parecidos', 'context' => [ 'titulo' => ' Zoolander ' ] ] ) );
		$this->assertNull( dsi_a2ui_validar_acao( [ 'name' => 'apagar_tudo', 'context' => [ 'titulo' => 'x' ] ] ) );
		$this->assertNull( dsi_a2ui_validar_acao( [ 'name' => 'mais_parecidos', 'context' => [] ] ) );
		$this->assertNull( dsi_a2ui_validar_acao( [ 'name' => 'mais_parecidos', 'context' => [ 'titulo' => str_repeat( 'a', 121 ) ] ] ) );
		$this->assertNull( dsi_a2ui_validar_acao( 'texto' ) );
	}

	public function test_a2ui_frase_sem_links(): void {
		$this->assertSame(
			'Zoolander tem crítica publicada no Deveserisso e nota 6,2 de 10.',
			dsi_a2ui_frase_sem_links( 'Zoolander tem crítica publicada no Deveserisso: https://deveserisso.com.br/zoolander/ e nota 6,2 de 10.' )
		);
		$this->assertSame( 'Vale conferir a crítica.', dsi_a2ui_frase_sem_links( 'Vale conferir a crítica. https://deveserisso.com.br/x/' ) );
		$this->assertSame( 'Sem endereço nenhum.', dsi_a2ui_frase_sem_links( 'Sem endereço nenhum.' ) );
		// so link: nao devolve string vazia
		$this->assertSame( 'https://deveserisso.com.br/x/', dsi_a2ui_frase_sem_links( 'https://deveserisso.com.br/x/' ) );
	}
}
