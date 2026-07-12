<?php
/**
 * breadcrumb.php — Trilha de navegação como pills
 * Em posts singulares: só Início + categoria (sem título do post)
 */
$items = [];
$items[] = [ 'label' => 'Início', 'url' => home_url( '/' ) ];

if ( is_category() ) {
    $items[] = [ 'label' => single_cat_title( '', false ), 'url' => '' ];
} elseif ( is_tag() ) {
    $items[] = [ 'label' => single_tag_title( '', false ), 'url' => '' ];
} elseif ( is_author() ) {
    $items[] = [ 'label' => get_the_author_meta( 'display_name', get_queried_object_id() ), 'url' => '' ];
} elseif ( is_singular() ) {
    $cat = get_the_category();
    if ( $cat ) {
        $items[] = [ 'label' => $cat[0]->name, 'url' => get_category_link( $cat[0]->term_id ) ];
    }
    // Título do post não incluído — só Início + categoria
}
?>
<nav class="dsi-breadcrumb" aria-label="Trilha de navegação">
    <?php foreach ( $items as $i => $item ) :
        $is_last = ( $i === count( $items ) - 1 );
        $has_url = ! empty( $item['url'] );
    ?>
        <?php if ( $has_url ) : ?>
            <a href="<?php echo esc_url( $item['url'] ); ?>"
               class="dsi-breadcrumb__pill">
                <?php echo esc_html( $item['label'] ); ?>
            </a>
        <?php else : ?>
            <span class="dsi-breadcrumb__pill<?php echo $is_last ? ' dsi-breadcrumb__pill--current' : ''; ?>"
                  <?php echo $is_last ? 'aria-current="page"' : ''; ?>>
                <?php echo esc_html( $item['label'] ); ?>
            </span>
        <?php endif; ?>
    <?php endforeach; ?>
</nav>
