<?php
/**
 * masthead-inner.php — Masthead compacto para páginas internas
 * (category, single, author, 404)
 */
$nav_items = [
    'Crítica'         => home_url( '/category/critica/' ),
    'Listas'          => home_url( '/category/listas/' ),
    'Streaming'       => home_url( '/category/streaming/' ),
    'Livros'          => home_url( '/category/livros/' ),
    'Programação TV'  => home_url( '/category/programacao-tv/' ),
    'Oscar'           => home_url( '/category/oscar/' ),
    'Entrevistas'     => home_url( '/category/entrevistas/' ),
    'Rapidinhas'      => home_url( '/category/rapidinhas/' ),
];

$current_cat_slug = is_category() ? get_queried_object()->slug : '';
$svg_search = '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>';
?>
<header class="dsi-masthead dsi-masthead--inner" role="banner">

    <!-- Wordmark compacto -->
    <div class="dsi-masthead__brand dsi-masthead__brand--compact">
        <a href="<?php echo esc_url( home_url( '/' ) ); ?>" class="dsi-masthead__wordmark" aria-label="<?php bloginfo( 'name' ); ?>">
            Deve<em>ser</em>isso
        </a>
    </div>

    <!-- Nav -->
    <nav class="dsi-masthead__nav" aria-label="Menu principal">
        <?php
        $dsi_nav_args = dsi_nav_menu_args( 'dsi-masthead__nav-list' );
        if ( $dsi_nav_args ) {
            wp_nav_menu( $dsi_nav_args );
        } else {
            echo '<ul class="dsi-masthead__nav-list">';
            foreach ( $nav_items as $label => $url ) {
                $slug   = sanitize_title( $label );
                $active = ( $current_cat_slug && $current_cat_slug === $slug );
                echo '<li class="dsi-masthead__nav-item"><a href="' . esc_url( $url ) . '" class="dsi-masthead__nav-link' . ( $active ? ' dsi-masthead__nav-link--active' : '' ) . '">' . esc_html( $label ) . '</a></li>';
            }
            echo '</ul>';
        }
        ?>

        <!-- Busca expansível desktop -->
        <div class="dsi-masthead__search-wrap">
            <button type="button" class="dsi-masthead__search-toggle" aria-label="Buscar" aria-expanded="false">
                Buscar <span aria-hidden="true"><?php echo $svg_search; ?></span>
            </button>
            <form class="dsi-masthead__search-form" role="search" method="get"
                  action="<?php echo esc_url( home_url( '/' ) ); ?>" aria-hidden="true">
                <input type="search" name="s" class="dsi-masthead__search-input"
                       placeholder="Buscar…" autocomplete="off">
                <button type="submit" class="dsi-masthead__search-submit" aria-label="Buscar">
                    <?php echo $svg_search; ?>
                </button>
            </form>
        </div>
    </nav>

    <!-- Mobile hamburger -->
    <button class="dsi-masthead__burger" aria-label="Abrir menu" aria-expanded="false" aria-controls="dsi-mobile-nav-inner">
        <span></span><span></span><span></span>
    </button>
    <div class="dsi-mobile-nav" id="dsi-mobile-nav-inner" hidden>
        <?php
        $dsi_mob_args = dsi_nav_menu_args( 'dsi-mobile-nav__list' );
        if ( $dsi_mob_args ) {
            wp_nav_menu( $dsi_mob_args );
        } else {
            echo '<ul class="dsi-mobile-nav__list">';
            foreach ( $nav_items as $label => $url ) {
                echo '<li><a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a></li>';
            }
            echo '</ul>';
        }
        ?>
        <!-- Busca mobile -->
        <div class="dsi-mobile-nav__search">
            <form role="search" method="get" action="<?php echo esc_url( home_url( '/' ) ); ?>">
                <input type="search" name="s" class="dsi-mobile-nav__search-input"
                       placeholder="Buscar no site…" autocomplete="off">
                <button type="submit" class="dsi-mobile-nav__search-btn" aria-label="Buscar">
                    <?php echo $svg_search; ?>
                </button>
            </form>
        </div>
    </div>

</header>
<script>
(function(){
    var btn = document.querySelector('.dsi-masthead--inner .dsi-masthead__search-toggle');
    var frm = document.querySelector('.dsi-masthead--inner .dsi-masthead__search-form');
    if (!btn || !frm) return;
    btn.addEventListener('click', function() {
        var open = frm.classList.toggle('is-open');
        btn.setAttribute('aria-expanded', open);
        frm.setAttribute('aria-hidden', !open);
        if (open) { var inp = frm.querySelector('input'); if (inp) inp.focus(); }
    });
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && frm.classList.contains('is-open')) {
            frm.classList.remove('is-open');
            btn.setAttribute('aria-expanded', 'false');
            frm.setAttribute('aria-hidden', 'true');
            btn.focus();
        }
    });
    document.addEventListener('click', function(e) {
        if (frm.classList.contains('is-open') && !frm.contains(e.target) && !btn.contains(e.target)) {
            frm.classList.remove('is-open');
            btn.setAttribute('aria-expanded', 'false');
            frm.setAttribute('aria-hidden', 'true');
        }
    });
})();
</script>
