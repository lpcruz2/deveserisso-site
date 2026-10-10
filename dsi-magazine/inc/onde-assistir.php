<?php
/**
 * "Onde assistir" no Curador (2026-10-10, pedido do gestor).
 *
 * A pergunta "tem onde assistir?" depois da lista ficava sem resposta: o modelo so recebe
 * titulo, sinopse, elenco e nota, e o prompt manda nao inventar. Agora o modelo so
 * reconhece a intencao (`onde_assistir` + numero do titulo) e o SERVIDOR monta a resposta
 * com os dados do TMDB (watch/providers, Brasil, fornecidos pelo JustWatch). O modelo
 * nunca escreve nome de plataforma.
 *
 * Foco da conversa: o servidor nao guarda historico. Quando a resposta e sobre um titulo, devolve
 * `titulo_foco`; o widget manda de volta no proximo turno, e "e da pra alugar?" sem nome de titulo
 * continua falando do mesmo filme.
 *
 * Parte pura (sem WordPress, testada em tests/OndeAssistirTest.php): normalizacao, deteccao da
 * pergunta, escolha do titulo, escolha do resultado do TMDB e texto da resposta. Parte WP no fim.
 */

if ( ! defined( 'DSI_ONDE_ASSISTIR_CACHE_S' ) ) {
	define( 'DSI_ONDE_ASSISTIR_CACHE_S', 12 * 3600 );
	define( 'DSI_ONDE_ASSISTIR_CACHE_ERRO_S', 600 );
}

// Minusculas, sem acento, so letras e numeros separados por espaco.
function dsi_onde_assistir_normalizar( string $s ): string {
	$s = mb_strtolower( trim( $s ), 'UTF-8' );
	$s = strtr( $s, [
		'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
		'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
		'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c', 'ñ' => 'n',
	] );
	return trim( preg_replace( '/[^a-z0-9]+/', ' ', $s ) );
}

/**
 * A pergunta e sobre onde ver / alugar / comprar? So palavras claras: com falso positivo
 * o Curador responderia disponibilidade a quem perguntou outra coisa.
 */
function dsi_onde_assistir_pergunta_pede( string $pergunta ): bool {
	$q = dsi_onde_assistir_normalizar( $pergunta );
	return (bool) preg_match( '/\b(onde (?:(?:da|posso|tem|esta|fica|consigo)(?: pra| para)? )?(?:assist|ver\b|vej|passa|encontr)|em que (streaming|plataforma|servico)|qual (streaming|plataforma|servico)|streaming|plataforma|alugar|aluguel|comprar|compra|sem assinar|sem assinatura|de graca|gratis)/', $q );
}

// "Sem assinar", "alugar", "comprar": a resposta foca nessas opcoes.
function dsi_onde_assistir_sem_assinatura( string $pergunta ): bool {
	$q = dsi_onde_assistir_normalizar( $pergunta );
	return (bool) preg_match( '/\b(alugar|aluguel|comprar|compra|sem assinar|sem assinatura|de graca|gratis)/', $q );
}

/**
 * Qual titulo da lista a pergunta quer: o citado na pergunta (o mais longo, em palavra inteira)
 * ou, sem citacao, o que estava em foco na conversa. Devolve o numero (1...) ou null.
 */
function dsi_onde_assistir_achar_item( array $itens, string $pergunta, string $foco = '' ): ?int {
	$q       = ' ' . dsi_onde_assistir_normalizar( $pergunta ) . ' ';
	$melhor  = null;
	$tamanho = 0;
	foreach ( $itens as $i => $item ) {
		$t = dsi_onde_assistir_normalizar( (string) ( $item['titulo'] ?? '' ) );
		if ( $t !== '' && strpos( $q, ' ' . $t . ' ' ) !== false && strlen( $t ) > $tamanho ) {
			$melhor  = $i + 1;
			$tamanho = strlen( $t );
		}
	}
	if ( $melhor !== null ) {
		return $melhor;
	}
	$f = dsi_onde_assistir_normalizar( $foco );
	if ( $f !== '' ) {
		foreach ( $itens as $i => $item ) {
			if ( dsi_onde_assistir_normalizar( (string) ( $item['titulo'] ?? '' ) ) === $f ) {
				return $i + 1;
			}
		}
	}
	return null;
}

/**
 * Escolhe, entre os resultados de /search/multi, a obra do titulo citado: filme ou serie,
 * titulo (pt ou original) igual e ano de lancamento igual. Sem ano, so aceita se houver
 * uma unica obra com esse titulo. Melhor nao responder do que responder da obra errada.
 *
 * @return array{id:int,tipo:string}|null tipo = 'filme'|'serie'
 */
function dsi_onde_assistir_escolher( array $resultados, string $titulo, int $ano ): ?array {
	$alvo       = dsi_onde_assistir_normalizar( $titulo );
	$candidatos = [];
	foreach ( $resultados as $r ) {
		$media = $r['media_type'] ?? '';
		if ( ! is_array( $r ) || ( $media !== 'movie' && $media !== 'tv' ) || empty( $r['id'] ) ) {
			continue;
		}
		$nomes = array_filter( [ $r['title'] ?? '', $r['original_title'] ?? '', $r['name'] ?? '', $r['original_name'] ?? '' ] );
		$bate  = false;
		foreach ( $nomes as $n ) {
			if ( dsi_onde_assistir_normalizar( (string) $n ) === $alvo ) {
				$bate = true;
				break;
			}
		}
		if ( ! $bate ) {
			continue;
		}
		$data  = (string) ( $r['release_date'] ?? ( $r['first_air_date'] ?? '' ) );
		$ano_r = (int) substr( $data, 0, 4 );
		if ( $ano > 0 && $ano_r !== $ano ) {
			continue;
		}
		$candidatos[] = [ 'id' => (int) $r['id'], 'tipo' => $media === 'tv' ? 'serie' : 'filme', 'pop' => (float) ( $r['popularity'] ?? 0 ) ];
	}
	if ( ! $candidatos || ( $ano <= 0 && count( $candidatos ) > 1 ) ) {
		return null;
	}
	usort( $candidatos, fn( $a, $b ) => $b['pop'] <=> $a['pop'] );
	return [ 'id' => $candidatos[0]['id'], 'tipo' => $candidatos[0]['tipo'] ];
}

// Nomes de uma lista de provedores do TMDB, sem repetir, na ordem de prioridade da API.
function dsi_onde_assistir_nomes( $lista ): array {
	$nomes = [];
	if ( is_array( $lista ) ) {
		foreach ( $lista as $p ) {
			$n = is_array( $p ) ? trim( (string) ( $p['provider_name'] ?? '' ) ) : '';
			if ( $n !== '' && ! in_array( $n, $nomes, true ) ) {
				$nomes[] = $n;
			}
		}
	}
	return $nomes;
}

/**
 * Texto da resposta. $br: bloco "BR" do watch/providers; [] = sem disponibilidade
 * registrada; null = a consulta falhou (resposta honesta, sem inventar).
 * $sem_assinatura: a pessoa quer alugar, comprar ou ver de graca; a assinatura sai da lista.
 */
function dsi_onde_assistir_texto( string $titulo, ?array $br, bool $sem_assinatura = false ): string {
	if ( $br === null ) {
		return "Não consegui consultar agora onde \"{$titulo}\" está disponível. Tente de novo em alguns minutos.";
	}
	$rodape     = "Dados do JustWatch, via TMDB. A disponibilidade muda com frequência.";
	$assinatura = array_values( array_unique( dsi_onde_assistir_nomes( $br['flatrate'] ?? [] ) ) );
	$grupos     = [
		'Assinatura' => $assinatura,
		'Grátis'     => array_merge( dsi_onde_assistir_nomes( $br['free'] ?? [] ), dsi_onde_assistir_nomes( $br['ads'] ?? [] ) ),
		'Aluguel'    => dsi_onde_assistir_nomes( $br['rent'] ?? [] ),
		'Compra'     => dsi_onde_assistir_nomes( $br['buy'] ?? [] ),
	];
	if ( $sem_assinatura ) {
		unset( $grupos['Assinatura'] );
	}
	$linhas = [];
	foreach ( $grupos as $rotulo => $nomes ) {
		$nomes = array_values( array_unique( $nomes ) );
		if ( $nomes ) {
			$linhas[] = $rotulo . ': ' . implode( ', ', array_slice( $nomes, 0, 8 ) ) . '.';
		}
	}
	if ( ! $linhas && $sem_assinatura && $assinatura ) {
		return "Não achei \"{$titulo}\" para alugar, comprar ou ver de graça no Brasil agora. Ele está só em assinatura: " . implode( ', ', array_slice( $assinatura, 0, 8 ) ) . ".\n\n" . $rodape;
	}
	if ( ! $linhas ) {
		return "Não encontrei \"{$titulo}\" em nenhum serviço de streaming, aluguel ou compra no Brasil agora.\n\nA disponibilidade vem do JustWatch, via TMDB, e muda com frequência.";
	}
	$abertura = $sem_assinatura ? "Para ver \"{$titulo}\" sem assinar, no Brasil:" : "Onde ver \"{$titulo}\" no Brasil:";
	return $abertura . "\n\n" . implode( "\n", $linhas ) . "\n\n" . $rodape;
}

// ------------------------------------------------------------------ parte WordPress

function dsi_onde_assistir_tmdb( string $caminho, array $params = [] ): ?array {
	$chave = defined( 'FILMBOX_TMDB_KEY' ) ? FILMBOX_TMDB_KEY : '';
	if ( $chave === '' ) {
		return null;
	}
	$resp = wp_remote_get( add_query_arg( array_merge( $params, [ 'api_key' => $chave ] ), 'https://api.themoviedb.org/3/' . $caminho ), [ 'timeout' => 8 ] );
	if ( is_wp_error( $resp ) || (int) wp_remote_retrieve_response_code( $resp ) !== 200 ) {
		return null;
	}
	$corpo = json_decode( wp_remote_retrieve_body( $resp ), true );
	return is_array( $corpo ) ? $corpo : null;
}

// Obra do item: vinculo da critica com o TMDB, ou busca por titulo + ano.
function dsi_onde_assistir_resolver( array $item ): ?array {
	if ( ! empty( $item['tem_critica'] ) && ! empty( $item['link'] ) && function_exists( 'dsi_perfil_repo' ) ) {
		$post_id = (int) url_to_postid( $item['link'] );
		if ( $post_id > 0 ) {
			try {
				$l = dsi_perfil_repo()->tituloDoPost( $post_id );
				if ( $l && (int) $l['tmdb_id'] > 0 ) {
					return [ 'id' => (int) $l['tmdb_id'], 'tipo' => $l['tipo'] === 'serie' ? 'serie' : 'filme' ];
				}
			} catch ( Throwable $e ) {
				// segue para a busca por titulo
			}
		}
	}
	$titulo = trim( (string) ( $item['titulo'] ?? '' ) );
	if ( $titulo === '' ) {
		return null;
	}
	$ano   = (int) ( $item['ano_lancamento'] ?? 0 );
	$chave = 'dsi_onde_assistir_obra_v1_' . md5( dsi_onde_assistir_normalizar( $titulo ) . '|' . $ano );
	$cache = get_transient( $chave );
	if ( is_array( $cache ) ) {
		return $cache ?: null;
	}
	$busca = dsi_onde_assistir_tmdb( 'search/multi', [ 'query' => $titulo, 'language' => 'pt-BR' ] );
	if ( $busca === null ) {
		return null; // falha de rede: nao guarda, tenta de novo na proxima
	}
	$obra = dsi_onde_assistir_escolher( (array) ( $busca['results'] ?? [] ), $titulo, $ano );
	set_transient( $chave, $obra ?? [], 30 * DAY_IN_SECONDS );
	return $obra;
}

// Bloco BR do watch/providers, com cache. null = falha na consulta (cache curto).
function dsi_onde_assistir_provedores( int $id, string $tipo ): ?array {
	$chave = 'dsi_onde_assistir_v1_' . $tipo . '_' . $id;
	$cache = get_transient( $chave );
	if ( is_array( $cache ) ) {
		return $cache['erro'] ? null : $cache['br'];
	}
	$dados = dsi_onde_assistir_tmdb( ( $tipo === 'serie' ? 'tv/' : 'movie/' ) . $id . '/watch/providers' );
	if ( $dados === null ) {
		set_transient( $chave, [ 'erro' => true, 'br' => [] ], DSI_ONDE_ASSISTIR_CACHE_ERRO_S );
		return null;
	}
	$br = (array) ( $dados['results']['BR'] ?? [] );
	set_transient( $chave, [ 'erro' => false, 'br' => $br ], DSI_ONDE_ASSISTIR_CACHE_S );
	return $br;
}

/**
 * "Onde assistir" e foco da conversa. Se o modelo reconheceu a intencao para um titulo da lista,
 * ou a pergunta e claramente sobre onde ver e da para saber o titulo (citado na pergunta ou o que
 * estava em foco), troca o texto pela resposta montada com dados do TMDB. Sempre que a resposta e
 * sobre um titulo, devolve `titulo_foco` para o widget mandar de volta no proximo turno.
 */
function dsi_onde_assistir_aplicar( array $resposta, array $itens, string $pergunta = '', string $foco = '' ): array {
	$numero = ! empty( $resposta['titulo'] ) ? (int) $resposta['titulo'] : 0;
	if ( ( $resposta['intencao'] ?? '' ) !== 'onde_assistir' && dsi_onde_assistir_pergunta_pede( $pergunta ) ) {
		$achado = dsi_onde_assistir_achar_item( $itens, $pergunta, $foco );
		if ( $achado !== null ) {
			$resposta['intencao'] = 'onde_assistir';
			$numero               = $achado;
		}
	}
	$item = $numero > 0 ? ( $itens[ $numero - 1 ] ?? null ) : null;
	if ( ! $item ) {
		return $resposta;
	}
	$resposta['titulo']      = $numero;
	$resposta['titulo_foco'] = (string) $item['titulo'];
	if ( ( $resposta['intencao'] ?? '' ) !== 'onde_assistir' ) {
		return $resposta;
	}
	$obra = dsi_onde_assistir_resolver( $item );
	$br   = $obra ? dsi_onde_assistir_provedores( $obra['id'], $obra['tipo'] ) : null;
	$resposta['resposta'] = dsi_onde_assistir_texto( (string) $item['titulo'], $br, dsi_onde_assistir_sem_assinatura( $pergunta ) );
	return $resposta;
}
