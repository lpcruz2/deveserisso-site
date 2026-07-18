<?php
/**
 * 404.php — Página não encontrada
 * Sem breadcrumb (não há queried_object válido nessa página).
 */
get_header();

$recent = new WP_Query( [
    'posts_per_page' => 6,
    'post_status'    => 'publish',
] );
$more_posts = $recent->have_posts() ? $recent->posts : [];
wp_reset_postdata();
?>

<main id="main-content">

<!-- ===== ERRO 404 ===== -->
<section class="dsi-search-empty">
    <p class="dsi-search-empty__title">Página não encontrada</p>
    <p class="dsi-search-empty__hint">O conteúdo que você procura não existe mais ou mudou de endereço. Tente uma busca abaixo ou explore as categorias do site.</p>
</section>

<!-- ===== BUSCA ===== -->
<section class="dsi-search-hero" aria-label="Campo de busca">
    <form class="dsi-search-hero__form"
          role="search"
          method="get"
          action="<?php echo esc_url( home_url( '/' ) ); ?>">
        <label for="dsi-search-field" class="screen-reader-text">Buscar no site</label>
        <span class="dsi-search-hero__icon" aria-hidden="true"><svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg></span>
        <input type="search"
               id="dsi-search-field"
               class="dsi-search-hero__input"
               name="s"
               value=""
               autocomplete="off"
               placeholder="Digite sua busca…">
        <button type="submit" class="dsi-search-hero__btn">Buscar →</button>
    </form>
</section>

<!-- ===== TV + CATEGORIAS (igual à home) ===== -->
<section class="dsi-tv-cats">
    <!-- Programação de hoje -->
    <div class="dsi-tv">
        <div class="dsi-section-head">
            <h2 class="dsi-tv__heading"><?php echo esc_html( get_option( 'dsi_tv_title', 'Programação de hoje' ) ); ?></h2>
            <span class="dsi-rule"></span>
        </div>
        <?php
        $slots = get_option( 'dsi_tv_slots', [
            [ 'time' => '13h00', 'genre' => 'Sessão da Tarde',         'title' => 'Velocidade Máxima',   'link' => '', 'image' => '' ],
            [ 'time' => '15h30', 'genre' => 'Vale a Pena Ver de Novo', 'title' => 'Pega Pega',           'link' => '', 'image' => '' ],
            [ 'time' => '22h25', 'genre' => 'Tela Quente',             'title' => 'John Wick 4',         'link' => '', 'image' => '' ],
            [ 'time' => '01h10', 'genre' => 'Corujão I',               'title' => 'O Advogado do Diabo', 'link' => '', 'image' => '' ],
            [ 'time' => '03h05', 'genre' => 'Corujão II',              'title' => 'Constantine',         'link' => '', 'image' => '' ],
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
                        <img src="<?php echo esc_url( $image ); ?>" alt="<?php echo esc_attr( $slot['title'] ); ?>" loading="lazy">
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

    <!-- Índice de categorias — usa menu WP "Explorar por tema" -->
    <div class="dsi-cat-index">
        <p class="dsi-cat-index__eyebrow">Índice de categorias</p>
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
            foreach ( $cats as $c ) : ?>
                <a href="<?php echo esc_url( get_category_link( $c ) ); ?>" class="dsi-cat-index__row">
                    <span class="dsi-cat-index__name"><?php echo esc_html( $c->name ); ?></span>
                    <span class="dsi-cat-index__count"><?php echo esc_html( $c->count ); ?> posts</span>
                </a>
            <?php endforeach;
        endif; ?>
    </div>
</section>

<!-- ===== POSTS RECENTES ===== -->
<?php if ( count( $more_posts ) > 0 ) : ?>
<section class="dsi-more">
    <div class="dsi-section-head">
        <h2 class="dsi-more__heading">Continue explorando</h2>
        <span class="dsi-rule"></span>
    </div>
    <div class="dsi-more__grid">
        <?php foreach ( $more_posts as $p ) :
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

<?php get_template_part( 'template-parts/newsletter' ); ?>
<?php get_template_part( 'template-parts/footer-content' ); ?>
<?php get_footer(); ?>
