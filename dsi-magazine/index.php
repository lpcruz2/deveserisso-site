<?php
/**
 * Template fallback. Nunca deve aparecer num site funcionando —
 * home.php, single.php, category.php, search.php e author.php cobrem todos os casos.
 */
get_header();
?>
<main class="dsi-main">
	<div class="dsi-container">
		<?php if ( have_posts() ) :
			while ( have_posts() ) : the_post();
				get_template_part( 'template-parts/article-card' );
			endwhile;
		else : ?>
			<p>Nenhum conteúdo encontrado.</p>
		<?php endif; ?>
	</div>
</main>
<?php
get_footer();
