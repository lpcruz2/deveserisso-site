<?php
/**
 * single.php — Post individual
 * Inclui: drop-cap via CSS :first-letter, TOC com IntersectionObserver, seção de posts relacionados
 */
get_header();

if ( ! have_posts() ) {
    get_footer();
    exit;
}

the_post();

$post_id     = get_the_ID();
$author_id   = get_the_author_meta( 'ID' );
$category    = dsi_primary_category();
$read_time   = dsi_read_time();
$author_init = dsi_author_initials();
$tags        = get_the_tags();
$author_url  = get_author_posts_url( $author_id );
$author_bio  = get_the_author_meta( 'description' );
$author_name = get_the_author_meta( 'display_name' );

// Posts relacionados (mesma categoria, exceto o atual)
$related = new WP_Query( [
    'posts_per_page'      => 6,
    'post__not_in'        => [ $post_id ],
    'category_name'       => $category,
    'post_status'         => 'publish',
    'ignore_sticky_posts' => true,
] );
?>

<?php get_template_part( 'template-parts/breadcrumb' ); ?>

<main class="dsi-single" id="main-content">

    <!-- Topo: cabeçalho + imagem (grid 2 col no desktop, igual ao hero da home) -->
    <div class="dsi-single__top<?php echo has_post_thumbnail() ? ' dsi-single__top--has-image' : ''; ?>">

        <header class="dsi-single__header">
            <h1 class="dsi-single__title"><?php the_title(); ?></h1>
            <?php if ( has_excerpt() ) : ?>
                <p class="dsi-single__subtitle"><?php echo esc_html( get_the_excerpt() ); ?></p>
            <?php endif; ?>
            <div class="dsi-single__byline">
                <a href="<?php echo esc_url( $author_url ); ?>" class="dsi-single__avatar" aria-label="<?php echo esc_attr( $author_name ); ?>">
                    <?php
                    $avatar = get_avatar( $author_id, 88, '', $author_name, [ 'class' => 'dsi-single__avatar-img' ] );
                    if ( $avatar ) {
                        echo $avatar;
                    } else {
                        echo '<span class="dsi-single__avatar-init" aria-hidden="true">' . esc_html( $author_init ) . '</span>';
                    }
                    ?>
                </a>
                <div>
                    <a href="<?php echo esc_url( $author_url ); ?>" class="dsi-single__author"><?php echo esc_html( $author_name ); ?></a>
                    <p class="dsi-single__meta">
                        <?php
                        $s_pub = get_the_date( 'Y-m-d' );
                        $s_mod = get_the_modified_date( 'Y-m-d' );
                        $s_updated = $s_mod && $s_mod !== $s_pub;
                        ?>
                        <span class="dsi-single__dates">
                            <?php if ( $s_updated ) : ?>
                                <time datetime="<?php echo esc_attr( get_the_modified_date( 'c' ) ); ?>"><?php echo get_the_modified_date( 'j M Y' ); ?></time>
                            <?php else : ?>
                                <time datetime="<?php echo esc_attr( get_the_date( 'c' ) ); ?>"><?php echo get_the_date( 'j M Y' ); ?></time>
                            <?php endif; ?>
                        </span>
                        <span class="dsi-single__readtime">⏱ <?php echo esc_html( $read_time ); ?> de leitura</span>
                    </p>
                </div>
            </div>
        </header>

        <?php if ( has_post_thumbnail() ) : ?>
        <div class="dsi-single__hero-frame">
            <?php
            echo wp_get_attachment_image(
                get_post_thumbnail_id(),
                'large',
                false,
                [
                    'alt'           => esc_attr( get_the_title() ),
                    'class'         => 'dsi-single__hero-img',
                    'loading'       => 'eager',
                    'fetchpriority' => 'high',
                ]
            );
            ?>
            <?php
            $caption = get_the_post_thumbnail_caption();
            if ( $caption ) : ?>
                <figcaption class="dsi-single__hero-caption"><?php echo esc_html( $caption ); ?></figcaption>
            <?php endif; ?>
        </div>
        <?php endif; ?>

    </div><!-- /.dsi-single__top -->

    <!-- Layout: conteúdo + TOC sidebar -->
    <div class="dsi-single__layout">

        <!-- TOC (gerado via JS IntersectionObserver) -->
        <aside class="dsi-toc" id="dsi-toc" aria-label="Índice do artigo">
            <p class="dsi-toc__heading">Índice</p>
            <nav class="dsi-toc__nav" id="dsi-toc-nav">
                <!-- Preenchido por post-toc.js -->
            </nav>
        </aside>

        <!-- Conteúdo do post -->
        <article class="dsi-prose" id="dsi-post-content">
            <?php the_content(); ?>

            <?php
            wp_link_pages( [
                'before' => '<div class="dsi-single__pages"><span class="dsi-single__pages-label">Páginas:</span>',
                'after'  => '</div>',
            ] );
            ?>
        </article>

    </div><!-- /.dsi-single__layout -->

    <!-- Tags do post -->
    <?php if ( $tags ) : ?>
        <div class="dsi-single__tags">
            <span class="dsi-single__tags-label">Tags:</span>
            <?php foreach ( $tags as $t_obj ) : ?>
                <a href="<?php echo esc_url( get_tag_link( $t_obj ) ); ?>"
                   class="dsi-tag"><?php echo esc_html( $t_obj->name ); ?></a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- Assinatura do autor -->
    <section class="dsi-single__author-card" aria-label="Sobre o autor">
        <a href="<?php echo esc_url( $author_url ); ?>" class="dsi-single__author-avatar">
            <?php
            $av = get_avatar( $author_id, 120, '', $author_name, [ 'class' => 'dsi-single__author-avatar-img' ] );
            if ( $av ) {
                echo $av;
            } else {
                echo '<span class="dsi-single__author-avatar-init" aria-hidden="true">' . esc_html( $author_init ) . '</span>';
            }
            ?>
        </a>
        <div class="dsi-single__author-info">
            <p class="dsi-single__author-eyebrow">Escrito por</p>
            <a href="<?php echo esc_url( $author_url ); ?>" class="dsi-single__author-name"><?php echo esc_html( $author_name ); ?></a>
            <?php if ( $author_bio ) : ?>
                <p class="dsi-single__author-bio"><?php echo esc_html( $author_bio ); ?></p>
            <?php endif; ?>
            <a href="<?php echo esc_url( $author_url ); ?>" class="dsi-single__author-link">
                Ver todos os artigos de <?php echo esc_html( $author_name ); ?> →
            </a>
        </div>
    </section>

</main>

<!-- Comentários — antes dos relacionados -->
<?php comments_template(); ?>

<!-- Relacionados — carrossel -->
<?php if ( $related->have_posts() ) : ?>
<section class="dsi-related" aria-label="Artigos relacionados">
    <div class="dsi-related__bar">
        <div class="dsi-section-head" style="margin-bottom:0;flex:1;">
            <h2 class="dsi-related__heading">Para continuar lendo</h2>
            <span class="dsi-rule"></span>
        </div>
        <div class="dsi-related__arrows" aria-label="Navegar carrossel">
            <button class="dsi-related__arrow" id="dsi-rel-prev" aria-label="Anterior">←</button>
            <button class="dsi-related__arrow" id="dsi-rel-next" aria-label="Próximo">→</button>
        </div>
    </div>
    <div class="dsi-related__grid" id="dsi-related-grid">
        <?php while ( $related->have_posts() ) : $related->the_post(); ?>
            <article class="dsi-card dsi-card--carousel">
                <div class="dsi-card__thumb dsi-card__thumb--square">
                    <a href="<?php the_permalink(); ?>" tabindex="-1" aria-hidden="true">
                        <?php if ( has_post_thumbnail() ) : ?>
                            <?php the_post_thumbnail( 'dsi-square', [ 'class' => 'dsi-card__thumb-img' ] ); ?>
                        <?php else : ?>
                            <div class="dsi-card__thumb-fallback" aria-hidden="true"></div>
                        <?php endif; ?>
                    </a>
                </div>
                <p class="dsi-card__eyebrow">
                    <a href="<?php echo esc_url( dsi_primary_category_url() ); ?>"><?php echo esc_html( dsi_primary_category() ); ?></a>
                </p>
                <h3 class="dsi-card__title">
                    <a href="<?php the_permalink(); ?>"><?php the_title(); ?></a>
                </h3>
                <div class="dsi-card__meta">
                    <div class="dsi-card__avatar" aria-hidden="true"><?php echo esc_html( dsi_author_initials() ); ?></div>
                    <div>
                        <p class="dsi-card__author-name"><?php the_author(); ?></p>
                        <p class="dsi-card__byline"><?php echo get_the_date( 'j M Y' ); ?> · <?php echo esc_html( dsi_read_time() ); ?></p>
                    </div>
                </div>
            </article>
        <?php endwhile; wp_reset_postdata(); ?>
    </div>
</section>
<script>
(function(){
    var grid = document.getElementById('dsi-related-grid');
    var prev = document.getElementById('dsi-rel-prev');
    var next = document.getElementById('dsi-rel-next');
    if (!grid || !prev || !next) return;
    var step = grid.querySelector('.dsi-card--carousel');
    var w = step ? step.offsetWidth + 24 : 280;
    prev.addEventListener('click', function(){ grid.scrollBy({ left: -w, behavior: 'smooth' }); });
    next.addEventListener('click', function(){ grid.scrollBy({ left:  w, behavior: 'smooth' }); });
    function update(){
        prev.disabled = grid.scrollLeft < 8;
        next.disabled = grid.scrollLeft + grid.clientWidth >= grid.scrollWidth - 8;
    }
    grid.addEventListener('scroll', update, { passive: true });
    update();
})();
</script>
<?php endif; ?>

<?php get_template_part( 'template-parts/newsletter' ); ?>
<?php get_template_part( 'template-parts/footer-content' ); ?>
<?php get_footer(); ?>
