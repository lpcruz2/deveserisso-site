<?php
/**
 * Plugin Name:  DSI JSON-LD Fixer
 * Description:  Intercepta, corrige e normaliza todos os blocos JSON-LD da página.
 *               Substitui dsi-jsonld-dedup.php.
 * Version:      1.0.0
 * Author:       Deveserisso
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_head', 'dsi_jfix_start', 0 );
add_action( 'wp_head', 'dsi_jfix_end',   PHP_INT_MAX );

// =============================================================================
// CAPTURA E PROCESSAMENTO
// =============================================================================

function dsi_jfix_start(): void {
	ob_start();
}

function dsi_jfix_end(): void {
	$html = ob_get_clean();

	// Captura todos os blocos <script type="application/ld+json"> (sem regex no bloco inteiro:
	// o ItemList de "melhores animes" passa de 1 MB e estourava o PCRE, saindo sem correção).
	$spans   = dsi_jfix_find_blocks( $html );
	$matches = array_map( fn( $b ) => [ substr( $html, $b[0], $b[1] - $b[0] ), substr( $html, $b[2], $b[3] - $b[2] ) ], $spans );

	if ( empty( $matches ) ) {
		echo $html;
		return;
	}

	// ── 1. Parsear e achatar todos os nós ────────────────────────────────
	$all_nodes = [];
	foreach ( $matches as $m ) {
		$json = trim( $m[1] );
		$data = json_decode( $json, true );
		if ( ! is_array( $data ) ) {
			continue;
		}

		$ctx = $data['@context'] ?? 'https://schema.org';

		if ( isset( $data['@graph'] ) && is_array( $data['@graph'] ) ) {
			// Formato: { "@context": "...", "@graph": [...] }
			foreach ( $data['@graph'] as $node ) {
				if ( is_array( $node ) && ! isset( $node['@context'] ) ) {
					$node['@context'] = $ctx;
				}
				$all_nodes[] = $node;
			}
		} elseif ( array_key_exists( 0, $data ) ) {
			// Formato: array JSON de nós [ {...}, {...}, {...} ]
			// Alguns elementos podem ser @graph wrappers — expandir recursivamente.
			foreach ( $data as $node ) {
				if ( ! is_array( $node ) ) {
					continue;
				}
				if ( isset( $node['@graph'] ) && is_array( $node['@graph'] ) ) {
					// Elemento é um @graph wrapper — expandir seus filhos
					$nested_ctx = $node['@context'] ?? $ctx;
					foreach ( $node['@graph'] as $subnode ) {
						if ( is_array( $subnode ) && ! isset( $subnode['@context'] ) ) {
							$subnode['@context'] = $nested_ctx;
						}
						$all_nodes[] = $subnode;
					}
				} else {
					if ( ! isset( $node['@context'] ) ) {
						$node['@context'] = $ctx;
					}
					$all_nodes[] = $node;
				}
			}
		} else {
			// Formato: nó único { "@type": "...", ... }
			$all_nodes[] = $data;
		}
	}

	// ── 1b. Trailer de YouTube/Vimeo: o SASWP publica como VideoObject da página
	// (nome/data/transcript do post), o que descreve errado o conteúdo. Vira
	// Movie/TVSeries.trailer quando há ficha; senão, sai.
	$all_nodes = dsi_jfix_move_trailers( $all_nodes );

	// ── 2. Pré-scan: detectar tipos presentes ────────────────────────────
	$has_news_article = false;
	$has_real_faq     = false;
	foreach ( $all_nodes as $node ) {
		$type = $node['@type'] ?? '';
		if ( $type === 'NewsArticle' ) {
			$has_news_article = true;
		}
		if ( $type === 'FAQPage' && ! dsi_jfix_is_generic_faq( $node ) ) {
			$has_real_faq = true;
		}
	}

	// ── 3. Filtrar e corrigir ────────────────────────────────────────────
	$output_nodes = [];
	$seen_ids     = [];
	$seen_faq     = false;
	$nav_id_count = [];

	foreach ( $all_nodes as $node ) {
		$type = $node['@type'] ?? '';

		// Descartar Article genérico quando já existe NewsArticle
		if ( $type === 'Article' && $has_news_article ) {
			continue;
		}

		// Deduplicar FAQPage — manter apenas a primeira real
		if ( $type === 'FAQPage' ) {
			if ( $seen_faq ) {
				continue;
			}
			if ( $has_real_faq && dsi_jfix_is_generic_faq( $node ) ) {
				continue;
			}
			$seen_faq = true;
		}

		// Limpar nulls e strings vazias
		$node = dsi_jfix_clean_nulls( $node );

		// Correções por tipo
		switch ( $type ) {
			case 'VideoObject':
				$node = dsi_jfix_video( $node );
				break;
			case 'Article':
			case 'NewsArticle':
			case 'BlogPosting':
				$node = dsi_jfix_article( $node );
				break;
			case 'SiteNavigationElement':
				$node = dsi_jfix_nav_dedup( $node, $nav_id_count );
				break;
			case 'FAQPage':
				$node = dsi_jfix_faqpage( $node );
				break;
			case 'ItemList':
				$node = dsi_jfix_itemlist( $node );
				break;
			case 'Organization':
				$node = dsi_jfix_organization( $node );
				break;
			case 'WebSite':
				$node['name']       = 'Deveserisso';
				$node['inLanguage'] = 'pt-BR';
				break;
		}

		// Correções transversais
		$node = dsi_jfix_person( $node, 'author' );
		$node = dsi_jfix_person( $node, 'editor' );
		$node = dsi_jfix_publisher_url( $node );
		$node = dsi_jfix_imageobjects( $node );
		$node = dsi_jfix_comments( $node );

		// Normalizar @context
		if ( isset( $node['@context'] ) ) {
			$node['@context'] = 'https://schema.org';
		}

		// Deduplicar por @id
		if ( isset( $node['@id'] ) ) {
			if ( isset( $seen_ids[ $node['@id'] ] ) ) {
				continue;
			}
			$seen_ids[ $node['@id'] ] = true;
		}

		$output_nodes[] = $node;
	}

	// Página "Sobre": declara que ela trata da Organization e liga ao WebSite.
	if ( is_page( 'sobre' ) ) {
		$output_nodes[] = [
			'@context'   => 'https://schema.org',
			'@type'      => 'AboutPage',
			'@id'        => get_permalink() . '#aboutpage',
			'url'        => get_permalink(),
			'name'       => 'Sobre o Deveserisso',
			'inLanguage' => 'pt-BR',
			'about'      => [ '@id' => 'https://deveserisso.com.br#Organization' ],
			'isPartOf'   => [ '@id' => 'https://deveserisso.com.br#website' ],
		];
	}

	// ── 4. Remover blocos originais do buffer do wp_head ────────────────
	// IMPORTANTE: ob_start/end aqui captura APENAS o output do wp_head,
	// não o HTML completo. O </head> está fora desse buffer (no header.php).
	// Portanto: removemos os blocos do buffer e emitimos os corrigidos
	// diretamente como parte do wp_head — eles aparecerão dentro do <head>.
	$clean = '';
	$pos   = 0;
	foreach ( $spans as $b ) {
		$clean .= substr( $html, $pos, $b[0] - $pos );
		$pos    = $b[1];
	}
	$clean .= substr( $html, $pos );
	echo $clean;

	// ── 5. Emitir JSON-LD corrigido ──────────────────────────────────────
	foreach ( $output_nodes as $node ) {
		echo '<script type="application/ld+json">' . "\n"
			. wp_json_encode( $node, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT )
			. "\n</script>\n";
	}
}

/**
 * Localiza os <script type="application/ld+json"> por busca de texto.
 * Devolve [ início do bloco, fim do bloco, início do JSON, fim do JSON ] de cada um.
 */
function dsi_jfix_find_blocks( string $html ): array {
	$spans = [];
	$pos   = 0;
	while ( ( $a = stripos( $html, '<script', $pos ) ) !== false ) {
		$gt = strpos( $html, '>', $a );
		if ( $gt === false ) {
			break;
		}
		$end = stripos( $html, '</script>', $gt );
		if ( $end === false ) {
			break;
		}
		$tag = substr( $html, $a, $gt - $a + 1 );
		if ( preg_match( '@\btype=["\']application/ld\+json["\']@i', $tag ) ) {
			$spans[] = [ $a, $end + 9, $gt + 1, $end ];
		}
		$pos = $end + 9;
	}
	return $spans;
}

// =============================================================================
// FUNÇÕES DE CORREÇÃO
// =============================================================================

/** Remove null e strings vazias recursivamente */
function dsi_jfix_clean_nulls( $data ) {
	if ( ! is_array( $data ) ) {
		return $data;
	}
	$out = [];
	foreach ( $data as $k => $v ) {
		if ( $v === null || $v === '' ) {
			continue;
		}
		$out[ $k ] = is_array( $v ) ? dsi_jfix_clean_nulls( $v ) : $v;
	}
	return $out;
}

/** VideoObject de embed de terceiro (YouTube/Vimeo) -- trailer, não vídeo produzido pelo site. */
function dsi_jfix_is_third_party_video( array $node ): bool {
	$embed = (string) ( $node['embedUrl'] ?? '' );
	return (bool) preg_match( '@(youtube\.com|youtube-nocookie\.com|youtu\.be|vimeo\.com)/@i', $embed );
}

/** Reduz o VideoObject ao que é verdade sobre o trailer (sem data/autor/transcript do post). */
function dsi_jfix_trailer_from_video( array $video, string $titulo ): array {
	$trailer = [
		'@type'       => 'VideoObject',
		'name'        => $titulo !== '' ? 'Trailer: ' . $titulo : 'Trailer',
		'embedUrl'    => $video['embedUrl'],
		'description' => $titulo !== '' ? 'Trailer oficial de ' . $titulo . '.' : 'Trailer oficial.',
	];
	// uploadDate é obrigatório no Google (Vídeos). Usa o do nó original; sem ele, a data do post (aproximação).
	$upload = (string) ( $video['uploadDate'] ?? '' );
	if ( $upload === '' && function_exists( 'get_the_date' ) ) {
		$upload = (string) get_the_date( 'c' );
	}
	if ( $upload !== '' ) {
		$trailer['uploadDate'] = $upload;
	}
	if ( ! empty( $video['thumbnailUrl'] ) ) {
		$trailer['thumbnailUrl'] = $video['thumbnailUrl'];
	}
	return $trailer;
}

/** Tira o trailer do nível de página; aninha em Movie/TVSeries.trailer quando existe e ainda não tem. */
function dsi_jfix_move_trailers( array $all_nodes ): array {
	$movie_idx = null;
	foreach ( $all_nodes as $i => $node ) {
		if ( in_array( $node['@type'] ?? '', [ 'Movie', 'TVSeries' ], true ) ) {
			$movie_idx = $i;
			break;
		}
	}
	foreach ( $all_nodes as $i => $node ) {
		if ( ( $node['@type'] ?? '' ) !== 'VideoObject' || ! dsi_jfix_is_third_party_video( $node ) ) {
			continue;
		}
		if ( $movie_idx !== null && empty( $all_nodes[ $movie_idx ]['trailer'] ) ) {
			$all_nodes[ $movie_idx ]['trailer'] = dsi_jfix_trailer_from_video( $node, (string) ( $all_nodes[ $movie_idx ]['name'] ?? '' ) );
		}
		unset( $all_nodes[ $i ] );
	}
	return array_values( $all_nodes );
}

/** Data ISO 8601 com fuso (usa o do site quando falta); '' se não for data válida. */
function dsi_jfix_iso_date( string $v ): string {
	$v = trim( $v );
	if ( $v === '' ) {
		return '';
	}
	try {
		$d = new DateTimeImmutable( $v, wp_timezone() );
	} catch ( Exception $e ) {
		return '';
	}
	return $d->format( 'c' );
}

/** VideoObject: remove headline inválido, corrige embedUrl, remove contentUrl de watch */
function dsi_jfix_video( array $node ): array {
	unset( $node['headline'] );

	if ( isset( $node['embedUrl'] ) ) {
		$node['embedUrl'] = preg_replace(
			'@youtube\.com/watch\?v=([a-zA-Z0-9_-]+)@i',
			'youtube.com/embed/$1',
			$node['embedUrl']
		);
	}

	// uploadDate precisa ser ISO 8601 com fuso; só data ("2024-09-01") ou valor inválido falha no Google.
	if ( isset( $node['uploadDate'] ) ) {
		$up = dsi_jfix_iso_date( (string) $node['uploadDate'] );
		if ( $up === '' ) {
			unset( $node['uploadDate'] );
		} else {
			$node['uploadDate'] = $up;
		}
	}

	// Sem embedUrl, a URL de watch do YouTube vira embedUrl (contentUrl é só para arquivo de vídeo).
	if ( ! isset( $node['embedUrl'] ) && isset( $node['contentUrl'] )
		&& preg_match( '@youtube\.com/watch\?v=([a-zA-Z0-9_-]+)@i', (string) $node['contentUrl'], $yt ) ) {
		$node['embedUrl'] = 'https://www.youtube.com/embed/' . $yt[1];
		unset( $node['contentUrl'] );
	}

	// contentUrl deve ser URL direta do arquivo de vídeo.
	// YouTube watch URLs não são arquivos — removê-las quando embedUrl já existe.
	if ( isset( $node['contentUrl'] ) && isset( $node['embedUrl'] ) ) {
		if ( preg_match( '@youtube\.com/watch@i', (string) $node['contentUrl'] ) ) {
			unset( $node['contentUrl'] );
		}
	}

	return $node;
}

/**
 * NewsArticle: wordCount → int, remove mainEntity circular, desdup imagens,
 * limpa lixo de UI do plugin FilmBox que escapa pro articleBody.
 *
 * O SASWP (plugin que gera esse node) lê $post->post_content bruto pra
 * montar articleBody, sem passar pelo filtro 'the_content' que o FilmBox usa
 * (filmbox.php) pra esconder seus botões de editor ("🔄 Regenerar dados",
 * "✏️ Editar configurações") da página visível — por isso o texto desses
 * botões, que nunca aparece pro visitante, ainda vazava pro schema.
 */
function dsi_jfix_article( array $node ): array {
	if ( isset( $node['wordCount'] ) ) {
		$node['wordCount'] = (int) $node['wordCount'];
	}

	if ( isset( $node['articleBody'] ) && is_string( $node['articleBody'] ) ) {
		$node['articleBody'] = dsi_jfix_clean_article_body( $node['articleBody'] );
	}

	// mainEntity apontando para a própria página é circular e inútil
	if ( isset( $node['mainEntity']['@type'] ) && $node['mainEntity']['@type'] === 'WebPage' ) {
		unset( $node['mainEntity'] );
	}

	// Desduplicar imagens pelo URL
	if ( isset( $node['image'] ) && is_array( $node['image'] ) ) {
		$seen_urls  = [];
		$node['image'] = array_values( array_filter(
			$node['image'],
			function ( $img ) use ( &$seen_urls ) {
				$url = is_array( $img ) ? ( $img['url'] ?? '' ) : (string) $img;
				if ( isset( $seen_urls[ $url ] ) ) {
					return false;
				}
				$seen_urls[ $url ] = true;
				return true;
			}
		) );
	}

	return $node;
}

/** Remove strings de botões do editor do FilmBox e normaliza espaços sobrando */
function dsi_jfix_clean_article_body( string $body ): string {
	$junk = [ '🔄 Regenerar dados', '✏️ Editar configurações' ];
	$body = str_replace( $junk, '', $body );
	return trim( preg_replace( '/\s+/', ' ', $body ) );
}

/** Person: filtrar sameAs que não são URLs válidas e normalizar /blog */
function dsi_jfix_person( array $node, string $field ): array {
	if ( ! isset( $node[ $field ] ) || ! is_array( $node[ $field ] ) ) {
		return $node;
	}
	$p = $node[ $field ];

	if ( isset( $p['sameAs'] ) && is_array( $p['sameAs'] ) ) {
		// 1. Remover não-URLs
		$p['sameAs'] = array_filter(
			$p['sameAs'],
			fn( $s ) => is_string( $s ) && (bool) filter_var( $s, FILTER_VALIDATE_URL )
		);

		// 2. Remover entradas que apontam para /blog (caminho WP, não perfil)
		$p['sameAs'] = array_filter(
			$p['sameAs'],
			fn( $s ) => ! preg_match( '@/blog/?$@i', (string) $s )
		);

		$p['sameAs'] = array_values( $p['sameAs'] );

		if ( empty( $p['sameAs'] ) ) {
			unset( $p['sameAs'] );
		}
	}

	$node[ $field ] = $p;
	return $node;
}

/**
 * Organization: completa description (faltava), corrige url que apontava
 * pra /blog (subdiretório WP, nunca deveria ter sido a URL canônica) e
 * preenche sameAs com os perfis reais já cadastrados no Yoast
 * (Ajustes SEO -> Social) -- o plugin que gera esse node (Schema and
 * Structured Data for WP) não lê essas configurações do Yoast sozinho,
 * por isso o sameAs saía sempre vazio mesmo com os perfis reais existindo.
 */
function dsi_jfix_organization( array $node ): array {
	// Nome da entidade = nome do site (igual ao rótulo do item no Wikidata); o slogan fica na description.
	$node['name']         = 'Deveserisso';
	$node['foundingDate'] = '2009';
	$node['founder']      = [
		'@type' => 'Person',
		'name'  => 'Leonardo Cruz',
		'url'   => home_url( '/author/lpcruz2/' ),
	];

	if ( empty( $node['description'] ) ) {
		$node['description'] = 'Portal brasileiro de cultura pop e entretenimento — resenhas de filmes e séries, guias de streaming e cobertura de premiações.';
	}

	if ( isset( $node['url'] ) && preg_match( '@/blog/?$@', (string) $node['url'] ) ) {
		$node['url'] = home_url( '/' );
	}

	if ( empty( $node['sameAs'] ) ) {
		$social  = get_option( 'wpseo_social' );
		$profiles = [];
		foreach ( [ 'facebook_site', 'instagram_url', 'linkedin_url', 'youtube_url', 'pinterest_url' ] as $key ) {
			if ( ! empty( $social[ $key ] ) ) {
				$profiles[] = $social[ $key ];
			}
		}
		if ( ! empty( $social['twitter_site'] ) ) {
			$profiles[] = 'https://x.com/' . ltrim( $social['twitter_site'], '@' );
		}
		// "Outros perfis" do Yoast (ex.: entidade no Wikidata); as chaves fixas acima não os cobrem.
		if ( ! empty( $social['other_social_urls'] ) && is_array( $social['other_social_urls'] ) ) {
			foreach ( $social['other_social_urls'] as $url ) {
				if ( is_string( $url ) && filter_var( $url, FILTER_VALIDATE_URL ) ) {
					$profiles[] = $url;
				}
			}
		}
		if ( $profiles ) {
			$node['sameAs'] = array_values( array_unique( $profiles ) );
		}
	}

	if ( empty( $node['address'] ) ) {
		$node['address'] = [
			'@type'           => 'PostalAddress',
			'streetAddress'   => 'Rua Diana, 921 - Perdizes',
			'addressLocality' => 'São Paulo',
			'addressRegion'   => 'SP',
			'addressCountry'  => 'BR',
		];
	}

	if ( empty( $node['contactPoint'] ) ) {
		$node['contactPoint'] = [
			'@type'       => 'ContactPoint',
			'email'       => 'contato@deveserisso.com.br',
			'contactType' => 'customer support',
		];
	}

	return $node;
}

/** Publisher: URL não deve apontar para /blog (subdiretório WP) */
function dsi_jfix_publisher_url( array $node ): array {
	if ( ! isset( $node['publisher']['url'] ) ) {
		return $node;
	}
	if ( preg_match( '@/blog/?$@', $node['publisher']['url'] ) ) {
		$node['publisher']['url'] = home_url( '/' );
	}
	return $node;
}

/** ImageObject: width e height devem ser inteiros */
function dsi_jfix_imageobjects( array $node ): array {
	foreach ( [ 'image', 'logo', 'thumbnail' ] as $field ) {
		if ( isset( $node[ $field ] ) ) {
			$node[ $field ] = dsi_jfix_cast_image( $node[ $field ] );
		}
	}
	return $node;
}

function dsi_jfix_cast_image( $img ) {
	if ( is_string( $img ) ) {
		return $img;
	}
	if ( is_array( $img ) && isset( $img[0] ) ) {
		return array_map( 'dsi_jfix_cast_image', $img );
	}
	if ( is_array( $img ) ) {
		if ( isset( $img['width'] ) )  $img['width']  = (int) $img['width'];
		if ( isset( $img['height'] ) ) $img['height'] = (int) $img['height'];
	}
	return $img;
}

/** SiteNavigationElement: tornar @id único quando houver duplicatas */
function dsi_jfix_nav_dedup( array $node, array &$count ): array {
	if ( ! isset( $node['@id'] ) ) {
		return $node;
	}
	$id = $node['@id'];
	if ( ! isset( $count[ $id ] ) ) {
		$count[ $id ] = 0;
		return $node;
	}
	$count[ $id ]++;
	$node['@id'] = $id . '-' . $count[ $id ];
	return $node;
}

/** FAQPage: deduplicar perguntas pelo nome (case-insensitive, whitespace-normalizado) */
function dsi_jfix_faqpage( array $node ): array {
	if ( ! isset( $node['mainEntity'] ) || ! is_array( $node['mainEntity'] ) ) {
		return $node;
	}
	$seen = [];
	$node['mainEntity'] = array_values( array_filter(
		$node['mainEntity'],
		function ( $q ) use ( &$seen ) {
			if ( ! is_array( $q ) ) {
				return false;
			}
			$key = preg_replace( '/\s+/', ' ', strtolower( trim( $q['name'] ?? '' ) ) );
			if ( $key === '' || isset( $seen[ $key ] ) ) {
				return false;
			}
			$seen[ $key ] = true;
			return true;
		}
	) );
	return $node;
}

/** ItemList: corrigir VideoObjects aninhados (embedUrl, contentUrl, sameAs, interactionStatistic) */
function dsi_jfix_itemlist( array $node ): array {
	if ( ! isset( $node['itemListElement'] ) || ! is_array( $node['itemListElement'] ) ) {
		return $node;
	}
	$node['itemListElement'] = array_map( function ( $item ) {
		if ( ! is_array( $item ) ) {
			return $item;
		}
		// Limpar nulls aninhados
		$item = dsi_jfix_clean_nulls( $item );

		// Corrigir VideoObject dentro do ItemList
		if ( ( $item['@type'] ?? '' ) === 'VideoObject' ) {
			$item = dsi_jfix_video( $item );
			$item = dsi_jfix_person( $item, 'author' );
			$item = dsi_jfix_imageobjects( $item );
		}

		// userInteractionCount deve ser inteiro
		if ( isset( $item['interactionStatistic']['userInteractionCount'] ) ) {
			$item['interactionStatistic']['userInteractionCount'] =
				(int) $item['interactionStatistic']['userInteractionCount'];
		}

		return $item;
	}, $node['itemListElement'] );
	return $node;
}

/** Comment: renomeia "id" → "@id" dentro do array comment do nó */
function dsi_jfix_comments( array $node ): array {
	if ( ! isset( $node['comment'] ) || ! is_array( $node['comment'] ) ) {
		return $node;
	}
	$node['comment'] = array_map( function ( $c ) {
		if ( ! is_array( $c ) ) {
			return $c;
		}
		// SASWP gera "id" sem o @; corrigir para "@id"
		if ( isset( $c['id'] ) && ! isset( $c['@id'] ) ) {
			$c['@id'] = $c['id'];
			unset( $c['id'] );
		}
		return $c;
	}, $node['comment'] );
	return $node;
}

/** Detecta FAQPage com respostas genéricas/placeholder do SASWP */
function dsi_jfix_is_generic_faq( array $node ): bool {
	$entities = $node['mainEntity'] ?? [];
	if ( ! is_array( $entities ) || empty( $entities ) ) {
		return false;
	}
	$generic = 0;
	foreach ( $entities as $q ) {
		$answer = strtolower( $q['acceptedAnswer']['text'] ?? '' );
		if ( str_contains( $answer, 'confira o guia completo' ) ) {
			$generic++;
		}
	}
	return $generic > ( count( $entities ) / 2 );
}
