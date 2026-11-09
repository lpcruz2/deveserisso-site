<?php
/**
 * home.php — Template da homepage (layout de portal, inspirado na home do UOL)
 * Seções: Manchete + subchamadas + lateral → Grade densa → Frase da semana → Programação de TV + Explorar por tema → Faixas por categoria → Newsletter
 * Classes novas com prefixo dsi-portal-*; as antigas (dsi-hero, dsi-tricolumn, dsi-more…) seguem no CSS por segurança.
 */

get_header();

/** Card de grade: imagem 4:3, rótulo de categoria e título em negrito. */
if ( ! function_exists( 'dsi_portal_card' ) ) :
function dsi_portal_card( WP_Post $p, bool $eager = false ): void {
    $thumb = get_the_post_thumbnail( $p->ID, 'dsi-wide-sm', [
        'class'   => 'dsi-portal-card__img',
        'loading' => $eager ? 'eager' : 'lazy',
        'sizes'   => '(max-width:768px) 50vw, 240px',
    ] );
    ?>
    <article class="dsi-portal-card">
        <a class="dsi-portal-card__link" href="<?php echo esc_url( get_permalink( $p->ID ) ); ?>">
            <span class="dsi-portal-card__frame">
                <?php echo $thumb ?: '<span class="dsi-portal-card__fallback" aria-hidden="true"></span>'; ?>
            </span>
            <span class="dsi-portal-card__tag"><?php echo esc_html( dsi_primary_category( $p->ID ) ); ?></span>
            <h3 class="dsi-portal-card__title"><?php echo esc_html( get_the_title( $p->ID ) ); ?></h3>
        </a>
    </article>
    <?php
}
endif;

$recent = new WP_Query( [
    'posts_per_page'         => 70,
    'post_status'            => 'publish',
    'orderby'                => 'modified',
    'no_found_rows'          => true,
    'ignore_sticky_posts'    => true,
] );

// Fora do feed da home: páginas de filmes por letra (Stop/Adedonha) e a de mímica; elas têm lugar próprio no menu
$dsi_no_feed = static function ( $p ) {
    return ! preg_match( '/^(filmes?-com-(a-)?letra|filme-com-[a-z]-|filmes-para-brincar-de-mimica)/', $p->post_name );
};
$all = array_values( array_filter( $recent->have_posts() ? $recent->posts : [], $dsi_no_feed ) );
$lead_post  = $all[0] ?? null;
$sub_posts  = array_slice( $all, 1, 5 );   // 5 subchamadas ao lado da manchete
$grid_posts = array_slice( $all, 6, 16 );  // grade densa
$more_posts = array_slice( $all, 22, 16 ); // "Mais filmes e séries", abaixo da programação de TV
$used_ids   = wp_list_pluck( array_merge( $lead_post ? [ $lead_post ] : [], $sub_posts, $grid_posts, $more_posts ), 'ID' ); // só o que já aparece na home
wp_reset_postdata();

// Faixas por categoria: slugs na option `dsi_home_faixas` (padrão: as categorias com mais conteúdo). Só renderiza faixa com 4 posts.
$band_cats = [];
foreach ( (array) get_option( 'dsi_home_faixas', [ 'filmes', 'series', 'animes-e-manga', 'livros' ] ) as $band_slug ) {
    $t = get_category_by_slug( $band_slug );
    if ( $t && $t->count > 0 ) $band_cats[] = $t;
}
?>

<main id="main-content" class="dsi-portal">

<!-- ===== MANCHETE + SUBCHAMADAS + LATERAL ===== -->
<?php if ( $lead_post ) :
    $lead_thumb = get_the_post_thumbnail( $lead_post->ID, 'dsi-wide', [
        'class'         => 'dsi-portal-lead__img',
        'loading'       => 'eager',
        'fetchpriority' => 'high',
        'decoding'      => 'async',
        'sizes'         => '(max-width:768px) 100vw, 640px',
    ] );
?>
<section class="dsi-portal-top" aria-label="Destaques">
    <div class="dsi-portal-top__main">
        <article class="dsi-portal-lead">
            <a class="dsi-portal-lead__link" href="<?php echo esc_url( get_permalink( $lead_post->ID ) ); ?>">
                <span class="dsi-portal-lead__frame">
                    <?php echo $lead_thumb ?: '<span class="dsi-portal-card__fallback" aria-hidden="true"></span>'; ?>
                </span>
                <span class="dsi-portal-tag"><?php echo esc_html( dsi_primary_category( $lead_post->ID ) ); ?></span>
                <h2 class="dsi-portal-lead__title"><?php echo esc_html( get_the_title( $lead_post->ID ) ); ?></h2>
                <p class="dsi-portal-lead__excerpt"><?php echo dsi_excerpt( 160, $lead_post->ID ); ?></p>
            </a>
        </article>

        <?php if ( $sub_posts ) : ?>
        <ul class="dsi-portal-subs">
            <?php foreach ( $sub_posts as $p ) : ?>
                <li class="dsi-portal-subs__item">
                    <a href="<?php echo esc_url( get_permalink( $p->ID ) ); ?>">
                        <span class="dsi-portal-subs__tag"><?php echo esc_html( dsi_primary_category( $p->ID ) ); ?></span>
                        <span class="dsi-portal-subs__title"><?php echo esc_html( get_the_title( $p->ID ) ); ?></span>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </div>

    <?php get_template_part( 'template-parts/home-sidebar' ); ?>
</section>
<?php endif; ?>

<!-- ===== GRADE DENSA ===== -->
<?php if ( $grid_posts ) : ?>
<section class="dsi-portal-grid-sec" aria-label="Últimas do site">
    <h2 class="dsi-portal-head"><span>Últimas do site</span></h2>
    <div class="dsi-portal-grid">
        <?php foreach ( $grid_posts as $p ) dsi_portal_card( $p ); ?>
    </div>
</section>
<?php endif; ?>

<!-- ===== PULL QUOTE — Frase da semana ===== -->
<section class="dsi-pullquote" aria-label="Frase da semana">
    <?php
    // Tenta buscar um post de quote customizado; fallback para editorial fixo
    $pq_text = get_option( 'dsi_pull_quote_text', '"Cinema, para mim, sempre foi a forma mais honesta de mentir."' );
    $pq_attr = get_option( 'dsi_pull_quote_attr', '— Citação editorial · Deveserisso' );
    $pq_thumb = get_option( 'dsi_pull_quote_image', '' );
    ?>
    <div class="dsi-pullquote__image">
        <?php if ( $pq_thumb ) : ?>
            <img src="<?php echo esc_url( $pq_thumb ); ?>" alt="" width="400" height="400" loading="lazy">
        <?php else : ?>
            <div class="dsi-pullquote__image-fallback" aria-hidden="true"></div>
        <?php endif; ?>
    </div>
    <div class="dsi-pullquote__body">
        <p class="dsi-pullquote__eyebrow">※ Frase da semana</p>
        <blockquote class="dsi-pullquote__quote">
            <span class="dsi-pullquote__mark" aria-hidden="true">"</span>
            <?php echo esc_html( $pq_text ); ?>
            <span class="dsi-pullquote__mark" aria-hidden="true">"</span>
        </blockquote>
        <p class="dsi-pullquote__attr"><?php echo esc_html( $pq_attr ); ?></p>
    </div>
</section>

<!-- ===== TV + CATEGORIAS ===== -->
<section class="dsi-tv-cats">
    <!-- Programação de hoje -->
    <div class="dsi-tv">
        <div class="dsi-section-head">
            <h2 class="dsi-tv__heading"><?php echo esc_html( get_option( 'dsi_tv_title', 'Programação de hoje' ) ); ?></h2>
            <span class="dsi-rule"></span>
        </div>
        <?php
        // Slots de programação (configuráveis via DSI Conteúdo no wp-admin)
        $slots = get_option( 'dsi_tv_slots', [
            [ 'time' => '13h00', 'genre' => 'Sessão da Tarde',           'title' => 'Velocidade Máxima',    'link' => '', 'image' => '' ],
            [ 'time' => '15h30', 'genre' => 'Vale a Pena Ver de Novo',   'title' => 'Pega Pega',            'link' => '', 'image' => '' ],
            [ 'time' => '22h25', 'genre' => 'Tela Quente',               'title' => 'John Wick 4',          'link' => '', 'image' => '' ],
            [ 'time' => '01h10', 'genre' => 'Corujão I',                 'title' => 'O Advogado do Diabo',  'link' => '', 'image' => '' ],
            [ 'time' => '03h05', 'genre' => 'Corujão II',                'title' => 'Constantine',          'link' => '', 'image' => '' ],
        ] );
        foreach ( $slots as $slot ) :
            if ( empty( $slot['time'] ) && empty( $slot['title'] ) ) continue;
            $genre = ! empty( $slot['genre'] ) ? $slot['genre'] : ( $slot['show'] ?? '' );
            $link  = $slot['link']  ?? '';
            $image = $slot['image'] ?? '';
        ?>
            <div class="dsi-tv__row<?php echo $image ? ' dsi-tv__row--has-image' : ''; ?>">
                <?php if ( $image ) : ?>
                    <div class="dsi-tv__poster">
                        <img src="<?php echo esc_url( $image ); ?>"
                             alt="<?php echo esc_attr( $slot['title'] ); ?>"
                             loading="lazy">
                    </div>
                <?php endif; ?>
                <div class="dsi-tv__meta">
                    <span class="dsi-tv__time"><?php echo esc_html( $slot['time'] ); ?></span>
                    <span class="dsi-tv__show"><?php echo esc_html( $genre ); ?></span>
                </div>
                <span class="dsi-tv__title">
                    <?php if ( $link ) : ?>
                        <a href="<?php echo esc_url( $link ); ?>"><?php echo esc_html( $slot['title'] ); ?></a>
                    <?php else : ?>
                        <?php echo esc_html( $slot['title'] ); ?>
                    <?php endif; ?>
                </span>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Índice de categorias -->
    <div class="dsi-cat-index">
        <h2 class="dsi-cat-index__heading">Explorar por tema</h2>
        <?php
        $menu_items = wp_get_nav_menu_items( 'Explorar por tema' );
        if ( $menu_items ) :
            foreach ( $menu_items as $item ) :
                $count = '';
                if ( $item->type === 'taxonomy' && $item->object === 'category' ) {
                    $t = get_term( $item->object_id, 'category' );
                    if ( $t && ! is_wp_error( $t ) ) $count = $t->count;
                } ?>
                <a href="<?php echo esc_url( $item->url ); ?>" class="dsi-cat-index__row">
                    <span class="dsi-cat-index__name"><?php echo esc_html( $item->title ); ?></span>
                    <?php if ( $count ) : ?><span class="dsi-cat-index__count"><?php echo esc_html( $count ); ?> posts</span><?php endif; ?>
                </a>
            <?php endforeach;
        else :
            $cats = get_categories( [ 'orderby' => 'count', 'order' => 'DESC', 'number' => 9, 'hide_empty' => true ] );
            foreach ( $cats as $cat ) : ?>
                <a href="<?php echo esc_url( get_category_link( $cat ) ); ?>" class="dsi-cat-index__row">
                    <span class="dsi-cat-index__name"><?php echo esc_html( $cat->name ); ?></span>
                    <span class="dsi-cat-index__count"><?php echo esc_html( $cat->count ); ?> posts</span>
                </a>
            <?php endforeach;
        endif; ?>
    </div>
</section>

<!-- ===== MAIS FILMES E SÉRIES (grade densa abaixo da programação, como no UOL) ===== -->
<?php if ( $more_posts ) : ?>
<section class="dsi-portal-grid-sec" aria-label="Mais filmes e séries">
    <h2 class="dsi-portal-head"><span>Mais filmes e séries</span></h2>
    <div class="dsi-portal-grid">
        <?php foreach ( $more_posts as $p ) dsi_portal_card( $p ); ?>
    </div>
</section>
<?php endif; ?>

<!-- ===== FAIXAS POR CATEGORIA (4 cards por faixa, mesma grade do resto da home) ===== -->
<?php foreach ( $band_cats as $cat ) :
    $band = new WP_Query( [
        'cat'                 => $cat->term_id,
        'posts_per_page'      => 60,
        'post__not_in'        => $used_ids,
        'post_status'         => 'publish',
        'orderby'             => 'modified',
        'no_found_rows'       => true,
        'ignore_sticky_posts' => true,
    ] );
    $band_posts = array_slice( array_values( array_filter( $band->posts, $dsi_no_feed ) ), 0, 4 );
    if ( count( $band_posts ) < 4 ) { wp_reset_postdata(); continue; }
    $used_ids = array_merge( $used_ids, wp_list_pluck( $band_posts, 'ID' ) );
?>
<section class="dsi-portal-grid-sec dsi-portal-band" aria-label="<?php echo esc_attr( $cat->name ); ?>">
    <h2 class="dsi-portal-head">
        <a href="<?php echo esc_url( get_category_link( $cat ) ); ?>"><span><?php echo esc_html( $cat->name ); ?></span></a>
        <a class="dsi-portal-head__more" href="<?php echo esc_url( get_category_link( $cat ) ); ?>">ver tudo ›</a>
    </h2>
    <div class="dsi-portal-grid">
        <?php foreach ( $band_posts as $p ) dsi_portal_card( $p ); ?>
    </div>
</section>
<?php wp_reset_postdata(); endforeach; ?>

</main>

<!-- ===== NEWSLETTER ===== -->
<?php get_template_part( 'template-parts/newsletter' ); ?>

<!-- ===== FOOTER ===== -->
<?php get_template_part( 'template-parts/footer-content' ); ?>
<?php get_footer(); ?>
