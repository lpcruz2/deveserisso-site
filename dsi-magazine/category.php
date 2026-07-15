<?php
/**
 * category.php — Template de categoria/tags
 * Funciona também para tag.php (inclui este template via get_template_part)
 */
get_header();

$cat      = get_queried_object();
$is_tag   = is_tag();
$term_name = $is_tag ? single_tag_title( '', false ) : single_cat_title( '', false );
$total    = $is_tag ? $wp_query->found_posts : $cat->count;

// Primeiro post = destaque; resto vai para a grade
$lead_post  = null;
$grid_posts = [];
$list_posts = [];

if ( have_posts() ) {
    $i = 0;
    while ( have_posts() ) {
        the_post();
        if ( $i === 0 ) {
            $lead_post = $GLOBALS['post'];
        } elseif ( $i <= 3 ) {
            $grid_posts[] = $GLOBALS['post'];
        } else {
            $list_posts[] = $GLOBALS['post'];
        }
        $i++;
    }
    rewind_posts();
}
?>

<?php get_template_part( 'template-parts/breadcrumb' ); ?>

<main class="dsi-archive" id="main-content">

<!-- Cabeçalho da categoria -->
<section class="dsi-cat-header">
    <div class="dsi-cat-header__text">
        <h1 class="dsi-cat-header__title">
            <?php echo esc_html( $term_name ); ?>
        </h1>
        <?php if ( ! $is_tag && $cat->description ) : ?>
            <p class="dsi-cat-header__desc"><?php echo esc_html( $cat->description ); ?></p>
        <?php elseif ( ! $is_tag ) : ?>
            <p class="dsi-cat-header__desc">O maior arquivo do site — anos escrevendo sobre cinema clássico, blockbusters e tudo que passa nas sessões da tarde.</p>
        <?php endif; ?>
    </div>
    <div class="dsi-cat-header__stats">
        <p class="dsi-cat-header__stat-label">Inventário</p>
        <?php $stat_items = [
            [ 'Posts publicados', number_format_i18n( $total ) ],
            [ 'Atualizado', 'hoje' ],
        ];
        foreach ( $stat_items as $i => [ $k, $v ] ) : ?>
            <div class="dsi-cat-header__stat-row<?php echo $i > 0 ? ' dsi-cat-header__stat-row--sep' : ''; ?>">
                <span class="dsi-cat-header__stat-key"><?php echo esc_html( $k ); ?></span>
                <span class="dsi-cat-header__stat-val"><?php echo esc_html( $v ); ?></span>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<!-- Filtro de tags — apenas tags da categoria com >10 posts -->
<?php
$cat_tags_filtered = [];
if ( ! $is_tag && ! empty( $cat->term_id ) ) {
    global $wpdb;
    $cat_post_ids = get_posts( [
        'category'         => $cat->term_id,
        'numberposts'      => 1000,
        'fields'           => 'ids',
        'post_status'      => 'publish',
        'ignore_sticky_posts' => true,
        'suppress_filters' => true,
    ] );
    if ( ! empty( $cat_post_ids ) ) {
        $ids_sql  = implode( ',', array_map( 'intval', $cat_post_ids ) );
        $cat_tags_filtered = $wpdb->get_results( "
            SELECT t.term_id, t.name, t.slug, COUNT(tr.object_id) AS cat_count
            FROM {$wpdb->term_relationships} tr
            JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
            JOIN {$wpdb->terms} t ON tt.term_id = t.term_id
            WHERE tt.taxonomy = 'post_tag'
              AND tr.object_id IN ($ids_sql)
            GROUP BY t.term_id
            HAVING cat_count >= 10
            ORDER BY cat_count DESC
            LIMIT 30
        " );
    }
}
?>
<?php if ( ! $is_tag && ! empty( $cat_tags_filtered ) ) : ?>
<section class="dsi-tag-filter">
    <div class="dsi-tag-filter__head">
        <span class="dsi-tag-filter__label">Filtrar por tag</span>
        <span class="dsi-rule dsi-rule--muted"></span>
    </div>
    <div class="dsi-tag-filter__chips">
        <?php foreach ( $cat_tags_filtered as $t_obj ) :
            $tag_link = get_term_link( (int) $t_obj->term_id, 'post_tag' );
        ?>
            <a href="<?php echo esc_url( is_wp_error( $tag_link ) ? '#' : $tag_link ); ?>"
               class="dsi-tag-chip">
                <?php echo esc_html( $t_obj->name ); ?>
                <span class="dsi-tag-chip__count"><?php echo esc_html( $t_obj->cat_count ); ?></span>
            </a>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<!-- Artigo em destaque -->
<?php if ( $lead_post ) :
    setup_postdata( $lead_post );
    $lead_thumb = get_the_post_thumbnail( $lead_post->ID, 'dsi-wide', [ 'class' => 'dsi-lead__img' ] );
?>
<section class="dsi-lead">
    <div class="dsi-lead__head">
        <span class="dsi-lead__eyebrow-badge">Em destaque</span>
        <span class="dsi-rule"></span>
    </div>
    <div class="dsi-lead__grid">
        <div class="dsi-lead__frame">
            <a href="<?php echo esc_url( get_permalink( $lead_post->ID ) ); ?>" tabindex="-1" aria-hidden="true">
                <?php if ( $lead_thumb ) : echo $lead_thumb; else : ?>
                    <div class="dsi-lead__frame-fallback" aria-hidden="true"></div>
                <?php endif; ?>
            </a>
        </div>
        <div class="dsi-lead__content">
            <h2 class="dsi-lead__title">
                <a href="<?php echo esc_url( get_permalink( $lead_post->ID ) ); ?>">
                    <?php echo esc_html( get_the_title( $lead_post->ID ) ); ?>
                </a>
            </h2>
            <p class="dsi-lead__excerpt"><?php echo dsi_excerpt( 200, $lead_post->ID ); ?></p>
            <div class="dsi-lead__byline">
                <a href="<?php echo esc_url( get_author_posts_url( $lead_post->post_author ) ); ?>" class="dsi-lead__avatar" tabindex="-1" aria-hidden="true">
                    <?php
                    $av = get_avatar( $lead_post->post_author, 56, '', '', [ 'class' => 'dsi-lead__avatar-img' ] );
                    if ( $av ) { echo $av; }
                    else { echo '<span>' . esc_html( dsi_author_initials( $lead_post->post_author ) ) . '</span>'; }
                    ?>
                </a>
                <div>
                    <p class="dsi-lead__author">
                        <a href="<?php echo esc_url( get_author_posts_url( $lead_post->post_author ) ); ?>">
                            <?php echo esc_html( get_the_author_meta( 'display_name', $lead_post->post_author ) ); ?>
                        </a>
                    </p>
                    <p class="dsi-lead__meta">
                        <?php
                        $pub = get_the_date( 'Y-m-d', $lead_post->ID );
                        $mod = get_the_modified_date( 'Y-m-d', $lead_post->ID );
                        $lead_updated = $mod && $mod !== $pub;
                        if ( $lead_updated ) : ?>
                            <time datetime="<?php echo esc_attr( get_the_modified_date( 'c', $lead_post->ID ) ); ?>"><?php echo get_the_modified_date( 'j M Y', $lead_post->ID ); ?></time>
                        <?php else : ?>
                            <time datetime="<?php echo esc_attr( get_the_date( 'c', $lead_post->ID ) ); ?>"><?php echo get_the_date( 'j M Y', $lead_post->ID ); ?></time>
                        <?php endif; ?>
                        · <span>⏱ <?php echo esc_html( dsi_read_time( $lead_post->ID ) ); ?> de leitura</span>
                    </p>
                </div>
            </div>
        </div>
    </div>
</section>
<?php wp_reset_postdata(); endif; ?>

<!-- Grade de artigos -->
<section class="dsi-archive-grid">
    <div class="dsi-archive-grid__head">
        <h2 class="dsi-archive-grid__heading">Recentes da seção</h2>
        <span class="dsi-rule"></span>
    </div>
    <?php $paged = max( 1, get_query_var( 'paged' ) ); $total_pages = $wp_query->max_num_pages; ?>
    <?php if ( $total_pages > 1 ) : ?>
    <p class="dsi-archive-grid__pager">
        Página <strong><?php echo $paged; ?></strong> de <strong><?php echo $total_pages; ?></strong>
    </p>
    <?php endif; ?>

    <!-- 3-column feature row -->
    <?php if ( count( $grid_posts ) > 0 ) : ?>
    <div class="dsi-archive-grid__cols">
        <?php foreach ( $grid_posts as $i => $p ) :
            $thumb = get_the_post_thumbnail( $p->ID, 'dsi-4x3', [ 'class' => 'dsi-archive-grid__img' ] );
        ?>
            <article class="dsi-archive-grid__item<?php echo $i > 0 ? ' dsi-archive-grid__item--ruled' : ''; ?>">
                <?php if ( $thumb ) : ?>
                    <div class="dsi-archive-grid__frame">
                        <a href="<?php echo esc_url( get_permalink( $p->ID ) ); ?>" tabindex="-1" aria-hidden="true"><?php echo $thumb; ?></a>
                    </div>
                <?php endif; ?>
                <h3 class="dsi-archive-grid__title">
                    <a href="<?php echo esc_url( get_permalink( $p->ID ) ); ?>"><?php echo esc_html( get_the_title( $p->ID ) ); ?></a>
                </h3>
                <p class="dsi-archive-grid__excerpt"><?php echo dsi_excerpt( 120, $p->ID ); ?></p>
                <div class="dsi-archive-grid__meta">
                    <span class="dsi-archive-grid__meta-author"><?php echo esc_html( get_the_author_meta( 'display_name', $p->post_author ) ); ?></span>
                    <span class="dsi-archive-grid__meta-date">
                        <?php
                        $pub2 = get_the_date( 'Y-m-d', $p->ID );
                        $mod2 = get_the_modified_date( 'Y-m-d', $p->ID );
                        if ( $mod2 && $mod2 !== $pub2 ) : ?>
                            <time datetime="<?php echo esc_attr( get_the_modified_date( 'c', $p->ID ) ); ?>"><?php echo get_the_modified_date( 'j M Y', $p->ID ); ?></time>
                        <?php else : ?>
                            <time datetime="<?php echo esc_attr( get_the_date( 'c', $p->ID ) ); ?>"><?php echo get_the_date( 'j M Y', $p->ID ); ?></time>
                        <?php endif; ?>
                        · ⏱ <?php echo esc_html( dsi_read_time( $p->ID ) ); ?>
                    </span>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Lista horizontal -->
    <?php if ( count( $list_posts ) > 0 ) : ?>
    <div class="dsi-archive-list">
        <div class="dsi-archive-list__intro">
            <span>↓ Continuando o arquivo</span>
            <span class="dsi-rule"></span>
            <span>em ordem cronológica</span>
        </div>
        <?php foreach ( $list_posts as $p ) :
            $thumb = get_the_post_thumbnail( $p->ID, 'dsi-square', [ 'class' => 'dsi-card-row__thumb-img' ] );
        ?>
            <article class="dsi-card-row">
                <div class="dsi-card-row__thumb">
                    <a href="<?php echo esc_url( get_permalink( $p->ID ) ); ?>" tabindex="-1" aria-hidden="true">
                        <?php if ( $thumb ) : echo $thumb; else : ?>
                            <div style="width:100%;height:100%;background:<?php echo esc_attr( $p->ID % 2 ? '#d8ccb2' : '#e8dfce' ); ?>;" aria-hidden="true"></div>
                        <?php endif; ?>
                    </a>
                </div>
                <div class="dsi-card-row__body">
                    <h4 class="dsi-card-row__title">
                        <a href="<?php echo esc_url( get_permalink( $p->ID ) ); ?>"><?php echo esc_html( get_the_title( $p->ID ) ); ?></a>
                    </h4>
                    <div class="dsi-card-row__byline">
                        <span class="dsi-card-row__author"><?php echo esc_html( get_the_author_meta( 'display_name', $p->post_author ) ); ?></span>
                        <span class="dsi-card-row__date">
                            <?php
                            $pub3 = get_the_date( 'Y-m-d', $p->ID );
                            $mod3 = get_the_modified_date( 'Y-m-d', $p->ID );
                            if ( $mod3 && $mod3 !== $pub3 ) : ?>
                                <time datetime="<?php echo esc_attr( get_the_modified_date( 'c', $p->ID ) ); ?>"><?php echo get_the_modified_date( 'j M Y', $p->ID ); ?></time>
                            <?php else : ?>
                                <time datetime="<?php echo esc_attr( get_the_date( 'c', $p->ID ) ); ?>"><?php echo get_the_date( 'j M Y', $p->ID ); ?></time>
                            <?php endif; ?>
                            · ⏱ <?php echo esc_html( dsi_read_time( $p->ID ) ); ?>
                        </span>
                    </div>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</section>

<!-- TV + Categorias (igual à home) -->
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

<?php dsi_pagination(); ?>

</main>

<?php get_template_part( 'template-parts/newsletter' ); ?>
<?php get_template_part( 'template-parts/footer-content' ); ?>
<?php get_footer(); ?>
