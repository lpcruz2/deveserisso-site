<?php
/**
 * Coluna lateral da home (portal): "O que assistir hoje" (botão + listas mais lidas).
 * Listas = as de maior clique no Google (GSC, 90 dias até 2026-10-21), sem a página de mímica e sem as de filmes por letra.
 * Para trocar sem mexer no código: option `dsi_home_listas` (array de slugs). A programação de TV e o "Explorar por tema"
 * ficam na seção própria da home (dsi-tv-cats).
 */
$slugs = get_option( 'dsi_home_listas', [
    'melhores-filmes-de-comedia-besteirol-na-amazon-prime-video',
    'filmes-baseados-em-fatos-reais-para-assistir-hoje-na-netflix',
    'filmes-para-chorar',
    'filmes-parecidos-com-american-pie-que-voce-tem-de-assistir',
    'melhores-filmes-de-luta-netflix-que-voce-tem-de-assistir',
] );
$listas = [];
foreach ( (array) $slugs as $slug ) {
    $lp = get_page_by_path( $slug, OBJECT, 'post' );
    if ( $lp && $lp->post_status === 'publish' ) $listas[] = $lp;
}
?>
<aside class="dsi-portal-side" aria-label="Lateral">
    <section class="dsi-portal-box dsi-portal-box--watch">
        <a class="dsi-portal-side__cta" href="<?php echo esc_url( home_url( '/filme-serie-bom-assistir-hoje/' ) ); ?>">
            <span class="dsi-portal-side__cta-kicker">Sem ideia do que ver?</span>
            <span class="dsi-portal-side__cta-title">O que assistir hoje</span>
        </a>
        <?php if ( $listas ) : ?>
        <ul class="dsi-portal-topics">
            <?php foreach ( $listas as $lp ) : ?>
                <li><a href="<?php echo esc_url( get_permalink( $lp->ID ) ); ?>"><?php echo esc_html( get_the_title( $lp->ID ) ); ?></a></li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>
    </section>
</aside>
