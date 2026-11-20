<?php
/**
 * Bloco "Gostou? Veja parecidos" no fim da crítica de filme/série (2026-11-07).
 * Carrossel com 6 a 10 títulos com crítica no site (via /dsi/v1/recomendar-filme com a crítica como referência)
 * e o botão "Não gostei de nenhum", que abre o Curador pedindo outras sugestões, sem repetir os exibidos.
 * Só aparece em post com ficha técnica. Falha da API = bloco não aparece.
 */
$pid = get_queried_object_id();
$raw = (string) get_post_meta( $pid, '_dsi_dados_tecnicos_raw', true );
$dt  = $raw !== '' ? dsi_parse_dados_tecnicos( $raw ) : [];
if ( empty( $dt['titulo'] ) ) {
	return;
}

$chave = 'dsi_parecidos_v2_' . $pid;
$itens = get_transient( $chave );
if ( ! is_array( $itens ) ) {
	$itens = [];
	$req   = new WP_REST_Request( 'GET', '/dsi/v1/recomendar-filme' );
	$req->set_query_params( [ 'q' => $dt['titulo'], 'limite' => 12 ] );
	$resp = rest_do_request( $req );
	$data = $resp->is_error() ? [] : (array) $resp->get_data();
	foreach ( (array) ( $data['itemListElement'] ?? [] ) as $r ) {
		if ( ( $r['fonte'] ?? '' ) !== 'catalogo' || (int) ( $r['id'] ?? 0 ) === $pid || empty( $r['link'] ) ) {
			continue;
		}
		$itens[] = [
			'id'      => (int) $r['id'],
			'titulo'  => (string) ( $r['titulo'] ?? '' ),
			'ano'     => $r['ano'] ?? null,
			'poster'  => $r['poster'] ?? null,
			'sinopse' => (string) ( $r['sinopse'] ?? '' ),
			'link'    => (string) $r['link'],
		];
		if ( count( $itens ) >= 10 ) {
			break;
		}
	}
	set_transient( $chave, $itens, $itens ? DAY_IN_SECONDS : HOUR_IN_SECONDS );
}
if ( count( $itens ) < 3 ) {
	return;
}
$excluir = array_map( fn( array $i ): array => [ 'id' => $i['id'], 'fonte' => 'catalogo', 'titulo' => $i['titulo'] ], $itens );
?>
<style>
.dsi-parecidos{max-width:1100px;margin:48px auto;padding:0 20px}
.dsi-parecidos .dsi-related__bar{margin-bottom:24px}
.dsi-parecidos__card{flex:0 0 260px;width:260px;scroll-snap-align:start;display:flex;flex-direction:column}
.dsi-parecidos__thumb{display:block;overflow:hidden;background:#ebe3d2;border-radius:2px}
.dsi-parecidos__thumb img{display:block;width:100%;aspect-ratio:2/3;object-fit:cover;transition:transform .3s ease}
.dsi-parecidos__card:hover .dsi-parecidos__thumb img{transform:scale(1.03)}
.dsi-parecidos__ano{margin-top:14px;font-family:"JetBrains Mono",ui-monospace,monospace;font-size:11px;letter-spacing:.2em;text-transform:uppercase;color:#9c3f14}
.dsi-parecidos__nome{margin:6px 0 0;font-size:24px;line-height:1.15}
.dsi-parecidos__nome a{color:#1d1a14;text-decoration:none}
.dsi-parecidos__nome a:hover{color:#9c3f14}
.dsi-parecidos__sinopse{margin:10px 0 0;font-family:"Manrope",system-ui,sans-serif;font-size:15px;line-height:1.55;color:#4a4132;display:-webkit-box;-webkit-line-clamp:4;-webkit-box-orient:vertical;overflow:hidden}
.dsi-parecidos__nenhum{margin-top:20px;background:#9c3f14;color:#f4eee2;border:0;padding:16px 28px;font-family:"JetBrains Mono",ui-monospace,monospace;font-size:12px;letter-spacing:.25em;text-transform:uppercase;font-weight:700;cursor:pointer;border-radius:2px;transition:background-color .15s ease,color .15s ease}
.dsi-parecidos__nenhum:hover{background:#e8a83c;color:#1d1a14}
@media(max-width:768px){.dsi-parecidos__card{flex:0 0 220px;width:220px}.dsi-parecidos__nome{font-size:21px}}
</style>
<section class="dsi-parecidos" aria-label="Filmes parecidos">
	<div class="dsi-related__bar">
		<div class="dsi-section-head" style="margin-bottom:0;flex:1;">
			<h2 class="dsi-related__heading">Gostou? Veja parecidos</h2>
			<span class="dsi-rule"></span>
		</div>
		<div class="dsi-related__arrows" aria-label="Navegar carrossel">
			<button type="button" class="dsi-related__arrow" id="dsi-par-prev" aria-label="Anterior">←</button>
			<button type="button" class="dsi-related__arrow" id="dsi-par-next" aria-label="Próximo">→</button>
		</div>
	</div>
	<div class="dsi-related__grid" id="dsi-par-grid">
		<?php foreach ( $itens as $i ) : ?>
			<article class="dsi-parecidos__card">
				<a class="dsi-parecidos__thumb" href="<?php echo esc_url( $i['link'] ); ?>" data-dsi-parecido="<?php echo (int) $i['id']; ?>" tabindex="-1" aria-hidden="true">
					<?php if ( ! empty( $i['poster'] ) ) : ?>
						<img src="<?php echo esc_url( $i['poster'] ); ?>" alt="" loading="lazy" width="300" height="450">
					<?php endif; ?>
				</a>
				<?php if ( ! empty( $i['ano'] ) ) : ?><p class="dsi-parecidos__ano"><?php echo esc_html( (string) $i['ano'] ); ?></p><?php endif; ?>
				<h3 class="dsi-card__title dsi-parecidos__nome"><a href="<?php echo esc_url( $i['link'] ); ?>" data-dsi-parecido="<?php echo (int) $i['id']; ?>"><?php echo esc_html( $i['titulo'] ); ?></a></h3>
				<?php if ( $i['sinopse'] !== '' ) : ?><p class="dsi-parecidos__sinopse"><?php echo esc_html( $i['sinopse'] ); ?></p><?php endif; ?>
			</article>
		<?php endforeach; ?>
	</div>
	<button type="button" class="dsi-parecidos__nenhum" id="dsi-parecidos-nenhum">Não gostei de nenhum</button>
</section>
<script>
(function(){
var lista=<?php echo wp_json_encode( $excluir, JSON_UNESCAPED_UNICODE ); ?>;
function ev(n,p){window.dataLayer=window.dataLayer||[];p=p||{};p.event=n;window.dataLayer.push(p);}
var grid=document.getElementById('dsi-par-grid'),prev=document.getElementById('dsi-par-prev'),next=document.getElementById('dsi-par-next');
// Largura do passo medida só no clique (evita layout síncrono no carregamento, como no carrossel de relacionados).
function passo(){var c=grid.querySelector('.dsi-parecidos__card');return c?c.offsetWidth+24:280;}
prev.addEventListener('click',function(){grid.scrollBy({left:-passo(),behavior:'smooth'});});
next.addEventListener('click',function(){grid.scrollBy({left:passo(),behavior:'smooth'});});
function update(){prev.disabled=grid.scrollLeft<8;next.disabled=grid.scrollLeft+grid.clientWidth>=grid.scrollWidth-8;}
grid.addEventListener('scroll',update,{passive:true});
prev.disabled=true;
if('IntersectionObserver' in window){var io=new IntersectionObserver(function(e){if(e[0].isIntersecting){update();io.disconnect();}});io.observe(grid);}
grid.addEventListener('click',function(e){
var c=e.target.closest&&e.target.closest('[data-dsi-parecido]');
if(c)ev('parecido_clicado',{filme_id:c.getAttribute('data-dsi-parecido')});
});
document.getElementById('dsi-parecidos-nenhum').addEventListener('click',function(){
ev('parecidos_nenhum_clicado');
if(window.dsiBhOutrasSugestoes){window.dsiBhOutrasSugestoes(lista);return;}
var b=document.querySelector('.dsi-bh-bolha');if(b)b.click();
});
})();
</script>
