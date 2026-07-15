<?php
/**
 * DSI Magazine — functions.php
 * Child theme de cream-magazine. Substitui TODA a apresentação visual.
 */

defined( 'ABSPATH' ) || exit;

// =============================================================================
// 1. SETUP DO TEMA
// =============================================================================
add_action( 'after_setup_theme', 'dsi_setup' );
function dsi_setup(): void {
	// Herda menus do parent; registra posições usadas pelos templates.
	register_nav_menus( [
		'primary' => __( 'Menu Principal', 'dsi-magazine' ),
		'footer'  => __( 'Menu Rodapé', 'dsi-magazine' ),
	] );

	add_theme_support( 'title-tag' );
	add_theme_support( 'post-thumbnails' );
	add_theme_support( 'html5', [ 'search-form', 'comment-form', 'gallery', 'caption', 'style', 'script' ] );
	add_theme_support( 'align-wide' );
	add_theme_support( 'responsive-embeds' );

	// Tamanhos de imagem do design
	add_image_size( 'dsi-hero',    1920, 1080, true );  // frontispício
	add_image_size( 'dsi-poster',  900,  1200, true );  // pôster 3/4
	add_image_size( 'dsi-wide',    1200, 750,  true );  // 16/10 article lead
	add_image_size( 'dsi-square',  600,  600,  true );  // 1/1 card
	add_image_size( 'dsi-4x3',     800,  600,  true );  // tricolumn
	add_image_size( 'dsi-thumb',   120,  120,  true );  // list thumb
	add_image_size( 'dsi-author',  720,  900,  true );  // retrato autor 4/5
}

// =============================================================================
// 2. ESTILOS — desregistra pai, enfileira filho
// =============================================================================
add_action( 'wp_enqueue_scripts', 'dsi_enqueue', 20 );
function dsi_enqueue(): void {
	// Remove todos os estilos do parent cream-magazine
	wp_dequeue_style( 'cream-magazine-style' );
	wp_dequeue_style( 'cream-magazine-main' );

	// Também remove qualquer stylesheet combinado pelo LiteSpeed que venha do parent
	// (o MU plugin dsi-css-async.php cuida do async; aqui só garantimos que não carregamos o parent CSS)

	// CSS principal — fontes locais declaradas inline no critical CSS (sem Google Fonts externo)
	wp_enqueue_style(
		'dsi-main',
		get_stylesheet_directory_uri() . '/assets/css/main.css',
		[],
		filemtime( get_stylesheet_directory() . '/assets/css/main.css' )
	);
}

// =============================================================================
// 3. SCRIPTS
// =============================================================================
add_action( 'wp_enqueue_scripts', 'dsi_enqueue_scripts', 20 );
function dsi_enqueue_scripts(): void {
	// Mobile nav toggle — em todas as páginas
	wp_enqueue_script(
		'dsi-masthead',
		get_stylesheet_directory_uri() . '/assets/js/masthead.js',
		[],
		'1.0.0',
		[ 'strategy' => 'defer', 'in_footer' => true ]
	);

	// TOC com IntersectionObserver — só em singulares
	if ( is_singular() ) {
		wp_enqueue_script(
			'dsi-post-toc',
			get_stylesheet_directory_uri() . '/assets/js/post-toc.js',
			[],
			'1.0.0',
			[ 'strategy' => 'defer', 'in_footer' => true ]
		);
	}
}

// =============================================================================
// 4. PRELOAD das fontes locais (substituem Google Fonts)
//    — dispara download antes mesmo do CSS ser processado
// =============================================================================
add_action( 'wp_head', 'dsi_font_preload', 1 );
function dsi_font_preload(): void {
	$base = get_stylesheet_directory_uri() . '/assets/fonts/';
	$fonts = [
		'dm-serif-normal.woff2',
		'dm-serif-italic.woff2',
		'manrope.woff2',
		'jetbrains-mono.woff2',
	];
	foreach ( $fonts as $f ) {
		echo '<link rel="preload" href="' . esc_url( $base . $f ) . '" as="font" type="font/woff2" crossorigin>' . "\n";
	}
}

// =============================================================================
// 4b. CRITICAL CSS inline — evita FOUC quando o CSS principal é carregado async
//     Contém apenas o mínimo para o primeiro render:
//     reset base · body · masthead · grid · fonte fallback
// =============================================================================
add_action( 'wp_head', 'dsi_critical_css', 2 );
function dsi_critical_css(): void {
	$fonts = get_stylesheet_directory_uri() . '/assets/fonts/';
	?>
<style id="dsi-critical">
/* === @font-face locais — sem Google Fonts externo === */
@font-face{font-family:'DM Serif Display';font-style:normal;font-weight:400;font-display:swap;src:url('<?php echo esc_url($fonts); ?>dm-serif-normal.woff2') format('woff2')}
@font-face{font-family:'DM Serif Display';font-style:italic;font-weight:400;font-display:swap;src:url('<?php echo esc_url($fonts); ?>dm-serif-italic.woff2') format('woff2')}
@font-face{font-family:'Manrope';font-style:normal;font-weight:300 700;font-display:swap;src:url('<?php echo esc_url($fonts); ?>manrope.woff2') format('woff2')}
@font-face{font-family:'JetBrains Mono';font-style:normal;font-weight:300 500;font-display:swap;src:url('<?php echo esc_url($fonts); ?>jetbrains-mono.woff2') format('woff2')}
/* === Reset base === */
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html,body{background:#f4eee2}
body{color:#1d1a14;font-family:"Manrope",system-ui,sans-serif;font-size:14px;line-height:1.55;overflow-x:hidden}
a{color:inherit;text-decoration:none}
img,svg,video{display:block;max-width:100%}
/* === Container === */
.dsi-container{width:100%;max-width:1440px;margin-inline:auto;padding-inline:64px}
@media(max-width:768px){.dsi-container{padding-inline:20px}}
/* === Masthead — todas as variantes === */
.dsi-masthead{background:#f4eee2;color:#1d1a14;width:100%;position:relative}
.dsi-masthead__topbar{display:flex;justify-content:space-between;align-items:center;padding:20px 64px;border-bottom:1px solid #1d1a14;font-family:"JetBrains Mono",monospace;font-size:11px;letter-spacing:.18em;text-transform:uppercase}
@media(max-width:768px){.dsi-masthead__topbar{padding-inline:20px}}
.dsi-masthead__brand{padding:44px 64px 36px;text-align:center;border-bottom:3px double #1d1a14}
.dsi-masthead__brand--compact{padding:28px 64px 24px;border-bottom:1px solid #1d1a14}
@media(max-width:768px){.dsi-masthead__brand,.dsi-masthead__brand--compact{padding:24px 20px 20px}}
.dsi-masthead__wordmark{display:block;font-family:"DM Serif Display","Times New Roman",serif;font-size:clamp(64px,9.7vw,140px);line-height:.9;letter-spacing:-0.04em;color:#1d1a14;font-weight:400}
.dsi-masthead__brand--compact .dsi-masthead__wordmark{font-size:clamp(40px,4.4vw,64px);letter-spacing:-0.03em}
.dsi-masthead__wordmark em{font-style:italic;color:#c2511d}
.dsi-masthead__subtitle{font-family:"DM Serif Display","Times New Roman",serif;font-style:italic;font-size:22px;color:#6a5f4d;margin-top:12px}
.dsi-masthead__established{font-size:0}.dsi-masthead__established::before{content:"Desde 2018";font-size:11px;font-family:"JetBrains Mono",monospace;letter-spacing:.35em;text-transform:uppercase;color:#6a5f4d}
.dsi-masthead__nav{display:flex;align-items:center;padding:16px 64px;border-bottom:1px solid #1d1a14;gap:36px}
@media(max-width:768px){.dsi-masthead__nav{display:none}}
.dsi-masthead__nav-list{display:flex;align-items:center;gap:36px}
.dsi-masthead__nav-link,.dsi-masthead__nav-list .menu-item>a{font-family:"Manrope",system-ui,sans-serif;font-size:14px;font-weight:500;color:#1d1a14}
.dsi-masthead__search-toggle,.dsi-masthead__search-wrap{margin-left:auto}
.dsi-masthead__burger{display:none;flex-direction:column;gap:5px;background:none;border:none;cursor:pointer;padding:10px;position:absolute;top:20px;right:20px}
.dsi-masthead--home .dsi-masthead__burger{top:14px}
.dsi-masthead__burger span{display:block;width:22px;height:1px;background:#1d1a14}
@media(max-width:768px){.dsi-masthead__burger{display:flex}}
/* === Hero (home) — acima da dobra === */
.dsi-hero{padding:72px 64px 56px;display:grid;grid-template-columns:1.1fr 1fr;gap:56px;align-items:start}
@media(max-width:768px){.dsi-hero{padding:40px 20px 36px;grid-template-columns:1fr;gap:32px}}
.dsi-hero__title{font-family:"DM Serif Display","Times New Roman",serif;font-size:clamp(56px,7.6vw,110px);line-height:.92;letter-spacing:-0.03em;font-weight:400;color:#1d1a14}
.dsi-hero__excerpt{font-family:"Manrope",system-ui,sans-serif;font-size:19px;line-height:1.65;color:#1d1a14;margin-bottom:36px}
.dsi-hero__poster-frame{aspect-ratio:4/3;border:1px solid #1d1a14;padding:14px;background:#f4eee2;overflow:hidden}
.dsi-hero__img{width:100%;height:100%;object-fit:cover}
/* === Category header — acima da dobra === */
.dsi-cat-header{padding:80px 64px 56px;border-bottom:3px double #1d1a14}
@media(max-width:768px){.dsi-cat-header{padding:48px 20px 36px}}
.dsi-cat-header__title{font-family:"DM Serif Display","Times New Roman",serif;font-size:clamp(64px,11.7vw,168px);line-height:.88;letter-spacing:-0.04em;font-weight:400;color:#1d1a14}
/* === Single header — acima da dobra === */
.dsi-single__title{font-family:"DM Serif Display","Times New Roman",serif;font-size:clamp(36px,5vw,72px);line-height:1.02;letter-spacing:-0.03em;font-weight:400;color:#1d1a14}
.dsi-single__top--has-image{display:grid;grid-template-columns:1.1fr 1fr;gap:56px;padding:64px 64px 56px}
@media(max-width:768px){.dsi-single__top--has-image{grid-template-columns:1fr;padding:40px 20px}}
/* === Skip link === */
.dsi-skip-link{position:absolute;top:-50px;left:0;z-index:9999}
/* === Rule === */
.dsi-rule{flex:1;height:1px;background:#1d1a14}
</style>
	<?php
}

// =============================================================================
// 5. HELPER — número da edição (baseado na semana do ano)
// =============================================================================
function dsi_edicao(): string {
	// Edição 1 = semana 1 de 2018. Calcula delta em semanas desde 2018-01-01.
	$inicio = new DateTime( '2018-01-01' );
	$hoje   = new DateTime();
	$diff   = $inicio->diff( $hoje );
	$semana = (int) floor( $diff->days / 7 ) + 1;
	return (string) $semana;
}

// =============================================================================
// 6. HELPER — data por extenso em PT-BR sem depender de locale do servidor
// =============================================================================
function dsi_data_extenso( ?int $post_id = null ): string {
	$ts = $post_id ? get_post_timestamp( $post_id ) : current_time( 'timestamp' );
	$dias   = [ 'Domingo', 'Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado' ];
	$meses  = [ '', 'janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro' ];
	$semana = $dias[ (int) date( 'w', $ts ) ];
	$dia    = date( 'j', $ts );
	$mes    = $meses[ (int) date( 'n', $ts ) ];
	$ano    = date( 'Y', $ts );
	return "$semana, $dia de $mes de $ano";
}

// =============================================================================
// 7. HELPER — tempo de leitura estimado
// =============================================================================
function dsi_read_time( ?int $post_id = null ): string {
	$content = get_post_field( 'post_content', $post_id ?? get_the_ID() );
	$words   = str_word_count( wp_strip_all_tags( $content ) );
	$minutes = (int) ceil( $words / 200 );
	return $minutes . ' min';
}

// =============================================================================
// 8. HELPER — excerpt com tamanho fixo
// =============================================================================
function dsi_excerpt( int $length = 120, ?int $post_id = null ): string {
	$text = get_the_excerpt( $post_id );
	if ( ! $text ) {
		$text = wp_strip_all_tags( get_post_field( 'post_content', $post_id ?? get_the_ID() ) );
	}
	if ( mb_strlen( $text ) > $length ) {
		$text = mb_substr( $text, 0, $length ) . '…';
	}
	return esc_html( $text );
}

// =============================================================================
// 9. HELPER — iniciais do autor (para avatar fallback)
// =============================================================================
function dsi_author_initials( ?int $user_id = null ): string {
	$name = $user_id
		? get_the_author_meta( 'display_name', $user_id )
		: get_the_author_meta( 'display_name' );
	$parts = explode( ' ', trim( $name ) );
	$init  = '';
	foreach ( $parts as $p ) {
		$init .= mb_strtoupper( mb_substr( $p, 0, 1 ) );
		if ( mb_strlen( $init ) >= 2 ) break;
	}
	return $init ?: 'XX';
}

// =============================================================================
// 10. HELPER — categoria principal do post
// =============================================================================
function dsi_primary_category( ?int $post_id = null ): string {
	$id   = $post_id ?? get_the_ID();
	$cats = get_the_category( $id );
	if ( empty( $cats ) ) return '';
	// Yoast primary category
	$yoast = get_post_meta( $id, '_yoast_wpseo_primary_category', true );
	if ( $yoast ) {
		foreach ( $cats as $c ) {
			if ( $c->term_id == $yoast ) return esc_html( $c->name );
		}
	}
	return esc_html( $cats[0]->name );
}

function dsi_primary_category_url( ?int $post_id = null ): string {
	$id   = $post_id ?? get_the_ID();
	$cats = get_the_category( $id );
	if ( empty( $cats ) ) return home_url( '/' );
	$yoast = get_post_meta( $id, '_yoast_wpseo_primary_category', true );
	if ( $yoast ) {
		foreach ( $cats as $c ) {
			if ( $c->term_id == $yoast ) return get_category_link( $c->term_id );
		}
	}
	return get_category_link( $cats[0]->term_id );
}

// =============================================================================
// 11. HIGHLIGHT de termos de busca (server-side, retorna HTML com <mark>)
// =============================================================================
function dsi_highlight_search( string $text ): string {
	if ( ! is_search() ) return $text;
	$query = get_search_query();
	if ( ! $query ) return $text;
	$terms = array_filter( array_map( 'trim', explode( ' ', $query ) ) );
	foreach ( $terms as $term ) {
		$safe  = preg_quote( esc_html( $term ), '/' );
		$text  = preg_replace( '/(' . $safe . ')/iu', '<mark>$1</mark>', $text );
	}
	return $text;
}

// =============================================================================
// 12. PAGINAÇÃO — helper para usar em todos os archives
// =============================================================================
function dsi_pagination( array $args = [] ): void {
	$defaults = [
		'prev_text' => '← Página anterior',
		'next_text' => 'Próxima página →',
		'mid_size'  => 3,
		'type'      => 'array',
	];
	$links = paginate_links( array_merge( $defaults, $args, [ 'type' => 'array' ] ) );
	if ( ! $links ) return;
	$current  = max( 1, get_query_var( 'paged' ) );
	$total    = isset( $args['total'] ) ? $args['total'] : $GLOBALS['wp_query']->max_num_pages;
	?>
	<nav class="dsi-pagination" aria-label="Paginação">
		<span class="dsi-pagination__info"><?php printf( 'Página %d de %d', $current, $total ); ?></span>
		<div class="dsi-pagination__pages">
			<?php echo implode( '', $links ); ?>
		</div>
		<a href="<?php echo next_posts( $total, false ); ?>" class="dsi-pagination__next">Próxima página →</a>
	</nav>
	<?php
}

// =============================================================================
// 13. REMOVE widgets e meta do parent que não precisamos
// =============================================================================
add_action( 'init', 'dsi_cleanup' );
function dsi_cleanup(): void {
	remove_action( 'wp_head', 'wp_generator' );
	remove_action( 'wp_head', 'rsd_link' );
	remove_action( 'wp_head', 'wlwmanifest_link' );
	remove_action( 'wp_head', 'wp_shortlink_wp_head' );
}

// =============================================================================
// 14. BODY CLASSES adicionais para styling condicional
// =============================================================================
add_filter( 'body_class', 'dsi_body_classes' );
function dsi_body_classes( array $classes ): array {
	if ( is_home() || is_front_page() ) $classes[] = 'dsi-is-home';
	if ( is_singular() )               $classes[] = 'dsi-is-single';
	if ( is_search() )                 $classes[] = 'dsi-is-search';
	if ( is_author() )                 $classes[] = 'dsi-is-author';
	if ( is_category() || is_tag() )   $classes[] = 'dsi-is-archive';
	return $classes;
}

// =============================================================================
// 15. PURGE automático do LiteSpeed Cache — dispara uma vez por versão
// =============================================================================
if ( ! function_exists( 'dsi_once_purge_litespeed' ) ) {
	add_action( 'admin_init', 'dsi_once_purge_litespeed' );
	function dsi_once_purge_litespeed(): void {
		if ( get_option( 'dsi_litespeed_purged_v2' ) ) return;
		if ( class_exists( '\LiteSpeed\Purge' ) ) {
			\LiteSpeed\Purge::purge_all();
		}
		if ( function_exists( 'opcache_reset' ) ) {
			opcache_reset();
		}
		update_option( 'dsi_litespeed_purged_v1', true );
		update_option( 'dsi_litespeed_purged_v2', true );
	}
}

// =============================================================================
// 16. ADMIN — página DSI Conteúdo
// =============================================================================
add_action( 'wp_head', function (): void {
	echo '<!-- DSI-S16-LOADED -->';
} );
add_action( 'admin_notices', function (): void {
	echo '<div class="notice notice-warning"><p>DSI S16 ativo</p></div>';
} );

add_action( 'admin_menu', function (): void {
	add_menu_page(
		'DSI Conteúdo',
		'DSI Conteúdo',
		'manage_options',
		'dsi-conteudo',
		function (): void {
			if ( ! current_user_can( 'manage_options' ) ) return;

			$pq_text  = get_option( 'dsi_pull_quote_text',  '"Cinema, para mim, sempre foi a forma mais honesta de mentir."' );
			$pq_attr  = get_option( 'dsi_pull_quote_attr',  '— Citação editorial · Deveserisso' );
			$pq_image = get_option( 'dsi_pull_quote_image', '' );
			$tv_title = get_option( 'dsi_tv_title', 'Programação de hoje' );
			$slots    = get_option( 'dsi_tv_slots', [
				[ 'time' => '13h00', 'genre' => 'Sessão da Tarde',         'title' => 'Velocidade Máxima',   'link' => '', 'image' => '' ],
				[ 'time' => '15h30', 'genre' => 'Vale a Pena Ver de Novo', 'title' => 'Pega Pega',           'link' => '', 'image' => '' ],
				[ 'time' => '22h25', 'genre' => 'Tela Quente',             'title' => 'John Wick 4',         'link' => '', 'image' => '' ],
				[ 'time' => '01h10', 'genre' => 'Corujão I',               'title' => 'O Advogado do Diabo', 'link' => '', 'image' => '' ],
				[ 'time' => '03h05', 'genre' => 'Corujão II',              'title' => 'Constantine',         'link' => '', 'image' => '' ],
			] );
			while ( count( $slots ) < 5 ) {
				$slots[] = [ 'time' => '', 'genre' => '', 'title' => '', 'link' => '', 'image' => '' ];
			}
			// Compat: migrar campos legados 'show' → 'genre'
			foreach ( $slots as &$s ) {
				if ( ! isset( $s['genre'] ) ) $s['genre'] = $s['show'] ?? '';
				if ( ! isset( $s['link'] )  ) $s['link']  = '';
				if ( ! isset( $s['image'] ) ) $s['image'] = '';
			}
			unset( $s );
			?>
			<div class="wrap">
				<h1>DSI Conteúdo</h1>
				<?php if ( isset( $_GET['saved'] ) ) : ?>
					<div class="notice notice-success is-dismissible"><p>Salvo com sucesso.</p></div>
				<?php endif; ?>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="dsi_save_conteudo">
					<?php wp_nonce_field( 'dsi_conteudo_nonce', 'dsi_nonce' ); ?>

					<h2 style="border-top:1px solid #ddd;padding-top:20px;margin-top:30px">Programação de hoje</h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="dsi_tv_title">Título da seção</label></th>
							<td>
								<input type="text" id="dsi_tv_title" name="dsi_tv_title"
									value="<?php echo esc_attr( $tv_title ); ?>"
									class="regular-text" placeholder="Programação de hoje">
								<p class="description">Exibido como título da seção de TV na home, categorias e busca.</p>
							</td>
						</tr>
					</table>

					<h2 style="border-top:1px solid #ddd;padding-top:20px;margin-top:30px">Frase da semana</h2>
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="dsi_pull_quote_image">Foto (URL)</label></th>
							<td>
								<input type="url" id="dsi_pull_quote_image" name="dsi_pull_quote_image"
									value="<?php echo esc_attr( $pq_image ); ?>"
									class="regular-text" placeholder="https://…">
								<p class="description">URL da foto da pessoa citada (opcional). Proporção sugerida: retrato.</p>
								<?php if ( $pq_image ) : ?>
									<br><img src="<?php echo esc_url( $pq_image ); ?>" style="max-height:120px;margin-top:8px;border-radius:4px;" alt="">
								<?php endif; ?>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="dsi_pull_quote_text">Frase</label></th>
							<td>
								<textarea id="dsi_pull_quote_text" name="dsi_pull_quote_text"
									rows="3" class="large-text"><?php echo esc_textarea( $pq_text ); ?></textarea>
								<p class="description">Não precisa incluir as aspas — elas são adicionadas pelo template.</p>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="dsi_pull_quote_attr">Atribuição</label></th>
							<td>
								<input type="text" id="dsi_pull_quote_attr" name="dsi_pull_quote_attr"
									value="<?php echo esc_attr( $pq_attr ); ?>"
									class="regular-text" placeholder="— Nome · Contexto">
								<p class="description">Ex.: <code>— Stanley Kubrick · diretor</code></p>
							</td>
						</tr>
					</table>

					<h2 style="border-top:1px solid #ddd;padding-top:20px;margin-top:30px">Programação de hoje</h2>
					<p class="description" style="margin-bottom:16px">Até 5 filmes. Deixe em branco as linhas que não usar.</p>

					<?php for ( $i = 0; $i < 5; $i++ ) : $s = $slots[ $i ]; ?>
					<div style="border:1px solid #ddd;border-radius:4px;padding:16px;margin-bottom:12px;background:#fafafa">
						<strong style="font-size:13px;color:#444">Filme <?php echo $i + 1; ?></strong>
						<table class="form-table" role="presentation" style="margin-top:8px">
							<tr>
								<th style="width:140px"><label>Horário</label></th>
								<td><input type="text" name="tv_time_<?php echo $i; ?>"
									value="<?php echo esc_attr( $s['time'] ); ?>"
									class="small-text" placeholder="22h25"></td>
							</tr>
							<tr>
								<th><label>Gênero / Categoria</label></th>
								<td><input type="text" name="tv_genre_<?php echo $i; ?>"
									value="<?php echo esc_attr( $s['genre'] ); ?>"
									class="regular-text" placeholder="Ação, Comédia, Terror…"></td>
							</tr>
							<tr>
								<th><label>Nome do filme</label></th>
								<td><input type="text" name="tv_title_<?php echo $i; ?>"
									value="<?php echo esc_attr( $s['title'] ); ?>"
									class="regular-text" placeholder="Nome do filme"></td>
							</tr>
							<tr>
								<th><label>Link da matéria</label></th>
								<td>
									<input type="url" name="tv_link_<?php echo $i; ?>"
										value="<?php echo esc_attr( $s['link'] ); ?>"
										class="regular-text" placeholder="https://deveserisso.com.br/…">
									<p class="description">O nome do filme vira link clicável no site.</p>
								</td>
							</tr>
							<tr>
								<th><label>Cartaz (URL da imagem)</label></th>
								<td>
									<input type="url" name="tv_image_<?php echo $i; ?>"
										value="<?php echo esc_attr( $s['image'] ); ?>"
										class="regular-text" placeholder="https://…/cartaz.jpg"
										id="tv_image_<?php echo $i; ?>">
									<p class="description">Cartaz do filme. Será exibido com no máximo 80px de altura.</p>
									<?php if ( $s['image'] ) : ?>
										<br><img src="<?php echo esc_url( $s['image'] ); ?>"
											style="max-height:80px;max-width:60px;margin-top:8px;border-radius:2px;object-fit:cover;" alt="">
									<?php endif; ?>
								</td>
							</tr>
						</table>
					</div>
					<?php endfor; ?>

					<p class="submit" style="margin-top:24px">
						<?php submit_button( 'Salvar alterações', 'primary', 'submit', false ); ?>
					</p>
				</form>
			</div>
			<?php
		},
		'dashicons-edit-page',
		30
	);
} );

// =============================================================================
// 17. HELPER DE NAVEGAÇÃO — auto-detecta menu cadastrado
// =============================================================================
/**
 * Retorna args para wp_nav_menu() priorizando a location 'primary'.
 * Se nenhum menu estiver atribuído à location, usa automaticamente o primeiro
 * menu cadastrado no WP Admin (sem exigir configuração extra).
 * Retorna null se não há nenhum menu — aí o template usa o fallback hardcoded.
 *
 * @param string $menu_class Classe CSS do <ul> gerado.
 * @return array|null
 */
function dsi_nav_menu_args( string $menu_class ): ?array {
	$base = [
		'container'   => false,
		'menu_class'  => $menu_class,
		'depth'       => 1,
		'fallback_cb' => false, // desativa o fallback nativo (geraria HTML indesejado)
	];

	// 1. Prioridade: location 'primary' configurada no WP Admin
	$locs = get_nav_menu_locations();
	if ( ! empty( $locs['primary'] ) ) {
		return array_merge( $base, [ 'theme_location' => 'primary' ] );
	}

	// 2. Auto-detecta: usa o primeiro menu cadastrado (qualquer um)
	$all = wp_get_nav_menus( [ 'orderby' => 'term_id', 'order' => 'ASC' ] );
	if ( ! empty( $all ) ) {
		return array_merge( $base, [ 'menu' => $all[0]->term_id ] );
	}

	// 3. Nenhum menu existe → template usa fallback hardcoded
	return null;
}

// =============================================================================
// 18. CALLBACK DE COMENTÁRIOS
// =============================================================================
/**
 * Renderiza um comentário individual no template DSI.
 * Callback passado para wp_list_comments().
 */
function dsi_render_comment( WP_Comment $comment, array $args, int $depth ): void {
    $post_author_id = (int) get_post_field( 'post_author', $comment->comment_post_ID );
    $is_author      = ( (int) $comment->user_id > 0 && (int) $comment->user_id === $post_author_id );

    // Avatar
    $avatar   = get_avatar( $comment, 56, '', get_comment_author( $comment ), [ 'class' => 'dsi-comment__avatar-img' ] );

    // Iniciais fallback
    $name     = get_comment_author( $comment );
    $parts    = explode( ' ', $name, 2 );
    $initials = strtoupper( mb_substr( $parts[0], 0, 1 ) ) . ( isset( $parts[1] ) ? strtoupper( mb_substr( $parts[1], 0, 1 ) ) : '' );

    $classes = 'dsi-comment' . ( $is_author ? ' dsi-comment--author' : '' );
    ?>
    <li id="comment-<?php comment_ID(); ?>" <?php comment_class( $classes, $comment ); ?>>
        <div class="dsi-comment__inner">
            <div class="dsi-comment__avatar" aria-hidden="true">
                <?php if ( $avatar ) : echo $avatar;
                else : ?><span class="dsi-comment__avatar-init"><?php echo esc_html( $initials ); ?></span><?php
                endif; ?>
            </div>
            <div class="dsi-comment__body">
                <div class="dsi-comment__meta">
                    <span class="dsi-comment__author">
                        <?php echo esc_html( $name ); ?>
                        <?php if ( $is_author ) : ?>
                            <span class="dsi-comment__author-badge">Autor</span>
                        <?php endif; ?>
                    </span>
                    <time class="dsi-comment__date" datetime="<?php comment_date( 'c', $comment ); ?>">
                        <?php comment_date( 'j M Y', $comment ); ?> às <?php comment_time( 'H:i', $comment ); ?>
                    </time>
                </div>
                <?php if ( '0' === $comment->comment_approved ) : ?>
                    <p class="dsi-comment__pending">Seu comentário está aguardando moderação.</p>
                <?php endif; ?>
                <div class="dsi-comment__text"><?php comment_text( $comment ); ?></div>
                <div class="dsi-comment__actions">
                    <?php comment_reply_link( array_merge( $args, [
                        'reply_text' => '↩ Responder',
                        'depth'      => $depth,
                        'max_depth'  => $args['max_depth'],
                    ] ), $comment ); ?>
                </div>
            </div>
        </div>
    <?php
    // Nota: wp_list_comments fecha o <li> automaticamente quando end-callback = false
}

add_action( 'admin_post_dsi_save_conteudo', function (): void {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Permissão negada.' );
	}
	check_admin_referer( 'dsi_conteudo_nonce', 'dsi_nonce' );

	update_option( 'dsi_tv_title',         sanitize_text_field( wp_unslash( $_POST['dsi_tv_title']         ?? '' ) ) );
	update_option( 'dsi_pull_quote_text',  sanitize_text_field( wp_unslash( $_POST['dsi_pull_quote_text']  ?? '' ) ) );
	update_option( 'dsi_pull_quote_attr',  sanitize_text_field( wp_unslash( $_POST['dsi_pull_quote_attr']  ?? '' ) ) );
	update_option( 'dsi_pull_quote_image', esc_url_raw( wp_unslash( $_POST['dsi_pull_quote_image'] ?? '' ) ) );

	$slots = [];
	for ( $i = 0; $i < 5; $i++ ) {
		$slots[] = [
			'time'  => sanitize_text_field( wp_unslash( $_POST[ "tv_time_$i"  ] ?? '' ) ),
			'genre' => sanitize_text_field( wp_unslash( $_POST[ "tv_genre_$i" ] ?? '' ) ),
			'title' => sanitize_text_field( wp_unslash( $_POST[ "tv_title_$i" ] ?? '' ) ),
			'link'  => esc_url_raw( wp_unslash( $_POST[ "tv_link_$i"  ] ?? '' ) ),
			'image' => esc_url_raw( wp_unslash( $_POST[ "tv_image_$i" ] ?? '' ) ),
		];
	}
	update_option( 'dsi_tv_slots', $slots );

	wp_redirect( admin_url( 'admin.php?page=dsi-conteudo&saved=1' ) );
	exit;
} );

// =============================================================================
// 19. WEB STORIES — registrar 'category' no post type para feed /rapidinhas/
// =============================================================================
add_action( 'init', function () {
	register_taxonomy_for_object_type( 'category', 'web-story' );
}, 11 ); // priority 11: após o plugin registrar o post type

// =============================================================================
// WEB STORIES — override via template_include
// =============================================================================
add_filter( 'template_include', function ( string $tpl ): string {
	if ( ! is_post_type_archive( 'web-story' ) ) return $tpl;
	$custom = get_stylesheet_directory() . '/archive-web-story.php';
	return file_exists( $custom ) ? $custom : $tpl;
}, 20 );

// =============================================================================
// 20. NEWSLETTER — Integração MailerLite
// =============================================================================

// Registra newsletter.js e injeta nonce + ajaxUrl
add_action( 'wp_enqueue_scripts', function (): void {
	wp_enqueue_script(
		'dsi-newsletter',
		get_stylesheet_directory_uri() . '/assets/js/newsletter.js',
		[ 'dsi-masthead' ],   // carrega após dsi-masthead (que tem defer)
		'1.0.0',
		[ 'strategy' => 'defer', 'in_footer' => true ]
	);
	wp_localize_script( 'dsi-newsletter', 'dsiNewsletter', [
		'ajaxUrl' => admin_url( 'admin-ajax.php' ),
		'nonce'   => wp_create_nonce( 'dsi_newsletter' ),
	] );
} );

// Handler AJAX (logado e não logado)
add_action( 'wp_ajax_nopriv_dsi_newsletter_subscribe', 'dsi_newsletter_subscribe' );
add_action( 'wp_ajax_dsi_newsletter_subscribe',        'dsi_newsletter_subscribe' );

function dsi_newsletter_subscribe(): void {
	check_ajax_referer( 'dsi_newsletter', 'nonce' );

	$email = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
	if ( ! is_email( $email ) ) {
		wp_send_json_error( [ 'message' => 'Email inválido.' ] );
	}

	$api_key  = defined( 'DSI_MAILERLITE_KEY' ) ? DSI_MAILERLITE_KEY : '';
	$group_id = '188168656705816082';

	if ( empty( $api_key ) ) {
		wp_send_json_error( [ 'message' => 'Newsletter temporariamente indisponível.' ] );
	}

	$response = wp_remote_post(
		'https://connect.mailerlite.com/api/subscribers',
		[
			'headers' => [
				'Authorization' => 'Bearer ' . $api_key,
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
			],
			'body'    => wp_json_encode( [
				'email'  => $email,
				'groups' => [ $group_id ],
			] ),
			'timeout' => 15,
		]
	);

	if ( is_wp_error( $response ) ) {
		wp_send_json_error( [ 'message' => 'Erro de conexão. Tente novamente.' ] );
	}

	$code = (int) wp_remote_retrieve_response_code( $response );

	if ( $code === 200 || $code === 201 ) {
		wp_send_json_success( [ 'message' => 'Cadastrado com sucesso!' ] );
	} elseif ( $code === 422 ) {
		// Já cadastrado — tratar como sucesso para não revelar dados
		wp_send_json_success( [ 'message' => 'Você já está na lista!' ] );
	} else {
		wp_send_json_error( [ 'message' => 'Erro ao cadastrar. Tente novamente.' ] );
	}
}

// =============================================================================
// 21. PERFORMANCE — defer de scripts síncronos no <head> (TBT)
// =============================================================================
// jquery-core/jquery-migrate (WP core) e cream-magazine-bundle (tema pai) carregam
// síncronos no head. Nenhum script inline do head depende de jQuery de forma síncrona
// (confirmado via inspeção do HTML renderizado) — defer preserva a ordem de execução
// entre eles porque scripts com defer rodam na ordem em que aparecem no documento.
add_filter( 'script_loader_tag', function ( string $tag, string $handle, string $src ): string {
	$defer_handles = [ 'jquery-core', 'jquery-migrate', 'cream-magazine-bundle' ];

	if ( in_array( $handle, $defer_handles, true ) && ! str_contains( $tag, ' defer' ) ) {
		$tag = str_replace( ' src=', ' defer src=', $tag );
	}

	return $tag;
}, 10, 3 );

// =============================================================================
// 22. SEO — Fallback de meta description para categorias/tags sem description
// =============================================================================
// Yoast (wpseo_metadesc) retorna vazio para taxonomias sem term_description
// preenchida e sem template customizado em Search Appearance → Taxonomias.
// Este filtro só atua quando o valor do Yoast vem vazio — não sobrescreve
// nada que já esteja configurado.
add_filter( 'wpseo_metadesc', function ( string $metadesc ): string {
	if ( $metadesc !== '' ) {
		return $metadesc;
	}

	if ( ! is_category() && ! is_tag() ) {
		return $metadesc;
	}

	$term = get_queried_object();
	if ( ! $term instanceof WP_Term ) {
		return $metadesc;
	}

	if ( ! empty( $term->description ) ) {
		return wp_trim_words( wp_strip_all_tags( $term->description ), 30, '…' );
	}

	$label = is_tag() ? 'sobre ' . $term->name : 'de ' . $term->name;
	return sprintf(
		'Confira as melhores críticas, listas e recomendações %s no Deveserisso — o que vale a pena assistir, ler e maratonar.',
		$label
	);
}, 10, 1 );
