<?php
/**
 * Bloco "Gostou? Veja parecidos" no fim da crítica de filme/série (2026-11-07).
 * Três títulos com crítica no site (via /dsi/v1/recomendar-filme com a crítica como referência)
 * e o botão "Não gostei de nenhum", que abre o Curador pedindo outras sugestões, sem repetir estes três.
 * Só aparece em post com ficha técnica. Falha da API = bloco não aparece.
 */
$pid = get_queried_object_id();
$raw = (string) get_post_meta( $pid, '_dsi_dados_tecnicos_raw', true );
$dt  = $raw !== '' ? dsi_parse_dados_tecnicos( $raw ) : [];
if ( empty( $dt['titulo'] ) ) {
	return;
}

$chave = 'dsi_parecidos_' . $pid;
$itens = get_transient( $chave );
if ( ! is_array( $itens ) ) {
	$itens = [];
	$req   = new WP_REST_Request( 'GET', '/dsi/v1/recomendar-filme' );
	$req->set_query_params( [ 'q' => $dt['titulo'], 'limite' => 6 ] );
	$resp = rest_do_request( $req );
	$data = $resp->is_error() ? [] : (array) $resp->get_data();
	foreach ( (array) ( $data['itemListElement'] ?? [] ) as $r ) {
		if ( ( $r['fonte'] ?? '' ) !== 'catalogo' || (int) ( $r['id'] ?? 0 ) === $pid || empty( $r['link'] ) ) {
			continue;
		}
		$itens[] = [
			'id'     => (int) $r['id'],
			'titulo' => (string) ( $r['titulo'] ?? '' ),
			'ano'    => $r['ano'] ?? null,
			'poster' => $r['poster'] ?? null,
			'link'   => (string) $r['link'],
		];
		if ( count( $itens ) >= 3 ) {
			break;
		}
	}
	set_transient( $chave, $itens, $itens ? DAY_IN_SECONDS : HOUR_IN_SECONDS );
}
if ( count( $itens ) < 2 ) {
	return;
}
$excluir = array_map( fn( array $i ): array => [ 'id' => $i['id'], 'fonte' => 'catalogo', 'titulo' => $i['titulo'] ], $itens );
?>
<style>
.dsi-parecidos{max-width:1100px;margin:48px auto;padding:0 20px}
.dsi-parecidos__titulo{font-size:22px;font-weight:700;margin-bottom:16px;border-top:1px solid #1d1a14;padding-top:16px}
.dsi-parecidos__grade{display:grid;grid-template-columns:repeat(3,1fr);gap:16px}
.dsi-parecidos__card{display:block}
.dsi-parecidos__card img{width:100%;aspect-ratio:2/3;object-fit:cover;background:#ebe3d2;border-radius:2px}
.dsi-parecidos__nome{margin-top:8px;font-weight:600;line-height:1.25}
.dsi-parecidos__ano{color:#6a5f4d;font-size:12px}
.dsi-parecidos__nenhum{margin-top:20px;background:#9c3f14;color:#f4eee2;border:0;padding:12px 20px;font-weight:700;cursor:pointer;border-radius:2px}
.dsi-parecidos__nenhum:hover{background:#1d1a14}
@media(max-width:600px){.dsi-parecidos__grade{gap:10px}.dsi-parecidos__nome{font-size:13px}}
</style>
<section class="dsi-parecidos" aria-label="Filmes parecidos">
	<h2 class="dsi-parecidos__titulo">Gostou? Veja parecidos</h2>
	<div class="dsi-parecidos__grade">
		<?php foreach ( $itens as $i ) : ?>
			<a class="dsi-parecidos__card" href="<?php echo esc_url( $i['link'] ); ?>" data-dsi-parecido="<?php echo (int) $i['id']; ?>">
				<?php if ( ! empty( $i['poster'] ) ) : ?>
					<img src="<?php echo esc_url( $i['poster'] ); ?>" alt="" loading="lazy">
				<?php endif; ?>
				<p class="dsi-parecidos__nome"><?php echo esc_html( $i['titulo'] ); ?></p>
				<?php if ( ! empty( $i['ano'] ) ) : ?><p class="dsi-parecidos__ano"><?php echo esc_html( (string) $i['ano'] ); ?></p><?php endif; ?>
			</a>
		<?php endforeach; ?>
	</div>
	<button type="button" class="dsi-parecidos__nenhum" id="dsi-parecidos-nenhum">Não gostei de nenhum</button>
</section>
<script>
(function(){
var lista=<?php echo wp_json_encode( $excluir, JSON_UNESCAPED_UNICODE ); ?>;
function ev(n,p){window.dataLayer=window.dataLayer||[];p=p||{};p.event=n;window.dataLayer.push(p);}
document.addEventListener('click',function(e){
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
