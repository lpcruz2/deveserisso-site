<?php
/**
 * page.php — Página estática (ex: Políticas de Privacidade)
 * Mesma estrutura visual de single.php (breadcrumb, título, TOC lateral,
 * tipografia .dsi-prose), sem os elementos específicos de post
 * (autor, data, tags, relacionados, comentários).
 */
get_header();

if ( ! have_posts() ) {
    get_footer();
    exit;
}

the_post();
?>

<?php get_template_part( 'template-parts/breadcrumb' ); ?>

<main class="dsi-single" id="main-content">

    <div class="dsi-single__top<?php echo has_post_thumbnail() ? ' dsi-single__top--has-image' : ''; ?>">

        <header class="dsi-single__header">
            <h1 class="dsi-single__title"><?php the_title(); ?></h1>
            <?php if ( has_excerpt() ) : ?>
                <p class="dsi-single__subtitle"><?php echo esc_html( get_the_excerpt() ); ?></p>
            <?php endif; ?>
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
        </div>
        <?php endif; ?>

    </div><!-- /.dsi-single__top -->

    <!-- Layout: conteúdo + TOC sidebar (mesmo componente e post-toc.js de single.php) -->
    <div class="dsi-single__layout">

        <aside class="dsi-toc" id="dsi-toc" aria-label="Índice da página">
            <p class="dsi-toc__heading">Índice</p>
            <nav class="dsi-toc__nav" id="dsi-toc-nav">
                <!-- Preenchido por post-toc.js -->
            </nav>
        </aside>

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

</main>

<?php get_template_part( 'template-parts/footer-content' ); ?>
<?php get_footer(); ?>
