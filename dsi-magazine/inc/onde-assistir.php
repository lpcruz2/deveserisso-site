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
 * Parte pura (sem WordPress, testada em tests/OndeAssistirTest.php): normalizacao,
 * escolha do resultado do TMDB e texto da resposta. Parte WP no fim do arquivo.
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
		$data = (string) ( $r['release_date'] ?? ( $r['first_air_date'] ?? '' ) );
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
 */
function dsi_onde_assistir_texto( string $titulo, ?array $br ): string {
	if ( $br === null ) {
		return "Não consegui consultar agora onde \"{$titulo}\" está disponível. Tente de novo em alguns minutos.";
	}
	$linhas = [];
	$grupos = [
		'Assinatura'                => dsi_onde_assistir_nomes( $br['flatrate'] ?? [] ),
		'Grátis'                    => array_merge( dsi_onde_assistir_nomes( $br['free'] ?? [] ), dsi_onde_assistir_nomes( $br['ads'] ?? [] ) ),
		'Aluguel'                   => dsi_onde_assistir_nomes( $br['rent'] ?? [] ),
		'Compra'                    => dsi_onde_assistir_nomes( $br['buy'] ?? [] ),
	];
	foreach ( $grupos as $rotulo => $nomes ) {
		$nomes = array_values( array_unique( $nomes ) );
		if ( $nomes ) {
			$linhas[] = $rotulo . ': ' . implode( ', ', array_slice( $nomes, 0, 8 ) ) . '.';
		}
	}
	if ( ! $linhas ) {
		return "Não encontrei \"{$titulo}\" em nenhum serviço de streaming, aluguel ou compra no Brasil agora.\n\nA disponibilidade vem do JustWatch, via TMDB, e muda com frequência.";
	}
	return "Onde ver \"{$titulo}\" no Brasil:\n\n" . implode( "\n", $linhas ) . "\n\nDados do JustWatch, via TMDB. A disponibilidade muda com frequência.";
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
 * Se o modelo reconheceu "onde assistir" para um titulo da lista, troca o texto da resposta
 * pelo montado com dados do TMDB. Qualquer outra intencao passa sem mudanca.
 */
function dsi_onde_assistir_aplicar( array $resposta, array $itens ): array {
	if ( ( $resposta['intencao'] ?? '' ) !== 'onde_assistir' || empty( $resposta['titulo'] ) ) {
		return $resposta;
	}
	$item = $itens[ (int) $resposta['titulo'] - 1 ] ?? null;
	if ( ! $item ) {
		return $resposta;
	}
	$obra = dsi_onde_assistir_resolver( $item );
	$br   = $obra ? dsi_onde_assistir_provedores( $obra['id'], $obra['tipo'] ) : null;
	$resposta['resposta'] = dsi_onde_assistir_texto( (string) $item['titulo'], $br );
	return $resposta;
}
