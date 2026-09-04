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

	// Captura todos os blocos <script type="application/ld+json">
	$pattern = '@<script\b[^>]*\btype=["\']application/ld\+json["\'][^>]*>(.*?)</script>@si';
	preg_match_all( $pattern, $html, $matches, PREG_SET_ORDER );

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

	// ── 4. Remover blocos originais do buffer do wp_head ────────────────
	// IMPORTANTE: ob_start/end aqui captura APENAS o output do wp_head,
	// não o HTML completo. O </head> está fora desse buffer (no header.php).
	// Portanto: removemos os blocos do buffer e emitimos os corrigidos
	// diretamente como parte do wp_head — eles aparecerão dentro do <head>.
	$clean = preg_replace( $pattern, '', $html );
	echo $clean;

	// ── 5. Emitir JSON-LD corrigido ──────────────────────────────────────
	foreach ( $output_nodes as $node ) {
		echo '<script type="application/ld+json">' . "\n"
			. wp_json_encode( $node, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT )
			. "\n</script>\n";
	}
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

	// contentUrl deve ser URL direta do arquivo de vídeo.
	// YouTube watch URLs não são arquivos — removê-las quando embedUrl já existe.
	if ( isset( $node['contentUrl'] ) && isset( $node['embedUrl'] ) ) {
		if ( preg_match( '@youtube\.com/watch@i', (string) $node['contentUrl'] ) ) {
			unset( $node['contentUrl'] );
		}
	}

	return $node;
}

/** NewsArticle: wordCount → int, remove mainEntity circular, desdup imagens */
function dsi_jfix_article( array $node ): array {
	if ( isset( $node['wordCount'] ) ) {
		$node['wordCount'] = (int) $node['wordCount'];
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
		if ( $profiles ) {
			$node['sameAs'] = array_values( array_unique( $profiles ) );
		}
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
