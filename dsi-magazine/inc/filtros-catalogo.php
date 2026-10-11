<?php
/**
 * Filtros por dados do TMDB no Curador: protagonista feminina e faixa etaria (2026-10-11).
 *
 * Origem do problema (eval K14, conversa real na pagina de "O Advogado do Diabo"): a pessoa pediu "comedia com
 * protagonista mulher" e o Curador ignorou o pedido sem avisar, porque o catalogo nao tinha esse dado.
 * Agora a tabela {prefixo}dsi_tmdb_enriquecimento (carga de 2026-10-11, titulos com critica) guarda
 * o genero do primeiro nome do elenco, quantas mulheres ha entre os tres primeiros e a classificacao
 * indicativa do Brasil. O pedido e reconhecido por regra no texto da pessoa (sem depender do modelo)
 * e viaja no estado da conversa como `protagonista` e `idade`.
 *
 * Titulo sem dado confirmado NAO passa pelo filtro (para criancas, na duvida, fica de fora) e o aviso diz isso.
 * Parte pura testada em tests/FiltrosCatalogoTest.php; a parte WordPress fica no fim.
 */

const DSI_FILTROS_CLASSIFICACOES = [ 0, 10, 12, 14, 16, 18 ]; // 0 = livre

function dsi_filtros_normalizar( string $s ): string {
	$s = mb_strtolower( trim( $s ), 'UTF-8' );
	$s = strtr( $s, [
		'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
		'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i', 'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
		'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ç' => 'c', 'ñ' => 'n',
	] );
	return trim( preg_replace( '/[^a-z0-9?]+/', ' ', $s ) );
}

const DSI_FILTROS_PROTAGONISTA_REGEX = [
	'/\bprotagonista (mulher|mulheres|feminina|feminino)\b/',
	'/\b(mulher|mulheres|garota|menina) (como |de )?protagonista\b/',
	'/\bprotagonizad[oa]s? por (uma )?(mulher|mulheres)\b/',
	'/\bpersonagem principal (mulher|feminina)\b/',
	'/\bheroinas?\b/',
	'/\b(mulher|mulheres) (no|em) (o )?centro\b/',
	'/\bmulheres? (a frente|liderando)\b/',
];

const DSI_FILTROS_CRIANCA_TERMOS = '(?:crianca|criancas|filh[oa]s?|sobrinh[oa]s?|net[oa]s?|afilhad[oa]s?|menino|menina|pequen[oa]s?)';

/**
 * O que a pessoa pediu que da para filtrar: protagonista feminina e faixa etaria de quem vai assistir.
 *
 * @return array{protagonista:?string,idade:?int}
 */
function dsi_filtros_extrair_do_texto( string $mensagem ): array {
	$q   = dsi_filtros_normalizar( $mensagem );
	$res = [ 'protagonista' => null, 'idade' => null ];
	foreach ( DSI_FILTROS_PROTAGONISTA_REGEX as $padrao ) {
		if ( preg_match( $padrao, $q ) ) {
			$res['protagonista'] = 'feminina';
			break;
		}
	}
	$t = DSI_FILTROS_CRIANCA_TERMOS;
	if ( preg_match( '/\b' . $t . '\b[^?]{0,40}?\b(\d{1,2}) anos?\b/', $q, $m ) || preg_match( '/\b(\d{1,2}) anos?\b[^?]{0,20}\b' . $t . '\b/', $q, $m ) ) {
		$idade = (int) $m[1];
		if ( $idade >= 1 && $idade <= 17 ) {
			$res['idade'] = $idade;
		}
	} elseif ( preg_match( '/\b(crianca|criancas|infantil)\b/', $q ) ) {
		$res['idade'] = 10; // "para crianca" sem idade: livre e 10 anos
	}
	return $res;
}

// "Mas com protagonista mulher" depois da lista e um refinamento do pedido, nao uma pergunta sobre a lista.
function dsi_filtros_pede_refinar( string $mensagem ): bool {
	return strpos( $mensagem, '?' ) === false && dsi_filtros_extrair_do_texto( $mensagem )['protagonista'] !== null;
}

// Idade de quem vai assistir -> maior classificacao indicativa permitida (0 = livre).
function dsi_filtros_classificacao_maxima( int $idade ): int {
	$max = 0;
	foreach ( DSI_FILTROS_CLASSIFICACOES as $c ) {
		if ( $idade >= $c ) {
			$max = $c;
		}
	}
	return $max;
}

// Valores vindos da URL/estado -> filtros validos (vazio = nenhum filtro).
function dsi_filtros_do_pedido( $protagonista, $idade ): array {
	$f = [];
	if ( is_string( $protagonista ) && dsi_filtros_normalizar( $protagonista ) === 'feminina' ) {
		$f['protagonista'] = 'feminina';
	}
	if ( is_numeric( $idade ) && (int) $idade >= 1 && (int) $idade <= 17 ) {
		$f['idade'] = (int) $idade;
	}
	return $f;
}

/**
 * O titulo passa? $dados = linha da carga do TMDB (protagonista_g, mulheres_top3, cert_idade) ou null.
 * Sem dado, nao passa.
 */
function dsi_filtros_passa( ?array $dados, array $filtros ): bool {
	if ( ! $filtros ) {
		return true;
	}
	if ( $dados === null ) {
		return false;
	}
	if ( isset( $filtros['protagonista'] ) ) {
		$g = isset( $dados['protagonista_g'] ) ? (int) $dados['protagonista_g'] : -1;
		$m = isset( $dados['mulheres_top3'] ) ? (int) $dados['mulheres_top3'] : 0;
		if ( $g !== 1 && $m < 3 ) { // 1o nome do elenco feminino, ou os tres primeiros todos femininos
			return false;
		}
	}
	if ( isset( $filtros['idade'] ) ) {
		if ( ! isset( $dados['cert_idade'] ) || $dados['cert_idade'] === null || (int) $dados['cert_idade'] > dsi_filtros_classificacao_maxima( $filtros['idade'] ) ) {
			return false;
		}
	}
	return true;
}

function dsi_filtros_aviso( array $filtros, int $passaram ): string {
	$partes = [];
	if ( isset( $filtros['protagonista'] ) ) {
		$partes[] = 'protagonista mulher';
	}
	if ( isset( $filtros['idade'] ) ) {
		$max      = dsi_filtros_classificacao_maxima( $filtros['idade'] );
		$partes[] = $max === 0 ? 'classificação livre (para ' . $filtros['idade'] . ' anos)' : 'classificação até ' . $max . ' anos';
	}
	if ( ! $partes ) {
		return '';
	}
	$lista = implode( ' e ', $partes );
	return $passaram > 0
		? "Filtrei por {$lista}. Só entram títulos com esse dado confirmado, então a lista pode ser menor."
		: "Não achei título com {$lista} entre os que têm esse dado confirmado.";
}

// ------------------------------------------------------------------ parte WordPress

// "tmdb_id:tipo" => dados da carga do TMDB, em cache curto (a carga e rara).
function dsi_filtros_mapa_enriquecimento(): array {
	global $wpdb;
	$chave = 'dsi_filtros_enriq_v1_' . md5( $wpdb->prefix );
	$cache = get_transient( $chave );
	if ( is_array( $cache ) ) {
		return $cache;
	}
	$tabela = $wpdb->prefix . 'dsi_tmdb_enriquecimento';
	$wpdb->suppress_errors( true );
	$linhas = $wpdb->get_results( "SELECT tmdb_id, tipo, protagonista_g, mulheres_top3, cert_idade FROM {$tabela}", ARRAY_A );
	$wpdb->suppress_errors( false );
	$mapa = [];
	foreach ( (array) $linhas as $l ) {
		$mapa[ (int) $l['tmdb_id'] . ':' . $l['tipo'] ] = [
			'protagonista_g' => $l['protagonista_g'] !== null ? (int) $l['protagonista_g'] : null,
			'mulheres_top3'  => $l['mulheres_top3'] !== null ? (int) $l['mulheres_top3'] : null,
			'cert_idade'     => $l['cert_idade'] !== null ? (int) $l['cert_idade'] : null,
		];
	}
	set_transient( $chave, $mapa, 30 * MINUTE_IN_SECONDS );
	return $mapa;
}

/**
 * Aplica os filtros as duas listas de candidatos de /recomendar-filme (com critica e sem critica).
 * Critica: o TMDB vem do vinculo critica -> titulo. Sem critica: do tmdb_id do catalogo.
 *
 * @return array{0:array,1:array}
 */
function dsi_filtros_aplicar( array $filtros, array $candidatos, array $sem_resenha ): array {
	if ( ! $filtros ) {
		return [ $candidatos, $sem_resenha ];
	}
	$mapa    = dsi_filtros_mapa_enriquecimento();
	$vinculo = [];
	try {
		$vinculo = dsi_perfil_repo()->titulosDosPosts( array_map( fn( array $c ): int => (int) $c['post']->ID, $candidatos ) );
	} catch ( Throwable $e ) {
		$vinculo = [];
	}
	$candidatos = array_values( array_filter( $candidatos, function ( array $c ) use ( $filtros, $mapa, $vinculo ): bool {
		$v = $vinculo[ (int) $c['post']->ID ] ?? null;
		return dsi_filtros_passa( $v ? ( $mapa[ $v['tmdb_id'] . ':' . $v['tipo'] ] ?? null ) : null, $filtros );
	} ) );
	$sem_resenha = array_values( array_filter( $sem_resenha, function ( array $c ) use ( $filtros, $mapa ): bool {
		$tipo = ( $c['dados']['tipo'] ?? 'filme' ) === 'serie' ? 'serie' : 'filme';
		return dsi_filtros_passa( ! empty( $c['tmdb_id'] ) ? ( $mapa[ (int) $c['tmdb_id'] . ':' . $tipo ] ?? null ) : null, $filtros );
	} ) );
	return [ $candidatos, $sem_resenha ];
}
