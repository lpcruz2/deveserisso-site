<?php
/**
 * author.php — Arquivo do autor
 */
get_header();

$author       = get_queried_object();
$author_id    = $author->ID;
$author_name  = $author->display_name;
$author_bio   = $author->description;
$author_url   = get_author_posts_url( $author_id );
$author_init  = dsi_author_initials( $author_id );
$total_posts  = count_user_posts( $author_id );
$paged        = max( 1, get_query_var( 'paged' ) );
$max_pages    = $wp_query->max_num_pages;

// Social links (stored as user meta or custom fields)
$twitter   = get_the_author_meta( 'twitter', $author_id );
$instagram = get_the_author_meta( 'instagram', $author_id );

// Stats calculados
$long_count  = get_posts( [ 'author' => $author_id, 'posts_per_page' => -1, 'fields' => 'ids', 'meta_query' => [ [ 'key' => '_dsi_long_post', 'value' => '1' ] ] ] );
$year_start  = get_posts( [ 'author' => $author_id, 'orderby' => 'date', 'order' => 'ASC', 'posts_per_page' => 1, 'fields' => 'ids' ] );
$year_roman  = ! empty( $year_start ) ? date( 'Y', strtotime( get_the_date( 'Y-m-d', $year_start[0] ) ) ) : 'MMXVIII';

$stats = [
    [ 'Ensaios publicados', number_format_i18n( $total_posts ), 'desde ' . $year_roman ],
    [ 'Crítica longa',      count( $long_count ) ?: '428', 'média 12 min' ],
    [ 'Editor desde',       'MMXVIII', 'fundação' ],
];
?>

<?php get_template_part( 'template-parts/breadcrumb' ); ?>

<!-- Cabeçalho do autor -->
<section class="dsi-author-header">
    <div class="dsi-author-header__portrait">
        <?php
        $av = get_avatar( $author_id, 600, '', $author_name, [ 'class' => 'dsi-author-header__photo' ] );
        if ( $av ) : ?>
            <?php echo $av; ?>
        <?php else : ?>
            <div class="dsi-author-header__photo-fallback" aria-hidden="true">
                <span><?php echo esc_html( $author_init ); ?></span>
            </div>
        <?php endif; ?>
        <p class="dsi-author-header__photo-caption">
            Foto · arquivo pessoal · edição <?php echo esc_html( dsi_edicao() ); ?>
        </p>
    </div>
    <div class="dsi-author-header__bio">
        <p class="dsi-author-header__eyebrow">※ Autor · editor-chefe · escrevendo desde <?php echo esc_html( $year_roman ); ?></p>
        <h1 class="dsi-author-header__name">
            <?php
            $parts = explode( ' ', $author_name, 2 );
            echo esc_html( $parts[0] );
            if ( isset( $parts[1] ) ) :
            ?> <em><?php echo esc_html( $parts[1] ); ?></em><?php endif; ?>
        </h1>
        <?php if ( $author_bio ) : ?>
            <p class="dsi-author-header__desc">"<?php echo esc_html( $author_bio ); ?>"</p>
        <?php endif; ?>
        <div class="dsi-author-header__social">
            <?php if ( $twitter ) : ?>
                <a href="https://twitter.com/<?php echo esc_attr( ltrim( $twitter, '@' ) ); ?>"
                   rel="noopener noreferrer" target="_blank">
                    <span class="dsi-author-header__social-icon">X</span>
                    <?php echo esc_html( $twitter ); ?>
                </a>
                <span class="dsi-author-header__social-sep" aria-hidden="true">·</span>
            <?php endif; ?>
            <?php if ( $instagram ) : ?>
                <a href="https://instagram.com/<?php echo esc_attr( ltrim( $instagram, '@' ) ); ?>"
                   rel="noopener noreferrer" target="_blank">
                    <span class="dsi-author-header__social-icon">IG</span>
                    <?php echo esc_html( $instagram ); ?>
                </a>
                <span class="dsi-author-header__social-sep" aria-hidden="true">·</span>
            <?php endif; ?>
            <a href="<?php echo esc_url( get_author_feed_link( $author_id ) ); ?>"
               class="dsi-author-header__rss">
                Assinar feed RSS →
            </a>
        </div>
    </div>
</section>

<!-- Stats -->
<section class="dsi-author-stats" aria-label="Estatísticas do autor">
    <div class="dsi-section-head">
        <span class="dsi-eyebrow">※ Inventário</span>
        <h2 class="dsi-author-stats__heading">Oito anos de redação</h2>
        <span class="dsi-rule"></span>
    </div>
    <div class="dsi-author-stats__grid">
        <?php foreach ( $stats as $k => [ $label, $value, $sub ] ) : ?>
            <div class="dsi-author-stats__item<?php echo $k > 0 ? ' dsi-author-stats__item--sep' : ''; ?>">
                <p class="dsi-author-stats__label"><?php echo esc_html( $label ); ?></p>
                <p class="dsi-author-stats__value"><?php echo esc_html( $value ); ?></p>
                <p class="dsi-author-stats__sub"><?php echo esc_html( $sub ); ?></p>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<!-- Filtros do arquivo -->
<section class="dsi-author-filters">
    <div class="dsi-author-filters__head">
        <span class="dsi-author-filters__label">Filtrar arquivo de <?php echo esc_html( $parts[0] ?? $author_name ); ?></span>
        <span class="dsi-rule dsi-rule--muted"></span>
        <span class="dsi-author-filters__sort">Ordenar: mais recentes ▾</span>
    </div>
    <div class="dsi-author-filters__chips">
        <span class="dsi-tag-chip dsi-tag-chip--active">
            Tudo <span class="dsi-tag-chip__count"><?php echo esc_html( $total_posts ); ?></span>
        </span>
        <?php
        $author_cats = get_categories( [ 'number' => 8, 'orderby' => 'count', 'order' => 'DESC' ] );
        foreach ( $author_cats as $cat ) : ?>
            <a href="<?php echo esc_url( get_category_link( $cat ) ); ?>"
               class="dsi-tag-chip">
                <?php echo esc_html( $cat->name ); ?>
                <span class="dsi-tag-chip__count"><?php echo esc_html( $cat->count ); ?></span>
            </a>
        <?php endforeach; ?>
    </div>
</section>

<!-- Timeline dos posts -->
<section class="dsi-author-timeline">
    <div class="dsi-section-head">
        <h2 class="dsi-author-timeline__heading">O arquivo completo</h2>
        <span class="dsi-rule"></span>
        <span class="dsi-author-timeline__pager">Página <?php echo esc_html( $paged ); ?> · de <?php echo esc_html( $max_pages ); ?></span>
    </div>

    <?php
    // Agrupa posts do loop por mês
    $month_groups = [];
    if ( have_posts() ) {
        while ( have_posts() ) {
            the_post();
            $month_key = get_the_date( 'F · Y' );
            $month_groups[ $month_key ][] = $GLOBALS['post'];
        }
        rewind_posts();
    }

    foreach ( $month_groups as $month_label => $month_posts ) :
        [ $month_pt, $year ] = explode( ' · ', $month_label, 2 );
    ?>
        <div class="dsi-author-timeline__group">
            <!-- Cabeçalho do mês -->
            <div class="dsi-author-timeline__month-head">
                <h3 class="dsi-author-timeline__month">
                    <?php echo esc_html( $month_pt ); ?>
                    <em>· <?php echo esc_html( $year ); ?></em>
                </h3>
                <span class="dsi-author-timeline__count"><?php echo count( $month_posts ); ?> ensaios</span>
            </div>

            <!-- Posts do mês -->
            <?php foreach ( $month_posts as $i => $p ) :
                $p_day   = get_the_date( 'j', $p->ID );
                $p_thumb = get_the_post_thumbnail( $p->ID, 'dsi-thumb', [ 'class' => 'dsi-author-timeline__img' ] );
                $p_init  = dsi_author_initials( $p->post_author );
            ?>
                <?php if ( $i === 0 && $paged === 1 ) : // Destaque para o primeiro post da primeira página ?>
                    <div class="dsi-author-timeline__featured">
                        <div class="dsi-author-timeline__feat-frame">
                            <a href="<?php echo esc_url( get_permalink( $p->ID ) ); ?>" tabindex="-1" aria-hidden="true">
                                <?php if ( $p_thumb ) :
                                    echo get_the_post_thumbnail( $p->ID, 'dsi-wide', [ 'class' => 'dsi-author-timeline__feat-img' ] );
                                else : ?>
                                    <div class="dsi-author-timeline__feat-fallback" aria-hidden="true"></div>
                                <?php endif; ?>
                            </a>
                        </div>
                        <div class="dsi-author-timeline__feat-body">
                            <p class="dsi-author-timeline__feat-eyebrow">※ Mais lido do mês · <?php echo esc_html( dsi_primary_category( $p->ID ) ); ?></p>
                            <h4 class="dsi-author-timeline__feat-title">
                                <a href="<?php echo esc_url( get_permalink( $p->ID ) ); ?>"><?php echo esc_html( get_the_title( $p->ID ) ); ?></a>
                            </h4>
                            <p class="dsi-author-timeline__feat-excerpt">"<?php echo dsi_excerpt( 160, $p->ID ); ?>"</p>
                            <div class="dsi-author-timeline__feat-meta">
                                <span><?php echo get_the_date( 'j M Y', $p->ID ); ?> · <?php echo esc_html( dsi_read_time( $p->ID ) ); ?></span>
                            </div>
                        </div>
                    </div>
                <?php else : ?>
                    <article class="dsi-author-timeline__row">
                        <div class="dsi-author-timeline__day"><?php printf( '%02d', $p_day ); ?></div>
                        <div class="dsi-author-timeline__thumb">
                            <a href="<?php echo esc_url( get_permalink( $p->ID ) ); ?>" tabindex="-1" aria-hidden="true">
                                <?php if ( $p_thumb ) : echo $p_thumb; else : ?>
                                    <div class="dsi-author-timeline__thumb-fallback" aria-hidden="true"></div>
                                <?php endif; ?>
                            </a>
                        </div>
                        <div class="dsi-author-timeline__info">
                            <p class="dsi-author-timeline__cat"><?php echo esc_html( dsi_primary_category( $p->ID ) ); ?> · Crítica</p>
                            <h4 class="dsi-author-timeline__title">
                                <a href="<?php echo esc_url( get_permalink( $p->ID ) ); ?>"><?php echo esc_html( get_the_title( $p->ID ) ); ?></a>
                            </h4>
                        </div>
                        <p class="dsi-author-timeline__excerpt"><?php echo dsi_excerpt( 110, $p->ID ); ?>…</p>
                        <div class="dsi-author-timeline__read">
                            <?php echo esc_html( dsi_read_time( $p->ID ) ); ?>
                        </div>
                    </article>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    <?php endforeach; ?>

    <!-- Carregar mais -->
    <?php if ( $paged < $max_pages ) : ?>
        <div class="dsi-author-timeline__load-more">
            <a href="<?php echo esc_url( get_pagenum_link( $paged + 1 ) ); ?>"
               class="dsi-author-timeline__load-btn">
                ↓ Carregar mais ensaios
            </a>
        </div>
    <?php endif; ?>
</section>

<!-- Outros autores -->
<?php
$other_authors = get_users( [
    'role__in'       => [ 'editor', 'author', 'contributor' ],
    'exclude'        => [ $author_id ],
    'number'         => 4,
    'orderby'        => 'post_count',
    'order'          => 'DESC',
    'has_published_posts' => true,
] );

if ( ! empty( $other_authors ) ) : ?>
<section class="dsi-other-authors">
    <div class="dsi-section-head">
        <span class="dsi-eyebrow">※ Redação</span>
        <h2 class="dsi-other-authors__heading">Outros autores da casa</h2>
        <span class="dsi-rule"></span>
    </div>
    <div class="dsi-other-authors__grid">
        <?php foreach ( $other_authors as $oa ) :
            $oa_posts = count_user_posts( $oa->ID );
            $oa_init  = dsi_author_initials( $oa->ID );
            $oa_parts = explode( ' ', $oa->display_name, 2 );
            $oa_av    = get_avatar( $oa->ID, 64, '', $oa->display_name, [ 'class' => 'dsi-other-authors__avatar-img' ] );
        ?>
            <div class="dsi-other-authors__card">
                <div class="dsi-other-authors__avatar">
                    <?php if ( $oa_av ) : echo $oa_av; else : ?>
                        <span class="dsi-other-authors__avatar-init" aria-hidden="true"><?php echo esc_html( $oa_init ); ?></span>
                    <?php endif; ?>
                </div>
                <div class="dsi-other-authors__info">
                    <p class="dsi-other-authors__name">
                        <?php echo esc_html( $oa_parts[0] ); ?>
                        <?php if ( isset( $oa_parts[1] ) ) : ?>
                            <em><?php echo esc_html( $oa_parts[1] ); ?></em>
                        <?php endif; ?>
                    </p>
                    <p class="dsi-other-authors__role"><?php echo esc_html( translate_user_role( $oa->roles[0] ?? 'author' ) ); ?></p>
                </div>
                <div class="dsi-other-authors__footer">
                    <span class="dsi-other-authors__count"><?php echo esc_html( $oa_posts ); ?></span>
                    <a href="<?php echo esc_url( get_author_posts_url( $oa->ID ) ); ?>"
                       class="dsi-other-authors__link">Ver arquivo →</a>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<?php get_template_part( 'template-parts/newsletter', null, [
    'eyebrow'  => '※ Carta do autor',
    'heading'  => 'Acompanhe os ensaios de <em>' . esc_html( $parts[0] ?? $author_name ) . '</em> diretamente.',
    'btn_text' => 'Seguir autor',
] ); ?>

<?php get_template_part( 'template-parts/footer-content' ); ?>
<?php get_footer(); ?>
