<?php
/**
 * category-rapidinhas.php — Feed de Web Stories
 * Grade portrait 9:16, estilo feed Instagram, mobile-first.
 */
get_header();

$paged   = max( 1, (int) get_query_var( 'paged' ) );
$stories = new WP_Query( [
    'post_type'      => 'web-story',
    'posts_per_page' => 24,
    'post_status'    => 'publish',
    'paged'          => $paged,
    'orderby'        => 'date',
    'order'          => 'DESC',
] );
?>

<style>
/* ── Rapidinhas Feed ─────────────────────────────────── */
.dsi-rapidinhas {
    background: #f4eee2;
    min-height: 100vh;
    padding-bottom: 80px;
}

/* Cabeçalho da seção */
.dsi-rapidinhas__header {
    padding: 48px 64px 32px;
    border-bottom: 3px double #1d1a14;
    margin-bottom: 3px;
}
.dsi-rapidinhas__eyebrow {
    font-family: 'JetBrains Mono', monospace;
    font-size: 11px;
    letter-spacing: .2em;
    text-transform: uppercase;
    color: #6a5f4d;
    margin: 0 0 10px;
}
.dsi-rapidinhas__title {
    font-family: 'DM Serif Display', 'Times New Roman', serif;
    font-size: clamp( 48px, 7vw, 96px );
    line-height: .92;
    letter-spacing: -.04em;
    color: #1d1a14;
    font-weight: 400;
    margin: 0 0 8px;
}
.dsi-rapidinhas__desc {
    font-family: 'DM Serif Display', 'Times New Roman', serif;
    font-style: italic;
    font-size: 18px;
    color: #6a5f4d;
    margin: 0;
}

/* Grade — 3 colunas desktop, 2 mobile */
.dsi-rapidinhas__grid {
    display: grid;
    grid-template-columns: repeat( 3, 1fr );
    gap: 3px;
    width: 100%;
    max-width: 1440px;
    margin-inline: auto;
}

/* Card */
.dsi-story-card {
    display: block;
    position: relative;
    overflow: hidden;
    text-decoration: none;
    color: inherit;
    background: #1d1a14;
}
.dsi-story-card__frame {
    position: relative;
    aspect-ratio: 9 / 16;
    overflow: hidden;
}
.dsi-story-card__img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
    transition: transform .35s ease;
}
.dsi-story-card:hover .dsi-story-card__img,
.dsi-story-card:focus .dsi-story-card__img {
    transform: scale( 1.05 );
}
.dsi-story-card__placeholder {
    width: 100%;
    height: 100%;
    background: linear-gradient( 150deg, #2a2520 0%, #c2511d 100% );
}

/* Overlay com gradiente */
.dsi-story-card__overlay {
    position: absolute;
    inset: 0;
    display: flex;
    flex-direction: column;
    justify-content: flex-end;
    padding: 0 10px 14px;
    background: linear-gradient(
        to top,
        rgba( 0, 0, 0, .82 ) 0%,
        rgba( 0, 0, 0, .3  ) 40%,
        rgba( 0, 0, 0, 0   ) 65%
    );
    pointer-events: none;
}

/* Ícone play no topo */
.dsi-story-card__play {
    position: absolute;
    top: 12px;
    left: 12px;
    width: 28px;
    height: 28px;
    border: 1.5px solid rgba(255,255,255,.6);
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 10px;
    color: rgba(255,255,255,.8);
}

.dsi-story-card__title {
    font-family: 'DM Serif Display', 'Times New Roman', serif;
    font-size: clamp( 12px, 1.4vw, 17px );
    line-height: 1.2;
    color: #fff;
    font-weight: 400;
    margin: 0 0 4px;
    /* limita a 3 linhas */
    display: -webkit-box;
    -webkit-line-clamp: 3;
    -webkit-box-orient: vertical;
    overflow: hidden;
}
.dsi-story-card__date {
    font-family: 'JetBrains Mono', monospace;
    font-size: 9px;
    letter-spacing: .1em;
    color: rgba(255,255,255,.5);
    text-transform: uppercase;
}

/* Botão "Carregar mais" */
.dsi-rapidinhas__more {
    text-align: center;
    padding: 48px 20px;
}
.dsi-rapidinhas__load-more {
    display: inline-block;
    font-family: 'JetBrains Mono', monospace;
    font-size: 11px;
    letter-spacing: .2em;
    text-transform: uppercase;
    color: #1d1a14;
    border: 1px solid #1d1a14;
    padding: 14px 36px;
    text-decoration: none;
    transition: background .2s, color .2s;
}
.dsi-rapidinhas__load-more:hover {
    background: #1d1a14;
    color: #f4eee2;
}

/* Sem stories */
.dsi-rapidinhas__empty {
    padding: 80px 20px;
    text-align: center;
    font-family: 'DM Serif Display', serif;
    font-style: italic;
    font-size: 22px;
    color: #6a5f4d;
}

/* ── Responsivo ─────────────────────────────────────── */
@media ( max-width: 768px ) {
    .dsi-rapidinhas__header {
        padding: 32px 20px 24px;
    }
    .dsi-rapidinhas__grid {
        grid-template-columns: repeat( 2, 1fr );
        gap: 2px;
    }
    .dsi-story-card__title {
        font-size: 13px;
    }
}

@media ( max-width: 420px ) {
    .dsi-rapidinhas__grid {
        grid-template-columns: repeat( 3, 1fr );
        gap: 1px;
    }
    .dsi-story-card__title {
        font-size: 10px;
        -webkit-line-clamp: 2;
    }
    .dsi-story-card__overlay {
        padding: 0 6px 8px;
    }
}
</style>

<main id="main-content" class="dsi-rapidinhas">

    <!-- Cabeçalho -->
    <div class="dsi-rapidinhas__header">
        <p class="dsi-rapidinhas__eyebrow">★ Stories · Deveserisso</p>
        <h1 class="dsi-rapidinhas__title">Rapidinhas</h1>
        <p class="dsi-rapidinhas__desc">Cultura pop em formato de story</p>
    </div>

    <!-- Grade de stories -->
    <?php if ( $stories->have_posts() ) : ?>
    <div class="dsi-rapidinhas__grid">
        <?php while ( $stories->have_posts() ) : $stories->the_post(); ?>
        <?php
            $thumb     = get_the_post_thumbnail( null, 'large', [ 'class' => 'dsi-story-card__img', 'loading' => 'lazy', 'alt' => esc_attr( get_the_title() ) ] );
            $permalink = get_permalink();
            $title     = get_the_title();
            $date      = get_the_date( 'j M Y' );
        ?>
        <a href="<?php echo esc_url( $permalink ); ?>"
           class="dsi-story-card"
           aria-label="<?php echo esc_attr( $title ); ?>">
            <div class="dsi-story-card__frame">
                <?php if ( $thumb ) : ?>
                    <?php echo $thumb; ?>
                <?php else : ?>
                    <div class="dsi-story-card__placeholder" aria-hidden="true"></div>
                <?php endif; ?>
                <div class="dsi-story-card__overlay">
                    <span class="dsi-story-card__play" aria-hidden="true">▶</span>
                    <h2 class="dsi-story-card__title"><?php echo esc_html( $title ); ?></h2>
                    <span class="dsi-story-card__date"><?php echo esc_html( $date ); ?></span>
                </div>
            </div>
        </a>
        <?php endwhile; wp_reset_postdata(); ?>
    </div>

    <?php if ( $stories->max_num_pages > 1 ) : ?>
    <div class="dsi-rapidinhas__more">
        <?php
        $next_url = get_pagenum_link( $paged + 1 );
        if ( $paged < $stories->max_num_pages ) :
        ?>
        <a href="<?php echo esc_url( $next_url ); ?>" class="dsi-rapidinhas__load-more">
            Ver mais rapidinhas
        </a>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php else : ?>
    <p class="dsi-rapidinhas__empty">Nenhum story publicado ainda.</p>
    <?php endif; ?>

</main>

<?php get_footer(); ?>
