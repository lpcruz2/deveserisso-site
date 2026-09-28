<?php
/**
 * Perfil de gosto -- acesso a dados (docs/erd-perfil-de-gosto.md).
 *
 * So PDO, sem $wpdb: a conexao vem de fora (no WordPress, montada com as
 * constantes do wp-config; nos testes, SQLite em memoria). Datas em UTC,
 * formato 'Y-m-d H:i:s'. Exclusoes em cascata feitas aqui, em transacao.
 */

require_once __DIR__ . '/regras.php';
require_once __DIR__ . '/esquema.php';

final class DSI_Perfil_Repositorio {

	private PDO $db;
	private string $p;
	/** @var callable():string */
	private $relogio;

	public function __construct( PDO $db, string $prefixo, ?callable $relogio = null ) {
		$db->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
		$db->setAttribute( PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC );
		$this->db      = $db;
		$this->p       = $prefixo;
		$this->relogio = $relogio ?? fn() => gmdate( 'Y-m-d H:i:s' );
	}

	private function agora(): string {
		return ( $this->relogio )();
	}

	private function um( string $sql, array $args = [] ): ?array {
		$st = $this->db->prepare( $sql );
		$st->execute( $args );
		$linha = $st->fetch();
		return $linha === false ? null : $linha;
	}

	private function todos( string $sql, array $args = [] ): array {
		$st = $this->db->prepare( $sql );
		$st->execute( $args );
		return $st->fetchAll();
	}

	private function exec( string $sql, array $args = [] ): int {
		$st = $this->db->prepare( $sql );
		$st->execute( $args );
		return $st->rowCount();
	}

	private function transacao( callable $fn ) {
		$this->db->beginTransaction();
		try {
			$r = $fn();
			$this->db->commit();
			return $r;
		} catch ( Throwable $e ) {
			$this->db->rollBack();
			throw $e;
		}
	}

	public function criarEsquema( string $dialeto ): void {
		foreach ( dsi_perfil_esquema_sql( $this->p, $dialeto ) as $sql ) {
			$this->db->exec( $sql );
		}
	}

	// ---------------------------------------------------------------- conta

	public function contaPorSub( string $google_sub ): ?array {
		return $this->um( "SELECT * FROM {$this->p}conta WHERE google_sub = ?", [ $google_sub ] );
	}

	public function conta( int $id ): ?array {
		return $this->um( "SELECT * FROM {$this->p}conta WHERE id = ?", [ $id ] );
	}

	/** Cria a conta ja com o termo aceito (R2: nao existe conta sem termo). */
	public function criarConta( string $google_sub, string $email, string $nome, string $origem, string $versao_termo ): array {
		if ( $google_sub === '' || $email === '' || $versao_termo === '' ) {
			throw new InvalidArgumentException( 'sub, e-mail e versao do termo sao obrigatorios' );
		}
		$existente = $this->contaPorSub( $google_sub );
		if ( $existente ) {
			return $existente;
		}
		return $this->transacao( function () use ( $google_sub, $email, $nome, $origem, $versao_termo ) {
			$agora = $this->agora();
			$this->exec(
				"INSERT INTO {$this->p}conta (google_sub, email, nome_exibicao, origem_cadastro, criado_em, ultimo_acesso_em) VALUES (?, ?, ?, ?, ?, ?)",
				[ $google_sub, $email, mb_substr( $nome, 0, 190 ), dsi_perfil_canal( $origem ), $agora, $agora ]
			);
			$id = (int) $this->db->lastInsertId();
			$this->exec(
				"INSERT INTO {$this->p}consentimento (conta_id, tipo, versao, aceito_em) VALUES (?, 'termo_perfil', ?, ?)",
				[ $id, $versao_termo, $agora ]
			);
			return $this->conta( $id );
		} );
	}

	/** Atualiza dados do Google e o ultimo acesso; zera o aviso de retencao. */
	public function registrarAcesso( int $conta_id, string $email, string $nome ): void {
		$this->exec(
			"UPDATE {$this->p}conta SET email = ?, nome_exibicao = ?, ultimo_acesso_em = ?, aviso_retencao_em = NULL WHERE id = ?",
			[ $email, mb_substr( $nome, 0, 190 ), $this->agora(), $conta_id ]
		);
	}

	public function aceitarConsentimento( int $conta_id, string $tipo, string $versao ): void {
		if ( $this->temConsentimento( $conta_id, $tipo ) ) {
			return;
		}
		$this->exec(
			"INSERT INTO {$this->p}consentimento (conta_id, tipo, versao, aceito_em) VALUES (?, ?, ?, ?)",
			[ $conta_id, $tipo, $versao, $this->agora() ]
		);
	}

	public function temConsentimento( int $conta_id, string $tipo ): bool {
		return (bool) $this->um(
			"SELECT id FROM {$this->p}consentimento WHERE conta_id = ? AND tipo = ? AND revogado_em IS NULL",
			[ $conta_id, $tipo ]
		);
	}

	// ------------------------------------------------------------ marcacoes

	/**
	 * Marca ou desmarca (R3). Dois nulos = linha apagada.
	 *
	 * @return array|null a marcacao gravada, ou null se ficou vazia
	 */
	public function marcar( int $conta_id, $tmdb_id, $tipo, $visto, $avaliacao, string $canal = 'site' ): ?array {
		$m = dsi_perfil_marcacao_valida( $tmdb_id, $tipo, $visto, $avaliacao );
		if ( $m === null ) {
			throw new InvalidArgumentException( 'marcacao invalida' );
		}
		return $this->transacao( function () use ( $conta_id, $m, $canal ) {
			$chave = [ $conta_id, $m['tmdb_id'], $m['tipo'] ];
			if ( $m['visto'] === null && $m['avaliacao'] === null ) {
				$this->exec( "DELETE FROM {$this->p}marcacao WHERE conta_id = ? AND tmdb_id = ? AND tipo = ?", $chave );
				return null;
			}
			$agora = $this->agora();
			$atual = $this->um( "SELECT id FROM {$this->p}marcacao WHERE conta_id = ? AND tmdb_id = ? AND tipo = ?", $chave );
			if ( $atual ) {
				$this->exec(
					"UPDATE {$this->p}marcacao SET visto = ?, avaliacao = ?, canal = ?, atualizado_em = ? WHERE id = ?",
					[ $m['visto'], $m['avaliacao'], dsi_perfil_canal( $canal ), $agora, $atual['id'] ]
				);
			} else {
				$this->exec(
					"INSERT INTO {$this->p}marcacao (conta_id, tmdb_id, tipo, visto, avaliacao, canal, criado_em, atualizado_em) VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
					[ $conta_id, $m['tmdb_id'], $m['tipo'], $m['visto'], $m['avaliacao'], dsi_perfil_canal( $canal ), $agora, $agora ]
				);
			}
			return $this->um( "SELECT tmdb_id, tipo, visto, avaliacao, canal, atualizado_em FROM {$this->p}marcacao WHERE conta_id = ? AND tmdb_id = ? AND tipo = ?", $chave );
		} );
	}

	public function marcacoes( int $conta_id ): array {
		return array_map( [ $this, 'tiparMarcacao' ], $this->todos(
			"SELECT tmdb_id, tipo, visto, avaliacao, canal, atualizado_em FROM {$this->p}marcacao WHERE conta_id = ? ORDER BY atualizado_em DESC, id DESC",
			[ $conta_id ]
		) );
	}

	private function tiparMarcacao( array $m ): array {
		$m['tmdb_id'] = (int) $m['tmdb_id'];
		return $m;
	}

	// --------------------------------------------------------- plataformas

	public function definirPlataformas( int $conta_id, array $slugs ): array {
		$ok = dsi_perfil_plataformas_validas( $slugs );
		$this->transacao( function () use ( $conta_id, $ok ) {
			$this->exec( "DELETE FROM {$this->p}preferencias WHERE conta_id = ?", [ $conta_id ] );
			$this->exec(
				"INSERT INTO {$this->p}preferencias (conta_id, plataformas, atualizado_em) VALUES (?, ?, ?)",
				[ $conta_id, json_encode( $ok ), $this->agora() ]
			);
		} );
		return $ok;
	}

	public function plataformas( int $conta_id ): array {
		$l = $this->um( "SELECT plataformas FROM {$this->p}preferencias WHERE conta_id = ?", [ $conta_id ] );
		return $l ? ( json_decode( $l['plataformas'], true ) ?: [] ) : [];
	}

	// ------------------------------------------------------------ resenhas

	/**
	 * Envia ou edita a resenha da conta pra um titulo (R10). Sempre cria uma
	 * versao pendente; a publicada (se houver) continua no ar ate o gestor
	 * aprovar a nova. Uma pendente anterior vira "substituida".
	 *
	 * @return array{ok:bool,erro:?string,resenha_id?:int,versao_id?:int,sinais?:string[]}
	 */
	public function enviarResenha( int $conta_id, $tmdb_id, $tipo, string $texto, string $link, bool $spoiler, string $canal = 'site' ): array {
		if ( ! $this->temConsentimento( $conta_id, 'resenha_publica' ) ) {
			return [ 'ok' => false, 'erro' => 'sem_consentimento' ];
		}
		$titulo = dsi_perfil_marcacao_valida( $tmdb_id, $tipo, null, null );
		if ( $titulo === null ) {
			return [ 'ok' => false, 'erro' => 'titulo_invalido' ];
		}
		$rede = dsi_perfil_rede_social( $link );
		if ( $rede === null ) {
			return [ 'ok' => false, 'erro' => 'link_invalido' ];
		}
		$limpo = dsi_perfil_texto_resenha( $texto );
		if ( $limpo['erro'] !== null ) {
			return [ 'ok' => false, 'erro' => 'texto_' . $limpo['erro'] ];
		}
		$canal = dsi_perfil_canal( $canal );

		return $this->transacao( function () use ( $conta_id, $titulo, $rede, $limpo, $spoiler, $canal ) {
			$agora   = $this->agora();
			$sinais  = $limpo['sinais'];
			$chave   = [ $conta_id, $titulo['tmdb_id'], $titulo['tipo'] ];
			$resenha = $this->um( "SELECT id FROM {$this->p}resenha WHERE conta_id = ? AND tmdb_id = ? AND tipo = ?", $chave );
			if ( ! $resenha ) {
				$this->exec(
					"INSERT INTO {$this->p}resenha (conta_id, tmdb_id, tipo, canal, criada_em) VALUES (?, ?, ?, ?, ?)",
					[ $conta_id, $titulo['tmdb_id'], $titulo['tipo'], $canal, $agora ]
				);
				$resenha_id = (int) $this->db->lastInsertId();
			} else {
				$resenha_id = (int) $resenha['id'];
			}

			// Mesmo texto enviado por outra conta: sinal pra fila.
			$repetido = $this->um(
				"SELECT v.id FROM {$this->p}resenha_versao v JOIN {$this->p}resenha r ON r.id = v.resenha_id WHERE v.texto = ? AND r.conta_id <> ? LIMIT 1",
				[ $limpo['texto'], $conta_id ]
			);
			if ( $repetido ) {
				$sinais[] = 'repetido';
			}

			$this->exec(
				"UPDATE {$this->p}resenha_versao SET status = 'substituida', decidida_em = ? WHERE resenha_id = ? AND status = 'pendente'",
				[ $agora, $resenha_id ]
			);
			$this->exec(
				"INSERT INTO {$this->p}resenha_versao (resenha_id, texto, link_social, rede, handle, spoiler, status, sinais_filtro, canal, enviada_em) VALUES (?, ?, ?, ?, ?, ?, 'pendente', ?, ?, ?)",
				[ $resenha_id, $limpo['texto'], $rede['url'], $rede['rede'], $rede['handle'], $spoiler ? 1 : 0, $sinais ? implode( ',', $sinais ) : null, $canal, $agora ]
			);
			$versao_id = (int) $this->db->lastInsertId();
			$this->exec( "UPDATE {$this->p}conta SET link_social = ? WHERE id = ?", [ $rede['url'], $conta_id ] );

			return [ 'ok' => true, 'erro' => null, 'resenha_id' => $resenha_id, 'versao_id' => $versao_id, 'sinais' => $sinais ];
		} );
	}

	/** A pessoa apaga a propria resenha: sai do ar na hora, com o historico. */
	public function apagarResenha( int $conta_id, $tmdb_id, $tipo ): bool {
		$r = $this->um( "SELECT id FROM {$this->p}resenha WHERE conta_id = ? AND tmdb_id = ? AND tipo = ?", [ $conta_id, (int) $tmdb_id, (string) $tipo ] );
		if ( ! $r ) {
			return false;
		}
		$this->transacao( function () use ( $r ) {
			$this->exec( "DELETE FROM {$this->p}resenha_versao WHERE resenha_id = ?", [ $r['id'] ] );
			$this->exec( "DELETE FROM {$this->p}resenha WHERE id = ?", [ $r['id'] ] );
		} );
		return true;
	}

	/** Gestor aprova: esta versao vai ao ar, a anterior vira "substituida". */
	public function aprovarVersao( int $versao_id ): bool {
		return $this->transacao( function () use ( $versao_id ) {
			$v = $this->um( "SELECT * FROM {$this->p}resenha_versao WHERE id = ?", [ $versao_id ] );
			if ( ! $v || $v['status'] !== 'pendente' ) {
				return false;
			}
			$agora = $this->agora();
			$r     = $this->um( "SELECT versao_publicada_id FROM {$this->p}resenha WHERE id = ?", [ $v['resenha_id'] ] );
			if ( $r && $r['versao_publicada_id'] ) {
				$this->exec( "UPDATE {$this->p}resenha_versao SET status = 'substituida' WHERE id = ?", [ $r['versao_publicada_id'] ] );
			}
			$this->exec( "UPDATE {$this->p}resenha_versao SET status = 'aprovada', decidida_em = ? WHERE id = ?", [ $agora, $versao_id ] );
			$this->exec( "UPDATE {$this->p}resenha SET versao_publicada_id = ? WHERE id = ?", [ $versao_id, $v['resenha_id'] ] );
			return true;
		} );
	}

	/** Gestor recusa: a versao no ar (se houver) nao muda. */
	public function recusarVersao( int $versao_id, string $motivo ): bool {
		if ( ! in_array( $motivo, DSI_PERFIL_MOTIVOS, true ) ) {
			throw new InvalidArgumentException( 'motivo invalido' );
		}
		return $this->exec(
			"UPDATE {$this->p}resenha_versao SET status = 'recusada', motivo_recusa = ?, decidida_em = ? WHERE id = ? AND status = 'pendente'",
			[ $motivo, $this->agora(), $versao_id ]
		) > 0;
	}

	/** Fila do gestor: pendentes, mais antigas primeiro, com a versao no ar pra comparar. */
	public function filaAprovacao( int $limite = 50 ): array {
		$linhas = $this->todos(
			"SELECT v.id AS versao_id, v.texto, v.link_social, v.rede, v.handle, v.spoiler, v.sinais_filtro, v.canal, v.enviada_em,
			        r.id AS resenha_id, r.tmdb_id, r.tipo, r.conta_id,
			        pub.texto AS texto_no_ar
			 FROM {$this->p}resenha_versao v
			 JOIN {$this->p}resenha r ON r.id = v.resenha_id
			 LEFT JOIN {$this->p}resenha_versao pub ON pub.id = r.versao_publicada_id
			 WHERE v.status = 'pendente'
			 ORDER BY v.enviada_em ASC, v.id ASC
			 LIMIT " . max( 1, min( 200, $limite ) )
		);
		foreach ( $linhas as &$l ) {
			$l['tmdb_id']        = (int) $l['tmdb_id'];
			$l['spoiler']        = (bool) $l['spoiler'];
			$l['sinais']         = $l['sinais_filtro'] ? explode( ',', $l['sinais_filtro'] ) : [];
			$l['tem_critica']    = $this->criticaExiste( $l['tmdb_id'], $l['tipo'] );
			unset( $l['sinais_filtro'] );
		}
		return $linhas;
	}

	/** Resenhas no ar de um titulo (R12): aprovadas E com critica publicada. */
	public function resenhasNoAr( $tmdb_id, $tipo ): array {
		if ( ! $this->criticaExiste( (int) $tmdb_id, (string) $tipo ) ) {
			return [];
		}
		$linhas = $this->todos(
			"SELECT v.texto, v.handle, v.rede, v.link_social, v.spoiler, v.decidida_em AS aprovada_em
			 FROM {$this->p}resenha r JOIN {$this->p}resenha_versao v ON v.id = r.versao_publicada_id
			 WHERE r.tmdb_id = ? AND r.tipo = ? AND v.status = 'aprovada'
			 ORDER BY v.decidida_em DESC, v.id DESC",
			[ (int) $tmdb_id, (string) $tipo ]
		);
		foreach ( $linhas as &$l ) {
			$l['spoiler'] = (bool) $l['spoiler'];
		}
		return $linhas;
	}

	/** Titulos sem critica com resenhas esperando (sinal de pauta). */
	public function filaPauta(): array {
		$linhas = $this->todos(
			"SELECT r.tmdb_id, r.tipo, COUNT(DISTINCT r.id) AS resenhas
			 FROM {$this->p}resenha r
			 LEFT JOIN {$this->p}titulo_post t ON t.tmdb_id = r.tmdb_id AND t.tipo = r.tipo
			 WHERE t.post_id IS NULL
			   AND (r.versao_publicada_id IS NOT NULL
			        OR EXISTS (SELECT 1 FROM {$this->p}resenha_versao v WHERE v.resenha_id = r.id AND v.status = 'pendente'))
			 GROUP BY r.tmdb_id, r.tipo
			 ORDER BY resenhas DESC, r.tmdb_id ASC"
		);
		return array_map( fn( $l ) => [ 'tmdb_id' => (int) $l['tmdb_id'], 'tipo' => $l['tipo'], 'resenhas' => (int) $l['resenhas'] ], $linhas );
	}

	/** Resenhas da pessoa, com o estado que ela ve em "Minha conta". */
	public function resenhasDaConta( int $conta_id ): array {
		$saida = [];
		foreach ( $this->todos( "SELECT * FROM {$this->p}resenha WHERE conta_id = ? ORDER BY criada_em DESC, id DESC", [ $conta_id ] ) as $r ) {
			$ultima = $this->um( "SELECT * FROM {$this->p}resenha_versao WHERE resenha_id = ? ORDER BY id DESC LIMIT 1", [ $r['id'] ] );
			$pub    = $r['versao_publicada_id'] ? $this->um( "SELECT * FROM {$this->p}resenha_versao WHERE id = ?", [ $r['versao_publicada_id'] ] ) : null;
			$no_ar  = dsi_perfil_resenha_no_ar( $r['versao_publicada_id'] ? (int) $r['versao_publicada_id'] : null, $this->criticaExiste( (int) $r['tmdb_id'], $r['tipo'] ) );
			$saida[] = [
				'tmdb_id'          => (int) $r['tmdb_id'],
				'tipo'             => $r['tipo'],
				'texto'            => $ultima['texto'],
				'link_social'      => $ultima['link_social'],
				'spoiler'          => (bool) $ultima['spoiler'],
				'status'           => $ultima['status'],
				'motivo_recusa'    => $ultima['motivo_recusa'],
				'texto_no_ar'      => $pub ? $pub['texto'] : null,
				'no_ar'            => $no_ar,
				'esperando_critica'=> $pub !== null && ! $no_ar,
				'enviada_em'       => $ultima['enviada_em'],
			];
		}
		return $saida;
	}

	// ------------------------------------------------- critica <-> titulo

	public function vincularTituloPost( int $post_id, int $tmdb_id, string $tipo, string $fonte ): void {
		if ( $post_id <= 0 || $tmdb_id <= 0 || ! in_array( $tipo, DSI_PERFIL_TIPOS, true ) ) {
			throw new InvalidArgumentException( 'vinculo invalido' );
		}
		$this->transacao( function () use ( $post_id, $tmdb_id, $tipo, $fonte ) {
			$this->exec( "DELETE FROM {$this->p}titulo_post WHERE post_id = ?", [ $post_id ] );
			$this->exec(
				"INSERT INTO {$this->p}titulo_post (post_id, tmdb_id, tipo, fonte, atualizado_em) VALUES (?, ?, ?, ?, ?)",
				[ $post_id, $tmdb_id, $tipo, mb_substr( $fonte, 0, 20 ), $this->agora() ]
			);
		} );
	}

	public function desvincularPost( int $post_id ): void {
		$this->exec( "DELETE FROM {$this->p}titulo_post WHERE post_id = ?", [ $post_id ] );
	}

	public function tituloDoPost( int $post_id ): ?array {
		$l = $this->um( "SELECT tmdb_id, tipo, fonte FROM {$this->p}titulo_post WHERE post_id = ?", [ $post_id ] );
		if ( $l ) {
			$l['tmdb_id'] = (int) $l['tmdb_id'];
		}
		return $l;
	}

	/** @return int[] */
	public function postsDoTitulo( int $tmdb_id, string $tipo ): array {
		return array_map( 'intval', array_column( $this->todos(
			"SELECT post_id FROM {$this->p}titulo_post WHERE tmdb_id = ? AND tipo = ? ORDER BY post_id",
			[ $tmdb_id, $tipo ]
		), 'post_id' ) );
	}

	public function criticaExiste( int $tmdb_id, string $tipo ): bool {
		return (bool) $this->um( "SELECT post_id FROM {$this->p}titulo_post WHERE tmdb_id = ? AND tipo = ? LIMIT 1", [ $tmdb_id, $tipo ] );
	}

	// ------------------------------------------------- LGPD e retencao

	/** Tudo da pessoa, no formato da exportacao (R6, R9). */
	public function exportar( int $conta_id ): ?array {
		$c = $this->conta( $conta_id );
		if ( ! $c ) {
			return null;
		}
		return [
			'conta'          => [
				'email'         => $c['email'],
				'nome_exibicao' => $c['nome_exibicao'],
				'criado_em'     => $c['criado_em'],
				'visibilidade'  => $c['visibilidade'],
				'link_social'   => $c['link_social'],
			],
			'consentimentos' => $this->todos( "SELECT tipo, versao, aceito_em, revogado_em FROM {$this->p}consentimento WHERE conta_id = ? ORDER BY id", [ $conta_id ] ),
			'plataformas'    => $this->plataformas( $conta_id ),
			'marcacoes'      => $this->marcacoes( $conta_id ),
			'resenhas'       => $this->resenhasDaConta( $conta_id ),
		];
	}

	/** Apaga de verdade, em cascata (R6). Remover no provedor fica com quem chama. */
	public function apagarConta( int $conta_id ): void {
		$this->transacao( function () use ( $conta_id ) {
			$ids = array_column( $this->todos( "SELECT id FROM {$this->p}resenha WHERE conta_id = ?", [ $conta_id ] ), 'id' );
			foreach ( $ids as $rid ) {
				$this->exec( "DELETE FROM {$this->p}resenha_versao WHERE resenha_id = ?", [ $rid ] );
			}
			foreach ( [ 'resenha', 'marcacao', 'preferencias', 'consentimento' ] as $t ) {
				$this->exec( "DELETE FROM {$this->p}{$t} WHERE conta_id = ?", [ $conta_id ] );
			}
			$this->exec( "DELETE FROM {$this->p}conta WHERE id = ?", [ $conta_id ] );
		} );
	}

	/** @return array<array{id:int,email:string,acao:string}> contas que pedem aviso ou exclusao */
	public function contasParaRetencao(): array {
		$agora = $this->agora();
		$saida = [];
		foreach ( $this->todos( "SELECT id, email, ultimo_acesso_em, aviso_retencao_em FROM {$this->p}conta" ) as $c ) {
			$acao = dsi_perfil_retencao_acao( $c['ultimo_acesso_em'], $c['aviso_retencao_em'], $agora );
			if ( $acao !== 'nada' ) {
				$saida[] = [ 'id' => (int) $c['id'], 'email' => $c['email'], 'acao' => $acao ];
			}
		}
		return $saida;
	}

	public function marcarAvisoRetencao( int $conta_id ): void {
		$this->exec( "UPDATE {$this->p}conta SET aviso_retencao_em = ? WHERE id = ?", [ $this->agora(), $conta_id ] );
	}
}
