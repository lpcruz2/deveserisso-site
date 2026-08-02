<?php
/**
 * header.php — DSI Magazine
 * Chamado por todos os templates via get_header().
 * Detecta o contexto e renderiza o masthead correto.
 */
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">

<!-- Critical CSS — above the fold -->
<style>
:root{--c-bg:#f4eee2;--c-bg-2:#ebe3d2;--c-fg:#1d1a14;--c-fg-2:#6a5f4d;--c-rule:#1d1a14;--c-rule-2:#bdb29c;--c-accent:#c2511d;--c-accent-2:#e8a83c}*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}html{scroll-behavior:smooth}body{background-color:#f4eee2;color:#1d1a14;font-family:"Manrope",system-ui,sans-serif;font-size:14px;line-height:1.55;overflow-x:hidden}img,video{display:block;max-width:100%;height:auto}a{color:inherit;text-decoration:none}h1,h2,h3,h4,h5,h6{font-weight:600;line-height:.92}.dsi-container{width:100%;max-width:1440px;margin-inline:auto;padding-inline:64px}@media(max-width:768px){.dsi-container{padding-inline:20px}}.dsi-hero{display:grid;grid-template-columns:1fr 1fr;gap:40px;align-items:center;padding:60px 0}.dsi-hero__poster-frame{width:100%;aspect-ratio:3/2;overflow:hidden;border-radius:4px}.dsi-hero__img{width:100%;height:100%;object-fit:cover}.dsi-section-head{display:flex;align-items:baseline;gap:16px;margin-bottom:40px}.dsi-rule{flex:1;height:1px;background-color:#1d1a14}header{background:#f4eee2;padding:20px 0}nav{display:flex;gap:20px}
</style>

<!-- Preload critical web fonts -->
<link rel="preload" href="<?php echo get_theme_file_uri( 'assets/fonts/dm-serif-normal.woff2' ); ?>" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="<?php echo get_theme_file_uri( 'assets/fonts/manrope.woff2' ); ?>" as="font" type="font/woff2" crossorigin>
<link rel="preload" href="<?php echo get_theme_file_uri( 'assets/fonts/jetbrains-mono.woff2' ); ?>" as="font" type="font/woff2" crossorigin>

<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<a href="#main-content" class="dsi-skip-link">Pular para o conteúdo principal</a>

<?php
/**
 * Determina qual layout de masthead renderizar:
 * - home:   masthead-home.php  (wordmark 140px + data + nav completa)
 * - search: masthead-search.php (só wordmark compacto, sem nav — busca ocupa o espaço)
 * - inner:  masthead-inner.php  (wordmark 64px + nav completa)
 */
if ( is_home() || is_front_page() ) {
    get_template_part( 'template-parts/masthead', 'home' );
} elseif ( is_search() ) {
    get_template_part( 'template-parts/masthead', 'search' );
} else {
    get_template_part( 'template-parts/masthead', 'inner' );
}
