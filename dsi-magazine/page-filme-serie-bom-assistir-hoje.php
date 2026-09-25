<?php
/**
 * page-filme-serie-bom-assistir-hoje.php — LP de mídia paga "Ache o filme pra hoje"
 * Selecionado automaticamente pela hierarquia de templates do WP para a
 * página com slug "filme-serie-bom-assistir-hoje" (page-{slug}.php vence
 * page.php). Sem get_header() de propósito: mídia paga não deve competir
 * com o menu principal do site (decisão do gestor, mockup aprovado em
 * 2026-09-25) — só um selo mínimo no topo. Rodapé é o real do site
 * (template-parts/footer-content.php), sem gate.
 *
 * O card de chat em si (cabeçalho, thread, form, disclaimer) não é
 * marcado aqui — fica todo a cargo de assets/js/bilheteiro-widget.js, que
 * detecta a div#dsi-bh-lp-slot abaixo e monta o widget dentro dela, já
 * aberto, em vez do balão flutuante padrão (ver modoLP no JS).
 */
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php wp_head(); ?>
<style>
.dsi-lp{width:100%;display:flex;flex-direction:column;align-items:center}
.dsi-lp__inner{width:100%;max-width:560px;box-sizing:border-box;padding:0 20px 64px}
.dsi-lp__topbar{display:flex;justify-content:space-between;align-items:center;padding:20px 0;border-bottom:1px solid #1d1a14}
.dsi-lp__selo{font-family:"DM Serif Display","Times New Roman",serif;font-size:20px;letter-spacing:-0.02em}
.dsi-lp__selo em{font-style:italic;color:#c2511d}
.dsi-lp__desde{font-family:"JetBrains Mono",monospace;font-size:10px;letter-spacing:.2em;text-transform:uppercase;color:#6a5f4d}
.dsi-lp__hero{text-align:center;padding:40px 0 28px;display:flex;flex-direction:column;align-items:center;gap:16px}
.dsi-lp__eyebrow{font-family:"JetBrains Mono",monospace;font-size:11px;letter-spacing:.28em;text-transform:uppercase;color:#c2511d}
.dsi-lp__titulo{margin:0;font-family:"DM Serif Display","Times New Roman",serif;font-weight:400;font-size:40px;line-height:1.08;letter-spacing:-0.02em;color:#1d1a14}
.dsi-lp__titulo em{font-style:italic;color:#c2511d}
.dsi-lp__sub{margin:0;font-size:16px;line-height:1.5;color:#4a4436;max-width:420px}
#dsi-bh-lp-slot{width:100%;min-height:260px}
.dsi-lp__como{padding:48px 0 16px;display:flex;flex-direction:column;gap:36px}
.dsi-lp__como-head{display:flex;flex-direction:column;gap:16px;text-align:center}
.dsi-lp__como-eyebrow{font-family:"JetBrains Mono",monospace;font-size:13px;font-weight:700;letter-spacing:.28em;text-transform:uppercase;color:#1d1a14}
.dsi-lp__como-titulo{margin:0;font-family:"DM Serif Display","Times New Roman",serif;font-weight:400;font-size:26px;line-height:1.3;color:#1d1a14}
.dsi-lp__como-texto{margin:0;font-size:14px;color:#4a4436;line-height:1.6}
.dsi-lp__stat{display:flex;align-items:center;justify-content:center;gap:14px}
.dsi-lp__stat-numero{font-family:"DM Serif Display","Times New Roman",serif;font-size:32px;color:#c2511d}
.dsi-lp__stat-texto{font-size:13px;color:#6a5f4d;line-height:1.3;max-width:160px}
.dsi-lp__passos{display:flex;flex-direction:column;gap:28px}
.dsi-lp__passo{display:flex;gap:18px;align-items:flex-start}
.dsi-lp__passo-num{flex-shrink:0;width:28px;height:28px;border-radius:50%;background:#c2511d;color:#fff;display:flex;align-items:center;justify-content:center;font-family:"JetBrains Mono",monospace;font-size:13px;font-weight:500}
.dsi-lp__passo-corpo{display:flex;flex-direction:column;gap:6px}
.dsi-lp__passo-titulo{font-weight:700;font-size:15px;color:#1d1a14}
.dsi-lp__passo-texto{font-size:14px;color:#4a4436;line-height:1.5}
/* Acima de ~900px o layout de 1 coluna centralizada (pensado pra trafego
   pago via celular) sobrava vazio nas laterais -- vira duas colunas:
   texto + chat lado a lado no hero, texto + passos lado a lado no
   "como funciona" (mesmo padrao de grid do .dsi-hero do tema, mas com
   proporcoes proprias porque o chat precisa de largura fixa, nao 1fr). */
@media(min-width:900px){
.dsi-lp__inner{max-width:1120px;padding:0 48px 96px}
.dsi-lp__topbar{padding:28px 0}
.dsi-lp__hero-grid{display:grid;grid-template-columns:1fr 440px;gap:56px;align-items:start;padding:64px 0 56px}
.dsi-lp__hero{text-align:left;align-items:flex-start;padding:0;gap:20px}
.dsi-lp__titulo{font-size:52px}
.dsi-lp__sub{max-width:440px}
#dsi-bh-lp-slot{position:sticky;top:24px}
.dsi-lp__como{padding:88px 0 24px}
.dsi-lp__como-grid{display:grid;grid-template-columns:1fr 1fr;gap:72px;align-items:start}
.dsi-lp__como-head{text-align:left;align-items:flex-start}
.dsi-lp__como-titulo{font-size:32px}
.dsi-lp__stat{justify-content:flex-start;margin-top:8px}
}
</style>
</head>
<body <?php body_class( 'dsi-lp-body' ); ?>>
<?php wp_body_open(); ?>

<main class="dsi-lp" id="main-content">
<div class="dsi-lp__inner">

	<div class="dsi-lp__topbar">
		<span class="dsi-lp__selo">deve<em>se</em>risso</span>
		<span class="dsi-lp__desde">Desde 2009</span>
	</div>

	<div class="dsi-lp__hero-grid">
		<div class="dsi-lp__hero">
			<span class="dsi-lp__eyebrow">Curadoria ao vivo</span>
			<h1 class="dsi-lp__titulo">Não sabe o que assistir <em>hoje</em>?</h1>
			<p class="dsi-lp__sub">O Curador é um assistente de IA que conversa com você e recomenda filmes e séries sob medida, direto do nosso catálogo. Sem rolar feed. Sem ficar horas decidindo.</p>
		</div>

		<div id="dsi-bh-lp-slot"></div>
	</div>

	<div class="dsi-lp__como">
		<div class="dsi-lp__como-grid">
		<div class="dsi-lp__como-intro">
		<div class="dsi-lp__como-head">
			<span class="dsi-lp__como-eyebrow">Como funciona</span>
			<h2 class="dsi-lp__como-titulo">Um crítico particular, pronto em 1 minuto</h2>
			<p class="dsi-lp__como-texto">Nada de algoritmo genérico. O Curador cruza o que você conta com resenhas de verdade, escritas por gente que assistiu, publicadas aqui desde 2009.</p>
		</div>

		<div class="dsi-lp__stat">
			<span class="dsi-lp__stat-numero">+3.000</span>
			<span class="dsi-lp__stat-texto">filmes e séries no nosso catálogo pra recomendar</span>
		</div>
		</div>

		<div class="dsi-lp__passos">
			<div class="dsi-lp__passo">
				<span class="dsi-lp__passo-num">1</span>
				<div class="dsi-lp__passo-corpo">
					<div class="dsi-lp__passo-titulo">Conte o que você curte</div>
					<div class="dsi-lp__passo-texto">Um gênero, um filme parecido ou um ator favorito já dizem muito sobre seu gosto.</div>
				</div>
			</div>
			<div class="dsi-lp__passo">
				<span class="dsi-lp__passo-num">2</span>
				<div class="dsi-lp__passo-corpo">
					<div class="dsi-lp__passo-titulo">O Curador refina com você</div>
					<div class="dsi-lp__passo-texto">No máximo 4 perguntas certeiras, nunca um questionário longo.</div>
				</div>
			</div>
			<div class="dsi-lp__passo">
				<span class="dsi-lp__passo-num">3</span>
				<div class="dsi-lp__passo-corpo">
					<div class="dsi-lp__passo-titulo">Receba recomendações com lastro</div>
					<div class="dsi-lp__passo-texto">Nota, sinopse e a resenha completa de quem realmente assistiu, na hora.</div>
				</div>
			</div>
		</div>
		</div>
	</div>

</div>
</main>

<?php get_template_part( 'template-parts/footer-content' ); ?>
<?php get_footer(); ?>
