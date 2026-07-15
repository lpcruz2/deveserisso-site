<?php
/**
 * footer-content.php — Rodapé editorial reutilizável
 * Incluído por cada template logo antes de get_footer().
 */
$year = date( 'Y' );
?>
<footer class="dsi-footer" role="contentinfo">
    <div class="dsi-footer__inner">

        <!-- Coluna da marca -->
        <div class="dsi-footer__brand">
            <a href="<?php echo esc_url( home_url( '/' ) ); ?>"
               class="dsi-footer__wordmark"
               aria-label="<?php bloginfo( 'name' ); ?>">
                Deve<em>ser</em>isso
            </a>
            <p class="dsi-footer__desc">
                Crítica, listas e recomendações sobre o que realmente vale a pena assistir.
                Desde 2018, feito por gente que ama cinema, séries, livros e TV.
            </p>
        </div>

        <!-- Coluna: Sobre nós (Menu do WordPress) -->
        <div class="dsi-footer__col">
            <h3 class="dsi-footer__col-title">Sobre nós</h3>
            <?php
            wp_nav_menu( [
                'theme_location' => 'footer',
                'container'      => false,
                'menu_class'     => 'dsi-footer__col-list',
                'depth'          => 1,
                'fallback_cb'    => false,
            ] );
            ?>
        </div>

        <!-- Coluna: Nas redes (Links customizados) -->
        <div class="dsi-footer__col">
            <h3 class="dsi-footer__col-title">Nas redes</h3>
            <ul class="dsi-footer__col-list">
                <li>
                    <a href="https://x.com/deveserisso" target="_blank" rel="noopener noreferrer">
                        Twitter
                    </a>
                </li>
                <li>
                    <a href="https://www.instagram.com/deveserisso.dsi/" target="_blank" rel="noopener noreferrer">
                        Instagram
                    </a>
                </li>
            </ul>
        </div>

    </div><!-- /.dsi-footer__inner -->

    <div class="dsi-footer__bottom">
        <span>© <?php echo esc_html( $year ); ?> Deveserisso · Todos os direitos reservados</span>
        <span style="display:flex;align-items:center;gap:8px;">
            <span style="font-size:9px;letter-spacing:.05em;text-transform:none;">Este produto utiliza a API do TMDB, mas não é endossado nem certificado pelo TMDB.</span>
            <a href="https://www.themoviedb.org" target="_blank" rel="noopener" aria-label="The Movie Database (TMDB)">
                <img src="https://www.themoviedb.org/assets/2/v4/logos/v2/blue_short-8e7b30f73a4020692ccca9c88bafe5dcb6f8a62a4c6bc55cd9ba82bb2cd95f6c.svg"
                     alt="TMDB" width="60" height="8"
                     style="max-width:60px;height:auto;display:inline-block;">
            </a>
        </span>
    </div>
</footer>
