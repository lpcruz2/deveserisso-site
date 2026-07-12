<?php
/**
 * home.php — Template da homepage (blog posts index)
 * Seções: Frontispiece → HeroSplit → Tricolumn → PullQuote → TVAndCategories → MoreReading → Newsletter
 */

// Helper function to add srcset to images
function add_srcset_to_image( $img_html ) {
    if ( ! $img_html ) return $img_html;

    preg_match( '/src="([^"]+)"/', $img_html, $matches );
    $img_url = $matches[1] ?? '';

    if ( ! $img_url ) return $img_html;

    $path_parts = explode( '/', $img_url );
    $filename = end( $path_parts );
    $dir = implode( '/', array_slice( $path_parts, 0, -1 ) );
    $base_name = pathinfo( $filename, PATHINFO_FILENAME );

    // Add srcset with 400w and 768w variants
    $srcset = "$dir/{$base_name}-400w.webp 400w, $dir/{$base_name}-768w.webp 768w, " . $img_url . " original";

    return str_replace( 'src="', 'srcset="' . esc_attr( $srcset ) . '" src="', $img_html );
}

get_header();

// Query principal (primeiro post para o hero)
$hero_post = null;
$three_posts = [];
$more_posts = [];

$recent = new WP_Query( [
    'posts_per_page' => 22,
    'post_status'    => 'publish',
] );

if ( $recent->have_posts() ) {
    $all = $recent->posts;
    $hero_post   = $all[0] ?? null;
    $three_posts = array_slice( $all, 1, 3 );
    $more_posts  = array_slice( $all, 4, 8 );
    $extra_posts = array_slice( $all, 12, 6 );
}
wp_reset_postdata();
?>

<main id="main-content">

<!-- ===== HERO SPLIT ===== -->
<?php if ( $hero_post ) :
    setup_postdata( $hero_post );
    $hero_thumb = get_the_post_thumbnail( $hero_post->ID, 'dsi-poster', [ 'class' => 'dsi-hero__img', 'loading' => 'eager', 'fetchpriority' => 'high', 'decoding' => 'sync' ] );
    $hero_author_initials = dsi_author_initials( $hero_post->post_author );
?>
<section class="dsi-hero" aria-label="Ensaio principal">
    <div class="dsi-hero__text">
        <div class="dsi-hero__badge">
            <a href="<?php echo esc_url( dsi_primary_category_url( $hero_post->ID ) ); ?>">
                <?php echo esc_html( dsi_primary_category( $hero_post->ID ) ); ?>
            </a>
        </div>
        <a href="<?php echo esc_url( get_permalink( $hero_post->ID ) ); ?>" class="dsi-hero__content-link">
            <h2 class="dsi-hero__title">
                <?php echo esc_html( get_the_title( $hero_post->ID ) ); ?>
            </h2>
            <p class="dsi-hero__excerpt">
                <?php echo get_the_excerpt( $hero_post->ID ); ?>
            </p>
        </a>
        <div class="dsi-hero__byline">
            <div class="dsi-hero__avatar" aria-hidden="true"><?php echo esc_html( $hero_author_initials ); ?></div>
            <div>
                <p class="dsi-hero__author"><?php echo esc_html( get_the_author_meta( 'display_name', $hero_post->post_author ) ); ?></p>
                <p class="dsi-hero__meta">
                    <?php echo get_the_date( 'j M Y', $hero_post->ID ); ?> ·
                    <?php echo esc_html( dsi_read_time( $hero_post->ID ) ); ?>
                </p>
            </div>
        </div>
    </div>
    <div class="dsi-hero__poster">
        <a href="<?php echo esc_url( get_permalink( $hero_post->ID ) ); ?>" tabindex="-1" aria-hidden="true">
        <div class="dsi-hero__poster-frame">
            <?php if ( $hero_thumb ) : ?>
                <?php
                // Extract image URL from hero_thumb and add srcset
                preg_match( '/src="([^"]+)"/', $hero_thumb, $matches );
                $hero_img_url = $matches[1] ?? '';

                if ( $hero_img_url ) {
                    // Generate srcset variants based on image path
                    $path_parts = explode( '/', $hero_img_url );
                    $filename = end( $path_parts );
                    $dir = implode( '/', array_slice( $path_parts, 0, -1 ) );
                    $base_name = pathinfo( $filename, PATHINFO_FILENAME );
                    $ext = pathinfo( $filename, PATHINFO_EXTENSION );

                    // Build srcset URL pattern
                    $srcset = "$dir/{$base_name}-400w.$ext 400w, $dir/{$base_name}-768w.$ext 768w, $dir/{$base_name}-1200w.$ext 1200w";
                    $srcset_webp = "$dir/{$base_name}-400w.webp 400w, $dir/{$base_name}-768w.webp 768w, $dir/{$base_name}-1200w.webp 1200w";

                    // Replace in hero_thumb
                    $hero_thumb = str_replace( 'src="', 'srcset="' . esc_attr( $srcset_webp ) . '" src="', $hero_thumb );
                    $hero_thumb = str_replace( 'decoding="sync"', 'decoding="async"', $hero_thumb );
                }
                ?>
                <?php echo $hero_thumb; ?>
            <?php else : ?>
                <div class="dsi-hero__poster-fallback" aria-hidden="true"></div>
            <?php endif; ?>
        </div>
        </a>
        <p class="dsi-hero__poster-caption" aria-hidden="true">
            ↑ Imagem de destaque · <?php echo esc_html( get_the_title( $hero_post->ID ) ); ?>
        </p>
    </div>
</section>
<?php wp_reset_postdata(); endif; ?>

<!-- ===== TRICOLUMN — Esta semana ===== -->
<?php if ( count( $three_posts ) >= 1 ) : ?>
<section class="dsi-tricolumn">
    <div class="dsi-section-head">
        <h2 class="dsi-tricolumn__heading">Esta semana</h2>
        <span class="dsi-rule"></span>
        <span class="dsi-tricolumn__label">Três leituras essenciais</span>
    </div>
    <div class="dsi-tricolumn__grid">
        <?php foreach ( $three_posts as $i => $p ) :
            $thumb   = get_the_post_thumbnail( $p->ID, 'dsi-wide', [ 'class' => 'dsi-tricolumn__img', 'width' => '768', 'height' => '512' ] );
            $initials = dsi_author_initials( $p->post_author );
        ?>
            <article class="dsi-tricolumn__item<?php echo $i > 0 ? ' dsi-tricolumn__item--ruled' : ''; ?>">
                <p class="dsi-tricolumn__num">
                    <a href="<?php echo esc_url( dsi_primary_category_url( $p->ID ) ); ?>"><?php echo esc_html( dsi_primary_category( $p->ID ) ); ?></a>
                </p>
                <?php if ( $thumb ) : ?>
                    <div class="dsi-tricolumn__frame">
                        <a href="<?php echo esc_url( get_permalink( $p->ID ) ); ?>" tabindex="-1" aria-hidden="true">
                            <?php echo add_srcset_to_image( $thumb ); ?>
                        </a>
                    </div>
                <?php endif; ?>
                <a href="<?php echo esc_url( get_permalink( $p->ID ) ); ?>" class="dsi-tricolumn__content-link">
                    <h3 class="dsi-tricolumn__title"><?php echo esc_html( get_the_title( $p->ID ) ); ?></h3>
                    <p class="dsi-tricolumn__excerpt"><?php echo dsi_excerpt( 140, $p->ID ); ?></p>
                </a>
                <p class="dsi-tricolumn__meta">
                    <?php echo esc_html( get_the_author_meta( 'display_name', $p->post_author ) ); ?> ·
                    <?php echo esc_html( dsi_read_time( $p->ID ) ); ?>
                </p>
            </article>
        <?php endforeach; ?>
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

<!-- ===== MORE READING — Para continuar lendo ===== -->
<?php if ( count( $more_posts ) >= 1 ) : ?>
<section class="dsi-more">
    <div class="dsi-section-head">
        <h2 class="dsi-more__heading">Para continuar lendo</h2>
        <span class="dsi-rule"></span>
    </div>
    <div class="dsi-more__grid">
        <?php foreach ( $more_posts as $i => $p ) :
            $thumb = get_the_post_thumbnail( $p->ID, 'dsi-square', [ 'class' => 'dsi-card__thumb-img', 'width' => '400', 'height' => '400' ] );
        ?>
            <article class="dsi-card">
                <div class="dsi-card__thumb dsi-card__thumb--square">
                    <a href="<?php echo esc_url( get_permalink( $p->ID ) ); ?>" tabindex="-1" aria-hidden="true">
                        <?php if ( $thumb ) : ?>
                            <?php echo str_replace( '>', ' loading="lazy">', $thumb ); ?>
                        <?php else : ?>
                            <div class="dsi-card__thumb-fallback" aria-hidden="true"></div>
                        <?php endif; ?>
                    </a>
                </div>
                <p class="dsi-card__eyebrow"><a href="<?php echo esc_url( dsi_primary_category_url( $p->ID ) ); ?>"><?php echo esc_html( dsi_primary_category( $p->ID ) ); ?></a></p>
                <h3 class="dsi-card__title">
                    <a href="<?php echo esc_url( get_permalink( $p->ID ) ); ?>"><?php echo esc_html( get_the_title( $p->ID ) ); ?></a>
                </h3>
                <div class="dsi-card__meta">
                    <div class="dsi-card__avatar" aria-hidden="true"><?php echo esc_html( dsi_author_initials( $p->post_author ) ); ?></div>
                    <div>
                        <p class="dsi-card__author-name"><?php echo esc_html( get_the_author_meta( 'display_name', $p->post_author ) ); ?></p>
                        <p class="dsi-card__byline"><?php echo get_the_date( 'j M Y', $p->ID ); ?> · <?php echo esc_html( dsi_read_time( $p->ID ) ); ?></p>
                    </div>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<!-- ===== MAIS DA REDAÇÃO ===== -->
<?php if ( count( $extra_posts ) >= 1 ) : ?>
<section class="dsi-more">
    <div class="dsi-section-head">
        <h2 class="dsi-more__heading">Mais da redação</h2>
        <span class="dsi-rule"></span>
    </div>
    <div class="dsi-more__grid dsi-more__grid--3col">
        <?php foreach ( $extra_posts as $p ) :
            $thumb = get_the_post_thumbnail( $p->ID, 'dsi-square', [ 'class' => 'dsi-card__thumb-img', 'width' => '400', 'height' => '400' ] );
        ?>
            <article class="dsi-card">
                <div class="dsi-card__thumb dsi-card__thumb--square">
                    <a href="<?php echo esc_url( get_permalink( $p->ID ) ); ?>" tabindex="-1" aria-hidden="true">
                        <?php if ( $thumb ) : ?>
                            <?php echo str_replace( '>', ' loading="lazy">', $thumb ); ?>
                        <?php else : ?>
                            <div class="dsi-card__thumb-fallback" aria-hidden="true"></div>
                        <?php endif; ?>
                    </a>
                </div>
                <p class="dsi-card__eyebrow"><a href="<?php echo esc_url( dsi_primary_category_url( $p->ID ) ); ?>"><?php echo esc_html( dsi_primary_category( $p->ID ) ); ?></a></p>
                <h3 class="dsi-card__title">
                    <a href="<?php echo esc_url( get_permalink( $p->ID ) ); ?>"><?php echo esc_html( get_the_title( $p->ID ) ); ?></a>
                </h3>
                <div class="dsi-card__meta">
                    <div class="dsi-card__avatar" aria-hidden="true"><?php echo esc_html( dsi_author_initials( $p->post_author ) ); ?></div>
                    <div>
                        <p class="dsi-card__author-name"><?php echo esc_html( get_the_author_meta( 'display_name', $p->post_author ) ); ?></p>
                        <p class="dsi-card__byline"><?php echo get_the_date( 'j M Y', $p->ID ); ?> · <?php echo esc_html( dsi_read_time( $p->ID ) ); ?></p>
                    </div>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

</main>

<!-- ===== NEWSLETTER ===== -->
<?php get_template_part( 'template-parts/newsletter' ); ?>

<!-- ===== FOOTER ===== -->
<?php get_template_part( 'template-parts/footer-content' ); ?>
<?php get_footer(); ?>
