<?php
/**
 * search.php — Resultados de busca
 */
get_header();

$query      = get_search_query();
$found      = $wp_query->found_posts;
$max_pages  = $wp_query->max_num_pages;
$paged      = max( 1, get_query_var( 'paged' ) );
$top_result = null;
$other      = [];
$i          = 0;

if ( have_posts() ) {
    while ( have_posts() ) {
        the_post();
        if ( $i === 0 ) {
            $top_result = $GLOBALS['post'];
        } else {
            $other[] = $GLOBALS['post'];
        }
        $i++;
    }
    rewind_posts();
}
?>

<!-- Search Hero -->
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
               value="<?php echo esc_attr( $query ); ?>"
               autocomplete="off"
               placeholder="Digite sua busca…">
        <button type="submit" class="dsi-search-hero__btn">Buscar →</button>
    </form>
</section>

<!-- Resultado principal -->
<?php if ( $top_result ) :
    $tp_thumb = get_the_post_thumbnail( $top_result->ID, 'dsi-wide', [ 'class' => 'dsi-lead__img' ] );
    $tp_pub   = get_the_date( 'Y-m-d', $top_result->ID );
    $tp_mod   = get_the_modified_date( 'Y-m-d', $top_result->ID );
    $tp_upd   = $tp_mod && $tp_mod !== $tp_pub;
?>
<section class="dsi-lead">
    <div class="dsi-lead__head">
        <span class="dsi-lead__eyebrow-badge">Resultado principal</span>
        <span class="dsi-rule"></span>
    </div>
    <div class="dsi-lead__grid">
        <div class="dsi-lead__frame">
            <a href="<?php echo esc_url( get_permalink( $top_result->ID ) ); ?>" tabindex="-1" aria-hidden="true">
                <?php if ( $tp_thumb ) : echo $tp_thumb; else : ?>
                    <div class="dsi-lead__frame-fallback" aria-hidden="true"></div>
                <?php endif; ?>
            </a>
        </div>
        <div class="dsi-lead__content">
            <h2 class="dsi-lead__title">
                <a href="<?php echo esc_url( get_permalink( $top_result->ID ) ); ?>">
                    <?php echo dsi_highlight_search( esc_html( get_the_title( $top_result->ID ) ) ); ?>
                </a>
            </h2>
            <p class="dsi-lead__excerpt"><?php echo dsi_highlight_search( dsi_excerpt( 200, $top_result->ID ) ); ?></p>
            <div class="dsi-lead__byline">
                <a href="<?php echo esc_url( get_author_posts_url( $top_result->post_author ) ); ?>" class="dsi-lead__avatar">
                    <?php
                    $av = get_avatar( $top_result->post_author, 52, '', '', [ 'class' => 'dsi-lead__avatar-img' ] );
                    if ( $av ) { echo $av; }
                    else { echo '<span>' . esc_html( dsi_author_initials( $top_result->post_author ) ) . '</span>'; }
                    ?>
                </a>
                <div>
                    <p class="dsi-lead__author">
                        <a href="<?php echo esc_url( get_author_posts_url( $top_result->post_author ) ); ?>">
                            <?php echo esc_html( get_the_author_meta( 'display_name', $top_result->post_author ) ); ?>
                        </a>
                    </p>
                    <p class="dsi-lead__meta">
                        <time datetime="<?php echo esc_attr( $tp_upd ? get_the_modified_date( 'c', $top_result->ID ) : get_the_date( 'c', $top_result->ID ) ); ?>">
                            <?php echo $tp_upd ? get_the_modified_date( 'j M Y', $top_result->ID ) : get_the_date( 'j M Y', $top_result->ID ); ?>
                        </time>
                        · <span>⏱ <?php echo esc_html( dsi_read_time( $top_result->ID ) ); ?> de leitura</span>
                    </p>
                </div>
            </div>
        </div>
    </div>
</section>
<?php endif; ?>

<!-- Lista de resultados -->
<?php if ( ! empty( $other ) ) : ?>
<section class="dsi-archive-grid">
    <div class="dsi-archive-grid__head">
        <h2 class="dsi-archive-grid__heading">Outros <?php echo esc_html( max( 0, $found - 1 ) ); ?> resultados</h2>
        <span class="dsi-rule"></span>
    </div>
    <div class="dsi-archive-list">
        <?php foreach ( $other as $p ) :
            $p_thumb = get_the_post_thumbnail( $p->ID, 'dsi-square', [ 'class' => 'dsi-card-row__thumb-img' ] );
            $p_pub   = get_the_date( 'Y-m-d', $p->ID );
            $p_mod   = get_the_modified_date( 'Y-m-d', $p->ID );
            $p_upd   = $p_mod && $p_mod !== $p_pub;
        ?>
            <article class="dsi-card-row">
                <div class="dsi-card-row__thumb">
                    <a href="<?php echo esc_url( get_permalink( $p->ID ) ); ?>" tabindex="-1" aria-hidden="true">
                        <?php if ( $p_thumb ) : echo $p_thumb; else : ?>
                            <div style="width:100%;height:100%;background:#ebe3d2;" aria-hidden="true"></div>
                        <?php endif; ?>
                    </a>
                </div>
                <div class="dsi-card-row__body">
                    <h4 class="dsi-card-row__title">
                        <a href="<?php echo esc_url( get_permalink( $p->ID ) ); ?>">
                            <?php echo dsi_highlight_search( esc_html( get_the_title( $p->ID ) ) ); ?>
                        </a>
                    </h4>
                    <p class="dsi-card-row__excerpt"><?php echo dsi_highlight_search( dsi_excerpt( 120, $p->ID ) ); ?></p>
                    <div class="dsi-card-row__byline">
                        <span class="dsi-card-row__author"><?php echo esc_html( get_the_author_meta( 'display_name', $p->post_author ) ); ?></span>
                        <span class="dsi-card-row__date">
                            <time datetime="<?php echo esc_attr( $p_upd ? get_the_modified_date( 'c', $p->ID ) : get_the_date( 'c', $p->ID ) ); ?>">
                                <?php echo $p_upd ? get_the_modified_date( 'j M Y', $p->ID ) : get_the_date( 'j M Y', $p->ID ); ?>
                            </time>
                            · ⏱ <?php echo esc_html( dsi_read_time( $p->ID ) ); ?>
                        </span>
                    </div>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<!-- Sem resultados -->
<?php if ( $found === 0 ) : ?>
<section class="dsi-search-empty">
    <p class="dsi-search-empty__title">Nenhum resultado para "<?php echo esc_html( $query ); ?>"</p>
    <p class="dsi-search-empty__hint">Tente termos mais curtos, verifique a ortografia ou explore as categorias abaixo.</p>
    <div class="dsi-cat-index" style="max-width:480px;margin-top:40px">
        <?php
        $menu_items = wp_get_nav_menu_items( 'Explorar por tema' );
        if ( $menu_items ) :
            foreach ( $menu_items as $item ) : ?>
                <a href="<?php echo esc_url( $item->url ); ?>" class="dsi-cat-index__row">
                    <span class="dsi-cat-index__name"><?php echo esc_html( $item->title ); ?></span>
                </a>
            <?php endforeach;
        endif; ?>
    </div>
</section>
<?php endif; ?>

<!-- Buscas populares — tags/cats com >10 posts, sem contagem -->
<?php
$popular_tags = get_tags( [ 'number' => 30, 'orderby' => 'count', 'order' => 'DESC', 'hide_empty' => true ] );
$popular_tags = array_filter( $popular_tags, fn( $t ) => $t->count > 10 );
if ( ! empty( $popular_tags ) ) : ?>
<section class="dsi-tag-filter">
    <div class="dsi-tag-filter__head">
        <span class="dsi-tag-filter__label">Buscas populares</span>
        <span class="dsi-rule dsi-rule--muted"></span>
    </div>
    <div class="dsi-tag-filter__chips">
        <?php foreach ( $popular_tags as $t_obj ) : ?>
            <a href="<?php echo esc_url( home_url( '/?s=' . urlencode( $t_obj->name ) ) ); ?>"
               class="dsi-tag-chip">
                <?php echo esc_html( $t_obj->name ); ?>
            </a>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<!-- TV + Categorias (igual home e category) -->
<section class="dsi-tv-cats">
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
            $link  = $slot['link'] ?? '';
        ?>
            <div class="dsi-tv__row">
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

<?php dsi_pagination( [ 'total' => $max_pages ] ); ?>

<?php get_template_part( 'template-parts/newsletter' ); ?>
<?php get_template_part( 'template-parts/footer-content' ); ?>
<?php get_footer(); ?>
