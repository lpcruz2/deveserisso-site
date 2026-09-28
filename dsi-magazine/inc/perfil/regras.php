<?php
/**
 * Perfil de gosto -- regras puras (sem banco, sem WordPress).
 *
 * Especificacao: projeto WebMCP-deveserisso, docs/prd-perfil-de-gosto.md e
 * docs/erd-perfil-de-gosto.md. Premissa 4 do PRD: nada do perfil depende do
 * WordPress (o site pode sair dele), por isso este arquivo e o repositorio
 * usam so PHP puro. Titulos sao identificados por tmdb_id + tipo.
 */

const DSI_PERFIL_TIPOS       = [ 'filme', 'serie' ];
const DSI_PERFIL_VISTO       = [ 'quero_ver', 'ja_vi' ];
const DSI_PERFIL_AVALIACAO   = [ 'curti', 'nao_curti' ];
const DSI_PERFIL_CANAIS      = [ 'site', 'curador', 'mcp_claude', 'mcp_chatgpt' ];
const DSI_PERFIL_PLATAFORMAS = [ 'netflix', 'amazon-prime', 'globoplay', 'disney', 'telecine', 'appletv' ];
const DSI_PERFIL_MOTIVOS     = [ 'spoiler', 'ofensa', 'fora_do_tema', 'spam' ];
const DSI_PERFIL_TEXTO_MIN   = 100;
const DSI_PERFIL_TEXTO_MAX   = 3000;

// Rede -> hosts aceitos. Qualquer outro dominio e recusado no envio (spam e
// phishing). twitter.com vira "x"; threads mudou de .net pra .com em 2025.
const DSI_PERFIL_REDES = [
	'instagram'  => [ 'instagram.com' ],
	'x'          => [ 'x.com', 'twitter.com' ],
	'threads'    => [ 'threads.net', 'threads.com' ],
	'bluesky'    => [ 'bsky.app' ],
	'tiktok'     => [ 'tiktok.com' ],
	'youtube'    => [ 'youtube.com' ],
	'letterboxd' => [ 'letterboxd.com' ],
	'facebook'   => [ 'facebook.com' ],
];

// Primeiros segmentos de caminho que nao sao perfil (post, reel, busca...).
const DSI_PERFIL_CAMINHOS_RESERVADOS = [
	'p', 'reel', 'reels', 'explore', 'stories', 'tv', 'accounts', 'home', 'search', 'i', 'intent',
	'share', 'watch', 'shorts', 'results', 'feed', 'film', 'films', 'list', 'lists', 'settings',
	'login', 'hashtag', 'groups', 'events', 'pages', 'profile.php', 'sharer', 'tag', 'music',
];

/**
 * Normaliza um link de perfil em rede social.
 *
 * @return array{rede:string,handle:string,url:string}|null  null = recusado
 */
function dsi_perfil_rede_social( string $url ): ?array {
	$url = trim( $url );
	if ( $url === '' || strlen( $url ) > 300 ) {
		return null;
	}
	if ( ! preg_match( '#^https?://#i', $url ) ) {
		$url = 'https://' . $url;
	}
	$partes = parse_url( $url );
	if ( ! $partes || empty( $partes['host'] ) || ! in_array( strtolower( $partes['scheme'] ?? '' ), [ 'http', 'https' ], true ) ) {
		return null;
	}
	if ( isset( $partes['user'] ) || isset( $partes['pass'] ) || isset( $partes['port'] ) ) {
		return null;
	}
	$host = strtolower( $partes['host'] );
	$host = preg_replace( '/^(www|m|mobile|web)\./', '', $host );

	$rede = null;
	foreach ( DSI_PERFIL_REDES as $nome => $hosts ) {
		if ( in_array( $host, $hosts, true ) ) {
			$rede = $nome;
			break;
		}
	}
	if ( $rede === null ) {
		return null;
	}

	$segmentos = array_values( array_filter( explode( '/', $partes['path'] ?? '' ), 'strlen' ) );
	if ( ! $segmentos ) {
		return null;
	}
	$primeiro = $segmentos[0];

	switch ( $rede ) {
		case 'bluesky':
			// bsky.app/profile/<handle>, handle pode ter pontos (ana.bsky.social)
			if ( $primeiro !== 'profile' || empty( $segmentos[1] ) ) {
				return null;
			}
			$handle = $segmentos[1];
			$regex  = '/^[A-Za-z0-9][A-Za-z0-9.-]{0,252}$/';
			break;
		case 'threads':
		case 'tiktok':
			// threads.net/@ana, tiktok.com/@ana
			if ( $primeiro[0] !== '@' ) {
				return null;
			}
			$handle = substr( $primeiro, 1 );
			$regex  = '/^[A-Za-z0-9._]{1,60}$/';
			break;
		case 'youtube':
			// youtube.com/@canal, /c/canal ou /user/canal. /c/ e /user/ mantem o
			// caminho original: nem sempre sao o mesmo canal que /@canal.
			if ( $primeiro[0] === '@' ) {
				$handle = substr( $primeiro, 1 );
			} elseif ( in_array( $primeiro, [ 'c', 'user' ], true ) && ! empty( $segmentos[1] ) ) {
				$handle          = $segmentos[1];
				$caminho_youtube = '/' . $primeiro . '/' . $handle;
			} else {
				return null;
			}
			$regex = '/^[A-Za-z0-9._-]{1,60}$/';
			break;
		default:
			// instagram.com/ana, x.com/ana, letterboxd.com/ana, facebook.com/ana
			$handle = ltrim( $primeiro, '@' );
			if ( in_array( strtolower( $handle ), DSI_PERFIL_CAMINHOS_RESERVADOS, true ) ) {
				return null;
			}
			$regex = '/^[A-Za-z0-9._-]{1,60}$/';
	}

	if ( ! preg_match( $regex, $handle ) ) {
		return null;
	}

	$caminho = [
		'bluesky' => '/profile/' . $handle,
		'threads' => '/@' . $handle,
		'tiktok'  => '/@' . $handle,
		'youtube' => $caminho_youtube ?? '/@' . $handle,
	][ $rede ] ?? '/' . $handle;

	return [
		'rede'   => $rede,
		'handle' => '@' . $handle,
		'url'    => 'https://' . DSI_PERFIL_REDES[ $rede ][0] . $caminho,
	];
}

/** Minusculas sem acento, pra comparar palavras. */
function dsi_perfil_normalizar( string $s ): string {
	$s = mb_strtolower( $s, 'UTF-8' );
	return strtr( $s, [
		'á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a',
		'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
		'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
		'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o',
		'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
		'ç' => 'c', 'ñ' => 'n',
	] );
}

/**
 * Limpa e valida o texto de uma resenha.
 *
 * Texto puro: tags saem, links saem (sinal "link" pra fila), espacos e
 * quebras de linha sao normalizados. O filtro so MARCA sinais pra fila do
 * gestor; quem decide e sempre ele (PRD, R11).
 *
 * @return array{texto:string,erro:?string,sinais:string[]}
 */
function dsi_perfil_texto_resenha( string $texto ): array {
	$sinais = [];
	$texto  = str_replace( [ "\r\n", "\r", "\xC2\xA0" ], [ "\n", "\n", ' ' ], $texto );
	$texto  = strip_tags( $texto );
	$texto  = html_entity_decode( $texto, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	$texto  = strip_tags( $texto );

	// Endereco com protocolo ou www., ou dominio solto em minusculas. Sem a
	// trava (?![a-z0-9]), "gostei muito.Como" perderia "muito.Co".
	$sem_link = preg_replace( '~(?i:https?://|www\.)\S+|\b[a-z0-9-]+\.(?:com|net|org|io|app)(?:\.br)?(?![a-z0-9])(?:/\S*)?|\b[a-z0-9-]+\.com\.br(?![a-z0-9])(?:/\S*)?~u', '', $texto );
	if ( $sem_link !== $texto ) {
		$sinais[] = 'link';
		$texto    = $sem_link;
	}

	$texto = preg_replace( '/[ \t]+/u', ' ', $texto );
	$texto = preg_replace( '/ *\n */u', "\n", $texto );
	$texto = preg_replace( "/\n{3,}/u", "\n\n", $texto );
	$texto = trim( $texto );

	$tamanho = mb_strlen( $texto, 'UTF-8' );
	$erro    = null;
	if ( $tamanho < DSI_PERFIL_TEXTO_MIN ) {
		$erro = 'curto';
	} elseif ( $tamanho > DSI_PERFIL_TEXTO_MAX ) {
		$erro = 'longo';
	}

	if ( preg_match( '/\b(porra|caralho|merda|puta|putaria|foda|fodase|fdp|buceta|arrombad[oa]|desgracad[oa]|viad[oa]|vagabund[oa]|cuzao|otari[oa])\b/u', dsi_perfil_normalizar( $texto ) ) ) {
		$sinais[] = 'palavrao';
	}

	$letras = preg_replace( '/[^\p{L}]/u', '', $texto );
	if ( mb_strlen( $letras, 'UTF-8' ) >= 40 ) {
		$maiusculas = preg_replace( '/[^\p{Lu}]/u', '', $letras );
		if ( mb_strlen( $maiusculas, 'UTF-8' ) / mb_strlen( $letras, 'UTF-8' ) > 0.6 ) {
			$sinais[] = 'maiusculas';
		}
	}

	return [ 'texto' => $texto, 'erro' => $erro, 'sinais' => $sinais ];
}

/**
 * Valida uma marcacao (R3). Dois eixos independentes; null = desmarcado.
 *
 * @return array{tmdb_id:int,tipo:string,visto:?string,avaliacao:?string}|null
 */
function dsi_perfil_marcacao_valida( $tmdb_id, $tipo, $visto, $avaliacao ): ?array {
	$tmdb_id = filter_var( $tmdb_id, FILTER_VALIDATE_INT, [ 'options' => [ 'min_range' => 1 ] ] );
	if ( ! $tmdb_id || ! in_array( $tipo, DSI_PERFIL_TIPOS, true ) ) {
		return null;
	}
	$visto     = ( $visto === '' ) ? null : $visto;
	$avaliacao = ( $avaliacao === '' ) ? null : $avaliacao;
	if ( $visto !== null && ! in_array( $visto, DSI_PERFIL_VISTO, true ) ) {
		return null;
	}
	if ( $avaliacao !== null && ! in_array( $avaliacao, DSI_PERFIL_AVALIACAO, true ) ) {
		return null;
	}
	return [ 'tmdb_id' => (int) $tmdb_id, 'tipo' => $tipo, 'visto' => $visto, 'avaliacao' => $avaliacao ];
}

/** Plataformas validas, sem repeticao, na ordem recebida. */
function dsi_perfil_plataformas_validas( array $slugs ): array {
	$ok = [];
	foreach ( $slugs as $s ) {
		if ( is_string( $s ) && in_array( $s, DSI_PERFIL_PLATAFORMAS, true ) && ! in_array( $s, $ok, true ) ) {
			$ok[] = $s;
		}
	}
	return $ok;
}

/** Canal conhecido ou "site". */
function dsi_perfil_canal( $canal ): string {
	return in_array( $canal, DSI_PERFIL_CANAIS, true ) ? $canal : 'site';
}

/**
 * Resenha no ar = tem versao aprovada E o site tem critica do titulo (R12).
 * Sem critica, a aprovada espera; aparece sozinha quando a critica sair.
 */
function dsi_perfil_resenha_no_ar( ?int $versao_publicada_id, bool $critica_existe ): bool {
	return $versao_publicada_id !== null && $versao_publicada_id > 0 && $critica_existe;
}

/**
 * Escolhe o titulo do TMDB que corresponde a uma critica (etapa 1).
 *
 * Mesmo criterio validado nas 763 criticas em 2026-09-28: nome (titulo ou
 * original, sem acento e pontuacao) igual ao do resultado, e ano com
 * diferenca de ate 1 (ficha costuma ter o ano de producao; o TMDB, o da
 * estreia). So devolve o id quando sobra exatamente um candidato.
 *
 * @param array $resultados itens de /search/movie ou /search/tv
 * @return int[] ids candidatos (1 = resolvido; 0 ou 2+ = revisar)
 */
function dsi_perfil_tmdb_candidatos( array $resultados, string $titulo, string $original, int $ano ): array {
	$chave = fn( $s ) => trim( preg_replace( '/[^a-z0-9]+/', ' ', dsi_perfil_normalizar( (string) $s ) ) );
	$alvos = array_filter( array_unique( [ $chave( $titulo ), $chave( $original ) ] ) );
	$ids   = [];
	foreach ( $resultados as $r ) {
		$nomes = [ $chave( $r['title'] ?? $r['name'] ?? '' ), $chave( $r['original_title'] ?? $r['original_name'] ?? '' ) ];
		$r_ano = (int) substr( (string) ( $r['release_date'] ?? $r['first_air_date'] ?? '' ), 0, 4 );
		if ( array_intersect( $nomes, $alvos ) && ( ! $ano || ! $r_ano || abs( $r_ano - $ano ) <= 1 ) && ! empty( $r['id'] ) ) {
			$ids[] = (int) $r['id'];
		}
	}
	return array_values( array_unique( $ids ) );
}

/**
 * Retencao (R9): conta sem acesso por 24 meses e apagada, com aviso 30 dias
 * antes. So apaga se o aviso ja foi mandado ha pelo menos 30 dias.
 *
 * @return string 'nada' | 'avisar' | 'apagar'
 */
function dsi_perfil_retencao_acao( string $ultimo_acesso, ?string $aviso_em, string $agora ): string {
	$ultimo = new DateTimeImmutable( $ultimo_acesso, new DateTimeZone( 'UTC' ) );
	$hoje   = new DateTimeImmutable( $agora, new DateTimeZone( 'UTC' ) );
	$limite = $ultimo->modify( '+24 months' );
	$avisar = $limite->modify( '-30 days' );

	if ( $aviso_em !== null ) {
		$aviso = new DateTimeImmutable( $aviso_em, new DateTimeZone( 'UTC' ) );
		// Voltou a acessar depois do aviso: o aviso antigo nao vale mais.
		if ( $aviso < $ultimo ) {
			$aviso_em = null;
		} elseif ( $hoje >= $limite && $hoje >= $aviso->modify( '+30 days' ) ) {
			return 'apagar';
		} else {
			return 'nada';
		}
	}
	return $hoje >= $avisar ? 'avisar' : 'nada';
}
