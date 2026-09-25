/**
 * Widget flutuante do Bilheteiro, acessível de qualquer página do site.
 * Reaproveita 100% o backend da Jornada do Espectador (mesmos endpoints
 * REST usados pelo CineQuiz-deveserisso) -- aqui só muda a apresentação:
 * um chat flutuante em vez da jornada com Corredor/Emoção. Como nenhum
 * minigame roda antes, contexto_minigames sempre marca os dois como
 * pulados; o bilheteiro já sabe perguntar gênero/emoção direto
 * (dsi_bilheteiro_proxima_pergunta em functions.php, seção 31).
 */
( function () {
	'use strict';

	var BILHETEIRO_CHAT_ENDPOINT = 'https://deveserisso.com.br/wp-json/dsi/v1/bilheteiro-chat';
	var CINEQUIZ_ENDPOINT        = 'https://deveserisso.com.br/wp-json/dsi/v1/recomendar-filme';
	var FEEDBACK_ENDPOINT        = 'https://deveserisso.com.br/wp-json/dsi/v1/recomendacao-feedback';
	var PERGUNTAR_ENDPOINT       = 'https://deveserisso.com.br/wp-json/dsi/v1/bilheteiro-perguntar';
	var NEWSLETTER_ENDPOINT      = 'https://deveserisso.com.br/wp-json/dsi/v1/bilheteiro-newsletter';
	// A partir de quantas mensagens do usuario (qualquer tipo, preferencia
	// ou pergunta) o bot oferece cadastro na newsletter (2026-09-24, pedido
	// do gestor: "a pessoa pode falar infinitamente... mas não ganho nada
	// com isso"). Ver talvezPedirEmail() mais abaixo.
	// Descido de 10 pra 8 (2026-09-25, pedido do gestor apos a analise dos
	// ultimos 3 dias): nenhuma conversa real passou de 8 mensagens nesse
	// periodo, entao 10 nunca chegava a disparar de verdade.
	var LIMITE_MENSAGENS_PEDIR_EMAIL = 8;
	// A partir de quantas mensagens (sem email capturado) o bot avisa que a
	// conversa vai precisar reiniciar (2026-09-24, pedido do gestor).
	var LIMITE_MENSAGENS_AVISO = 15;
	var EMAIL_REGEX = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

	function gerarSessaoId() {
		if ( window.crypto && crypto.randomUUID ) return crypto.randomUUID();
		return 'sessao-' + Date.now() + '-' + Math.random().toString( 36 ).slice( 2 );
	}

	function valorOuVazio( v ) {
		return ( v === null || v === undefined || v === '__sem_preferencia__' ) ? '' : v;
	}

	function escapeHtml( s ) {
		var d = document.createElement( 'div' );
		d.textContent = s || '';
		return d.innerHTML;
	}

	// Persistencia em localStorage (decisao do gestor 2026-09-21: "queria
	// salvar no local storage para a pessoa sempre ter as recomendacoes").
	// So client-side, nenhuma mudanca no backend -- guarda so preferencia de
	// filme (sem IP/PII, mesma regra do resto do projeto), isolado por
	// origem (so deveserisso.com.br le). Expira em 7 dias pra nao acumular
	// pra sempre; try/catch em tudo porque localStorage pode falhar
	// (navegacao anonima, storage bloqueado).
	var STORAGE_KEY = 'dsi_bh_estado_v1';
	var STORAGE_TTL_MS = 7 * 24 * 60 * 60 * 1000;

	function carregarEstadoSalvo() {
		try {
			var bruto = localStorage.getItem( STORAGE_KEY );
			if ( ! bruto ) return null;
			var dados = JSON.parse( bruto );
			if ( ! dados || ! dados.salvoEm || ( Date.now() - dados.salvoEm ) > STORAGE_TTL_MS ) return null;
			return dados;
		} catch ( e ) {
			return null;
		}
	}
	function salvarEstadoFn( dados ) {
		try {
			localStorage.setItem( STORAGE_KEY, JSON.stringify( dados ) );
		} catch ( e ) { /* localStorage indisponivel -- degrada pra sessao sem persistencia */ }
	}
	function limparEstadoSalvo() {
		try {
			localStorage.removeItem( STORAGE_KEY );
		} catch ( e ) {}
	}

	// Flag durável e SEPARADA do estado da conversa (2026-09-24) -- guarda
	// so um booleano (nunca o email em si, mesma regra de nao guardar PII no
	// client), pra nao pedir de novo em visitas futuras nem depois de
	// "reiniciar" (que limpa STORAGE_KEY mas nao deve apagar isso).
	var STORAGE_KEY_EMAIL = 'dsi_bh_email_capturado_v1';
	function emailJaCapturado() {
		try {
			return localStorage.getItem( STORAGE_KEY_EMAIL ) === '1';
		} catch ( e ) {
			return false;
		}
	}
	function marcarEmailCapturado() {
		try {
			localStorage.setItem( STORAGE_KEY_EMAIL, '1' );
		} catch ( e ) {}
	}

	// Ate 2026-09-22 o site rodava GTM e isso empurrava {event:eventName,...}
	// pro dataLayer (convencao de "evento customizado" que so o GTM sabe
	// escutar). Trocado por GA4 via gtag.js direto em 2026-09-23 (ver secao
	// 36 de functions.php) -- sem GTM no meio, aquele push virava so um
	// objeto solto no array, nunca processado por nada (achado do gestor
	// 2026-09-25: "nao temos mais GTM"). gtag.js define window.gtag() como
	// wrapper de dataLayer.push(arguments) -- a chamada correta pra um
	// evento customizado em GA4 e gtag('event', nome, params), nao mais um
	// push manual no array. Sem isso nao existe NENHUM jeito de saber quantos
	// filmes foram clicados: o log do bilheteiro (wp_dsi_bilheteiro_log) so
	// guarda quais filmes foram recomendados e o like/dislike agregado da
	// rodada, nunca qual link especifico a pessoa abriu.
	function track( eventName, params ) {
		if ( typeof window.gtag !== 'function' ) return;
		window.gtag( 'event', eventName, params || {} );
	}

	function injetarEstilos() {
		if ( document.getElementById( 'dsi-bh-estilos' ) ) return;
		var style = document.createElement( 'style' );
		style.id = 'dsi-bh-estilos';
		style.textContent =
			'.dsi-bh-bolha{position:fixed;right:20px;bottom:20px;z-index:9999;height:52px;' +
			'border-radius:999px;border:none;background:#c2511d;color:#fff;font-size:14px;font-weight:600;' +
			'cursor:pointer;box-shadow:0 4px 16px rgba(0,0,0,.25);display:flex;align-items:center;' +
			'justify-content:center;gap:8px;padding:0 18px;font-family:Manrope,system-ui,sans-serif;}' +
			'.dsi-bh-bolha:hover{background:#a8461a;}' +
			'.dsi-bh-bolha .dsi-bh-bolha-emoji{font-size:20px;}' +
			/* .dsi-bh-painel comeca escondido (display:none) -- so o JS mostra,
			   trocando pra classe .aberto, ao clicar na bolha (achado do gestor
			   2026-09-20: o atributo HTML "hidden" sozinho nao bastava, porque
			   um display:flex direto no seletor da classe tem a mesma
			   especificidade e um CSS de autor sempre vence o estilo nativo do
			   navegador pro atributo hidden -- o painel ficava visivel na tela
			   mesmo com hidden=true por dentro). */
			'.dsi-bh-painel{position:fixed;right:20px;bottom:82px;z-index:9999;width:340px;max-width:calc(100vw - 32px);' +
			'height:480px;max-height:calc(100vh - 140px);background:#f4eee2;color:#1d1a14;border-radius:10px;' +
			'box-shadow:0 12px 40px rgba(0,0,0,.3);display:none;flex-direction:column;overflow:hidden;' +
			'font-family:Manrope,system-ui,sans-serif;font-size:13px;}' +
			'.dsi-bh-painel.aberto{display:flex;}' +
			/* Botao de expandir (2026-09-22, pedido do gestor: "falta um botao
			   na web pra expandir o chat") -- so faz sentido em tela grande, o
			   celular ja fica em tela cheia sozinho (media query mais abaixo). */
			'.dsi-bh-painel.expandido{width:520px;height:min(720px,calc(100vh - 100px));}' +
			'.dsi-bh-cabecalho{background:#1d1a14;color:#e8a83c;padding:12px 14px;display:flex;' +
			'align-items:center;justify-content:space-between;font-weight:600;}' +
			'.dsi-bh-fechar{background:none;border:none;color:#f4eee2;font-size:20px;cursor:pointer;line-height:1;}' +
			'.dsi-bh-reiniciar,.dsi-bh-expandir{background:none;border:none;color:#e8a83c;font-size:16px;cursor:pointer;' +
			'line-height:1;margin-right:8px;}' +
			'.dsi-bh-thread{flex:1;overflow-y:auto;padding:12px;display:flex;flex-direction:column;gap:8px;}' +
			'.dsi-bh-msg{max-width:85%;padding:8px 11px;border-radius:10px;line-height:1.4;}' +
			'.dsi-bh-msg--bot{background:#ebe3d2;align-self:flex-start;border-bottom-left-radius:2px;}' +
			'.dsi-bh-msg--user{background:#c2511d;color:#fff;align-self:flex-end;border-bottom-right-radius:2px;' +
			'white-space:pre-wrap;}' +
			/* Boxes de indicacao (2026-09-22, pedido do gestor: "os boxes
			   precisam pegar a tela toda do chat") -- em vez de herdar o
			   limite de 85% do balao de texto comum, essas mensagens usam a
			   largura inteira do thread. Classe aplicada so nas mensagens que
			   carregam .dsi-bh-filmes (ver renderFilmes/renderSemResenha). */
			'.dsi-bh-msg--filmes{max-width:100%;align-self:stretch;background:none;padding:0;}' +
			/* Espaco extra so entre o box de avaliacao (fim do bloco com
			   resenha) e o bloco externo seguinte (2026-09-23, pedido do
			   gestor: "so dar mais espaco, nao precisa aumentar o bloco") --
			   margin-top em cima do gap:8px que ja existe entre mensagens,
			   sem mexer no padding/tamanho do proprio box. */
			'.dsi-bh-msg--externo{margin-top:16px;}' +
			'.dsi-bh-digitando{opacity:.6;}' +
			'.dsi-bh-form{display:flex;gap:6px;padding:10px;border-top:1px solid #bdb29c;}' +
			'.dsi-bh-input{flex:1;padding:8px 10px;border:1px solid #bdb29c;border-radius:6px;font:inherit;' +
			'line-height:1.4;resize:none;}' +
			'.dsi-bh-enviar{background:#c2511d;color:#fff;border:none;border-radius:6px;padding:8px 12px;' +
			'font-weight:600;cursor:pointer;}' +
			/* Disclaimer fixo (2026-09-24, pedido do gestor: "tipo do
			   Gemini") -- sempre visivel embaixo do campo de digitar, nao
			   uma mensagem que soma ao historico. */
			'.dsi-bh-disclaimer{margin:0;padding:0 10px 8px;font-size:10px;line-height:1.3;' +
			'color:#8a7f68;text-align:center;}' +
			'.dsi-bh-filmes{display:flex;flex-direction:column;gap:8px;margin-top:4px;}' +
			/* Boxes de filme mais altos (2026-09-23, pedido do gestor: "mais
			   espaco pro texto sobre o filme e ver resenha") -- padding
			   6px -> 12px so pra dar folga, sem mexer no tamanho do poster. */
			'.dsi-bh-filme{display:flex;gap:8px;text-decoration:none;color:inherit;background:#fff;' +
			'border-radius:8px;padding:12px;box-shadow:0 1px 4px rgba(0,0,0,.12);}' +
			/* Poster cresce junto com o texto (2026-09-23, pedido do gestor:
			   "boxes precisam mostrar pelo menos 3 linhas do resumo") --
			   46x68 ficava pequeno demais e desproporcional ao lado de um
			   bloco de texto mais alto (titulo + 3 linhas + "Ver resenha").
			   56x84 mantem a proporcao 2:3 de poster de verdade. */
			'.dsi-bh-filme img{width:56px;height:84px;object-fit:cover;border-radius:4px;flex-shrink:0;}' +
			'.dsi-bh-filme-sem-poster{width:56px;height:84px;background:#ebe3d2;border-radius:4px;' +
			'display:flex;align-items:center;justify-content:center;font-size:24px;flex-shrink:0;}' +
			/* Fontes da indicacao aumentadas (2026-09-22/23, pedido do
			   gestor: "a letra da indicacao esta muito pequena", depois
			   "titulo do filme, sinopse e ver resenha podem ser um pouco
			   maiores"). */
			'.dsi-bh-filme-info strong{display:block;font-size:14px;margin-bottom:2px;}' +
			/* 3 linhas de sinopse (2026-09-23, pedido do gestor), nao mais 2. */
			'.dsi-bh-filme-info p{margin:0;font-size:13px;color:#6a5f4d;' +
			'display:-webkit-box;-webkit-line-clamp:3;-webkit-box-orient:vertical;overflow:hidden;}' +
			/* Titulos de secao (2026-09-23, pedido do gestor: "Minhas
			   indicacoes pra voce" antes da lista com resenha, "Voce tambem
			   pode gostar" antes da externa) -- em negrito, maior que os
			   cards, pra marcar visualmente a troca de bloco no chat. Fonte
			   subiu mais uma vez (15px -> 17px) a pedido do gestor, e o
			   espaco pro texto ate os boxes abaixo tambem (8px -> 14px). */
			'.dsi-bh-secao-titulo{margin:0 0 4px;font-size:17px;font-weight:700;}' +
			/* Texto de apoio (2026-09-23, pedido do gestor) abaixo do
			   titulo "Minhas indicacoes para voce" -- explica de onde vem a
			   recomendacao antes da lista de fato. */
			'.dsi-bh-secao-apoio{margin:0 0 14px;font-size:12px;color:#6a5f4d;}' +
			/* Fonte maior (2026-09-23, pedido do gestor: "precisa ter fonte
			   maior") -- 12px -> 14px. */
			'.dsi-bh-sem-resenha-titulo{margin:2px 0 8px;font-size:14px;color:#6a5f4d;font-weight:600;}' +
			'.dsi-bh-ver-resenha{display:inline-block;margin-top:2px;font-size:12px;font-weight:600;color:#c2511d;}' +
			/* Link inline do aviso de limite de mensagens (2026-09-24) --
			   parece texto sublinhado, nao botao de navegador. */
			'.dsi-bh-link-reiniciar{background:none;border:none;padding:0;margin:0;' +
			'color:#c2511d;text-decoration:underline;font:inherit;font-weight:700;cursor:pointer;}' +
			/* Avaliacao (2026-09-22, pedido do gestor: "precisam ter mais
			   espaco pra pessoas verem que eles existem") -- titulo em cima,
			   botoes numa linha so mas ocupando a largura inteira do box
			   (flex:1 em cada um), padding e fonte maiores que o resto do
			   card pra virar alvo de toque obvio, nao um detalhe pequeno.
			   2026-09-23: virou um box de verdade (fundo/borda/padding
			   proprios, nao so texto solto) pra ocupar mais espaco na tela
			   e o titulo "Gostou das indicacoes?" ficou negrito e maior. */
			'.dsi-bh-feedback{margin-top:12px;background:#fff;border-radius:8px;padding:14px;' +
			'box-shadow:0 1px 4px rgba(0,0,0,.12);}' +
			'.dsi-bh-feedback-titulo{display:block;margin-bottom:10px;font-size:15px;font-weight:700;}' +
			'.dsi-bh-feedback-botoes{display:flex;gap:8px;}' +
			'.dsi-bh-fb{background:#ebe3d2;border:1px solid #bdb29c;border-radius:6px;padding:18px 8px;' +
			'cursor:pointer;font-size:15px;font-weight:600;flex:1;}' +
			/* No celular o painel flutuante pequeno fica ilegivel quando o
			   teclado abre pra digitar (achado do gestor 2026-09-21: "fica
			   dificil ler o que foi dito no chat") -- no lugar de um balao no
			   canto, ocupa a tela inteira. */
			'@media(max-width:480px){.dsi-bh-painel.aberto{position:fixed;inset:0;width:100%;height:100%;' +
			'max-width:100%;max-height:100%;border-radius:0;}}' +
			/* Botoes "quebra-gelo" (2026-09-25, LP de midia paga "Ache o
			   filme pra hoje") -- generos oferecidos como atalho pra
			   primeira resposta, no lugar de so texto livre. So aparecem
			   quando addQuebraGelo() e chamado (modo LP), nunca no balao
			   flutuante padrao. */
			'.dsi-bh-quebra-gelo-wrap{display:flex;flex-wrap:wrap;gap:8px;margin-top:2px;}' +
			'.dsi-bh-quebra-gelo{background:#ebe3d2;border:1px dashed #a89a7d;border-radius:999px;' +
			'padding:8px 14px;font:inherit;font-size:13px;font-weight:600;color:#1d1a14;cursor:pointer;}' +
			'.dsi-bh-quebra-gelo:hover{background:#e3d9c2;}' +
			/* Modo LP: widget nasce embutido dentro de #dsi-bh-lp-slot (ver
			   page-filme-serie-bom-assistir-hoje.php), nao flutuante --
			   sobrescreve o posicionamento fixo do balao padrao. */
			'.dsi-bh-widget--lp .dsi-bh-bolha{display:none;}' +
			'.dsi-bh-widget--lp .dsi-bh-fechar,.dsi-bh-widget--lp .dsi-bh-expandir{display:none;}' +
			'.dsi-bh-widget--lp .dsi-bh-painel{position:static;display:flex;width:100%;max-width:100%;' +
			'height:auto;border-radius:14px;box-shadow:0 16px 40px rgba(29,26,20,.18);}' +
			'.dsi-bh-widget--lp .dsi-bh-thread{max-height:420px;}' +
			/* Com cards de filme na tela, 420px ficava apertado -- cresce
			   ate caber na janela (o card e sticky no desktop, entao nao
			   pode passar da altura da tela). */
			'.dsi-bh-widget--lp .dsi-bh-painel.com-filmes .dsi-bh-thread{max-height:max(420px,calc(100vh - 200px));}' +
			'@media(max-width:480px){.dsi-bh-widget--lp .dsi-bh-painel.aberto{position:static!important;' +
			'inset:auto!important;width:100%!important;height:auto!important;max-width:100%!important;' +
			'max-height:none!important;border-radius:14px!important;}}';
		document.head.appendChild( style );
	}

	function montarWidget() {
		injetarEstilos();

		// Modo LP (2026-09-25): pagina de midia paga marca onde o chat deve
		// nascer -- ja embutido e aberto na dobra, sem balao flutuante.
		// Ver page-filme-serie-bom-assistir-hoje.php.
		var lpSlot = document.getElementById( 'dsi-bh-lp-slot' );
		var modoLP = !! lpSlot;

		var raiz = document.createElement( 'div' );
		raiz.className = 'dsi-bh-widget' + ( modoLP ? ' dsi-bh-widget--lp' : '' );
		raiz.innerHTML =
			'<button class="dsi-bh-bolha" type="button" aria-expanded="false">' +
				'<span class="dsi-bh-bolha-emoji" aria-hidden="true">🎬</span> O que assistir hoje?' +
			'</button>' +
			'<div class="dsi-bh-painel">' +
				'<div class="dsi-bh-cabecalho">' +
					'<span>' + ( modoLP ? '🎬 O Deveserisso te ajuda!' : 'Curadoria Deveserisso!' ) + '</span>' +
					'<span>' +
						'<button class="dsi-bh-reiniciar" type="button" title="Começar uma nova busca">↺</button>' +
						'<button class="dsi-bh-expandir" type="button" title="Expandir chat">⤢</button>' +
						'<button class="dsi-bh-fechar" type="button" aria-label="Fechar">×</button>' +
					'</span>' +
				'</div>' +
				'<div class="dsi-bh-thread"></div>' +
				'<form class="dsi-bh-form">' +
					'<textarea class="dsi-bh-input" rows="' + ( modoLP ? 2 : 1 ) + '" placeholder="Digite sua resposta..." ' +
						'autocomplete="off" aria-label="Digite sua resposta"></textarea>' +
					'<button type="submit" class="dsi-bh-enviar">Enviar</button>' +
				'</form>' +
				'<p class="dsi-bh-disclaimer">O Curador é uma IA e pode cometer erros. Considere checar informações importantes.</p>' +
			'</div>';

		// A LP ja vem com uma copia estatica do chat renderizada no HTML
		// (page-filme-serie-bom-assistir-hoje.php), pra aparecer antes deste
		// script carregar. Troca pela versao de verdade no mesmo frame
		// (conteudo identico, sem piscar), mas sem perder o que a pessoa
		// ja estava digitando nela.
		var textoPreCarga = '', focoPreCarga = false;
		if ( modoLP ) {
			var inputPreCarga = lpSlot.querySelector( '.dsi-bh-input' );
			if ( inputPreCarga ) {
				textoPreCarga = inputPreCarga.value;
				focoPreCarga  = document.activeElement === inputPreCarga;
			}
			lpSlot.innerHTML = '';
			window.dsiBhMontado = true;
		}
		( lpSlot || document.body ).appendChild( raiz );

		var bolha     = raiz.querySelector( '.dsi-bh-bolha' );
		var painel    = raiz.querySelector( '.dsi-bh-painel' );
		var fechar    = raiz.querySelector( '.dsi-bh-fechar' );
		var reiniciarBtn = raiz.querySelector( '.dsi-bh-reiniciar' );
		var expandirBtn  = raiz.querySelector( '.dsi-bh-expandir' );
		var thread    = raiz.querySelector( '.dsi-bh-thread' );
		var form      = raiz.querySelector( '.dsi-bh-form' );
		var input     = raiz.querySelector( '.dsi-bh-input' );

		var iniciado        = false;
		var sessaoId        = gerarSessaoId();
		var estado          = {};
		var perguntasFeitas = 0;
		var rodadaAtual     = 1;
		var excluirFilmes   = [];
		var historico       = []; // replay da conversa pra restaurar do localStorage
		// Ultimo bloco com resenha do deveserisso renderizado -- mantem a
		// tela fixada nele em vez do fim da conversa (achado do gestor
		// 2026-09-23: no celular, abrir o widget com uma conversa salva
		// sempre mostrava a recomendacao externa primeiro, porque
		// ajustarParaTeclado() forcava scroll pro fim toda vez que rodava,
		// inclusive logo depois do restaurar() e a cada evento de teclado).
		// Zerada quando uma nova mensagem do usuario entra (renderUser) e
		// setada de novo quando um novo bloco com resenha aparece
		// (renderFilmes) -- ver ajustarParaTeclado abaixo.
		var ancoraComResenha = null;
		// Ids (no catalogo) dos filmes mostrados na ultima leva de
		// recomendacoes + flag "ja recomendei, proxima mensagem e pergunta
		// sobre o que mostrei, nao nova preferencia" (achado do relatorio
		// semanal 2026-09-23: "Todas essas produções possuem o De Niro?"
		// caia direto na extracao de preferencia de novo, sem responder
		// nada). Ver perguntarSobreRecomendacoes() e o branch em
		// form.addEventListener('submit', ...) abaixo.
		var ultimosItensRecomendados = [];
		var perguntasEncerradas      = false;
		// Captura de email (2026-09-24): conta toda mensagem do usuario
		// (preferencia ou pergunta), independente do rate limit por IP --
		// ao bater o limite, oferece newsletter uma unica vez por conversa
		// (pedidoEmailMostrado); aguardandoEmail marca que a PROXIMA
		// mensagem deve ser tratada como tentativa de email, nao roteada
		// pro chat/pergunta normal. Ver talvezPedirEmail()/capturarEmail().
		var mensagensEnviadas   = 0;
		var pedidoEmailMostrado = false;
		var aguardandoEmail     = false;
		// Aviso de limite (2026-09-24, pedido do gestor): quem ignora o
		// pedido de email e continua batendo papo sem teto nenhum, alem do
		// rate limit por IP, nao gera valor nenhum (custa chamada a LLM de
		// graca). Aos LIMITE_MENSAGENS_AVISO, avisa uma unica vez e oferece
		// reiniciar -- nunca reseta sozinho, so oferece o link.
		var avisoLimiteMostrado = false;

		function salvarEstado() {
			salvarEstadoFn( {
				sessaoId: sessaoId,
				estado: estado,
				perguntasFeitas: perguntasFeitas,
				mensagensEnviadas: mensagensEnviadas,
				pedidoEmailMostrado: pedidoEmailMostrado,
				aguardandoEmail: aguardandoEmail,
				avisoLimiteMostrado: avisoLimiteMostrado,
				rodadaAtual: rodadaAtual,
				excluirFilmes: excluirFilmes,
				historico: historico,
				ultimosItensRecomendados: ultimosItensRecomendados,
				perguntasEncerradas: perguntasEncerradas,
				salvoEm: Date.now()
			} );
		}

		// No celular, "height:100%"/vh do CSS nao acompanha o teclado --
		// varios navegadores so encolhem o "visual viewport" (window.
		// visualViewport), nao o layout viewport que 100% usa como
		// referencia (achado do gestor 2026-09-21: "quando a tela encolhe
		// [...] o chat deveria acompanhar"). Ajusta altura/topo do painel
		// via JS seguindo o visualViewport de verdade, só no celular e só
		// com o painel aberto -- limpa o estilo inline no resto dos casos
		// pra nao atrapalhar o CSS normal (painel pequeno no canto).
		function limparAjusteTeclado() {
			painel.style.height = '';
			painel.style.top = '';
		}
		// El.offsetTop sozinho da a posicao relativa ao offsetParent mais
		// proximo (aqui, .dsi-bh-painel, ja que .dsi-bh-thread nao tem
		// position:relative) -- nao a posicao dentro do conteudo rolavel do
		// thread. Usar isso direto em thread.scrollTop desalinhava o alvo
		// (achado do gestor 2026-09-23: o texto da secao nao ficava no
		// comecinho da lista depois de rolar). getBoundingClientRect e
		// independente de quem e o offsetParent -- sempre da a posicao real
		// do elemento dentro do scroll do thread.
		function offsetDentroDoThread( el ) {
			return el.getBoundingClientRect().top - thread.getBoundingClientRect().top + thread.scrollTop;
		}
		function ajustarParaTeclado() {
			// No modo LP o painel e estatico dentro da pagina, nao um balao
			// fixo -- o ajuste de teclado (pensado pro balao ocupar a tela
			// inteira) nao se aplica aqui.
			if ( modoLP ) { limparAjusteTeclado(); return; }
			if ( ! window.visualViewport || window.innerWidth > 480 || ! painel.classList.contains( 'aberto' ) ) {
				limparAjusteTeclado();
				return;
			}
			var vv = window.visualViewport;
			painel.style.height = vv.height + 'px';
			painel.style.top = vv.offsetTop + 'px';
			thread.scrollTop = ancoraComResenha ? offsetDentroDoThread( ancoraComResenha ) : thread.scrollHeight;
		}
		if ( window.visualViewport ) {
			window.visualViewport.addEventListener( 'resize', ajustarParaTeclado );
			window.visualViewport.addEventListener( 'scroll', ajustarParaTeclado );
		}
		// Reforco: em alguns navegadores o resize do visualViewport demora um
		// pouco pra disparar depois do teclado abrir.
		input.addEventListener( 'focus', function () { setTimeout( ajustarParaTeclado, 50 ); } );
		// Campo virou textarea (2026-09-25) -- Enter continua enviando
		// como no input antigo; Shift+Enter quebra linha.
		input.addEventListener( 'keydown', function ( e ) {
			if ( e.key !== 'Enter' || e.shiftKey || e.isComposing ) return;
			e.preventDefault();
			enviarFormulario();
		} );
		function enviarFormulario() {
			if ( form.requestSubmit ) form.requestSubmit();
			else form.dispatchEvent( new Event( 'submit', { cancelable: true } ) );
		}

		function abrir() {
			painel.classList.add( 'aberto' );
			bolha.setAttribute( 'aria-expanded', 'true' );
			if ( ! iniciado ) {
				iniciado = true;
				var salvo = carregarEstadoSalvo();
				if ( salvo && salvo.historico && salvo.historico.length ) {
					restaurar( salvo );
					// Quem recarregou a LP antes de responder o genero (nenhum
					// turno de verdade rodou ainda, perguntasFeitas continua
					// 0) precisa ver os botoes de novo -- eles nao entram no
					// historico persistido de proposito (ver addQuebraGelo).
					if ( modoLP && perguntasFeitas === 0 && ! perguntasEncerradas ) {
						addQuebraGelo( [ 'Ação', 'Comédia', 'Terror', 'Romance', 'Drama' ] );
					}
				} else if ( modoLP ) {
					iniciarLP();
				} else {
					iniciar();
				}
			}
			ajustarParaTeclado();
			// No modo LP o chat ja nasce aberto na dobra -- focar o input
			// sozinho abriria o teclado do celular assim que a pagina
			// carrega, antes da pessoa ler qualquer coisa.
			if ( ! modoLP ) input.focus();
		}
		function fecharPainel() {
			painel.classList.remove( 'aberto' );
			bolha.setAttribute( 'aria-expanded', 'false' );
			limparAjusteTeclado();
		}
		bolha.addEventListener( 'click', function () {
			if ( painel.classList.contains( 'aberto' ) ) fecharPainel(); else abrir();
		} );
		fechar.addEventListener( 'click', fecharPainel );
		expandirBtn.addEventListener( 'click', function () {
			var expandido = painel.classList.toggle( 'expandido' );
			expandirBtn.textContent = expandido ? '⤡' : '⤢';
			expandirBtn.title = expandido ? 'Recolher chat' : 'Expandir chat';
		} );
		// Extraida do handler do botao (2026-09-24) pra poder ser chamada
		// tambem pelo link "clique aqui" dentro do aviso de limite de
		// mensagens (ver talvezAvisarLimite abaixo).
		function reiniciarConversa() {
			limparEstadoSalvo();
			sessaoId                 = gerarSessaoId();
			estado                   = {};
			perguntasFeitas          = 0;
			rodadaAtual              = 1;
			excluirFilmes            = [];
			historico                = [];
			ultimosItensRecomendados = [];
			perguntasEncerradas      = false;
			// Nao mexe em emailJaCapturado() -- e duravel, guardado numa
			// chave separada de proposito (nao repetir o pedido pra quem ja
			// deu o email, mesmo reiniciando a conversa).
			mensagensEnviadas    = 0;
			pedidoEmailMostrado  = false;
			aguardandoEmail      = false;
			avisoLimiteMostrado  = false;
			thread.innerHTML = '';
			painel.classList.remove( 'com-filmes' );
			if ( modoLP ) iniciarLP(); else iniciar();
		}
		reiniciarBtn.addEventListener( 'click', reiniciarConversa );

		// render* so mexem no DOM (usados tambem pra restaurar do
		// localStorage, sem duplicar no historico). add*/registrarFilmes
		// alem de renderizar, gravam no historico e persistem.
		function renderBot( html ) {
			var el = document.createElement( 'div' );
			el.className = 'dsi-bh-msg dsi-bh-msg--bot';
			el.innerHTML = html;
			thread.appendChild( el );
			thread.scrollTop = thread.scrollHeight;
			return el;
		}
		function renderUser( texto ) {
			// Nova pergunta do usuario -- solta a ancora no bloco com resenha
			// anterior, volta a acompanhar o fim da conversa ate a proxima
			// recomendacao chegar (ver renderFilmes).
			ancoraComResenha = null;
			var el = document.createElement( 'div' );
			el.className = 'dsi-bh-msg dsi-bh-msg--user';
			el.textContent = texto;
			thread.appendChild( el );
			thread.scrollTop = thread.scrollHeight;
		}
		function addBot( html ) {
			var el = renderBot( html );
			historico.push( { tipo: 'bot', html: html } );
			salvarEstado();
			return el;
		}
		function addUser( texto ) {
			renderUser( texto );
			historico.push( { tipo: 'user', texto: texto } );
			salvarEstado();
		}

		// Recoloca a conversa salva na tela (sem chamar a API de novo) --
		// so acontece na primeira abertura do painel, se tiver algo salvo
		// dentro do prazo de validade (ver carregarEstadoSalvo).
		function restaurar( dados ) {
			sessaoId                 = dados.sessaoId || sessaoId;
			estado                   = dados.estado || {};
			perguntasFeitas          = dados.perguntasFeitas || 0;
			rodadaAtual              = dados.rodadaAtual || 1;
			excluirFilmes            = dados.excluirFilmes || [];
			historico                = dados.historico || [];
			ultimosItensRecomendados = dados.ultimosItensRecomendados || [];
			perguntasEncerradas      = !! dados.perguntasEncerradas;
			mensagensEnviadas        = dados.mensagensEnviadas || 0;
			pedidoEmailMostrado      = !! dados.pedidoEmailMostrado;
			aguardandoEmail          = !! dados.aguardandoEmail;
			avisoLimiteMostrado      = !! dados.avisoLimiteMostrado;
			historico.forEach( function ( item ) {
				if ( item.tipo === 'bot' ) renderBot( item.html );
				else if ( item.tipo === 'user' ) renderUser( item.texto );
				else if ( item.tipo === 'filmes' ) renderFilmes( item.itens );
				else if ( item.tipo === 'sem_resenha' ) renderSemResenha( item.itens );
				else if ( item.tipo === 'feedback_pedido' ) renderFeedback();
				else if ( item.tipo === 'aviso_limite' ) renderAvisoLimite();
			} );
		}

		// Par do log 'bloqueio' do servidor: la da pra contar, aqui da pra
		// cruzar com a origem da sessao (utm da campanha) no GA4.
		function trackBloqueio( rota ) {
			track( 'widget_bloqueado', { rota: rota, sessao_id: sessaoId } );
		}

		function chamarBilheteiro( mensagem ) {
			return fetch( BILHETEIRO_CHAT_ENDPOINT, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify( {
					mensagem: mensagem,
					sessao_id: sessaoId,
					estado: estado,
					perguntas_feitas: perguntasFeitas,
					// sem_minigames avisa o backend que essa entrada nao tem
					// Corredor/Emocao -- muda o conjunto de perguntas
					// obrigatorias pra genero/filmes-series/atores/plataforma
					// (decisao do gestor 2026-09-21), ver
					// dsi_bilheteiro_campos_obrigatorios em functions.php.
					contexto_minigames: { corredor_pulado: true, emocao_pulada: true, sem_minigames: true }
				} )
			} ).then( function ( r ) {
				return r.json().then( function ( body ) {
					if ( ! r.ok ) {
						if ( r.status === 429 ) trackBloqueio( 'chat' );
						// Mostra o motivo real (ex: "Muitas mensagens em pouco
						// tempo...", limite RNF3) em vez de um erro generico --
						// achado do gestor 2026-09-20: sem isso, um 429 e um erro
						// de verdade pareciam a mesma coisa pra quem tava usando.
						var erro = new Error( ( body && body.erro ) || ( 'http ' + r.status ) );
						erro.mensagemAmigavel = body && body.erro;
						throw erro;
					}
					return body;
				} );
			} );
		}

		function processarResposta( data ) {
			estado = data.estado || estado;
			perguntasFeitas = data.perguntas_feitas || perguntasFeitas;
			if ( data.pronto ) {
				buscarRecomendacoes();
			} else {
				// Pedido de email/aviso ANTES da resposta de verdade
				// (2026-09-24, achado do gestor com transcript real: "ele
				// responde a duvida e so depois manda pedindo o email" ficava
				// estranho, com o pedido colado atras da resposta como se
				// fosse a ultima palavra da conversa). Assim a conversa
				// sempre termina no conteudo, nao no pedido.
				talvezPedirEmail();
				talvezAvisarLimite();
				addBot( escapeHtml( data.mensagem ) );
			}
		}

		function iniciar() {
			// Mensagem de boas-vindas fixa, some na hora (nao depende da API) --
			// achado do gestor 2026-09-20: sem isso, o painel abria vazio ate a
			// primeira pergunta chegar, e quem tava usando nao entendia o que
			// fazer ali (ainda mais se a chamada demorasse ou desse 429).
			addBot( 'Oi! Sou o seu curador pessoal e vou te ajudar a encontrar o que assistir hoje.' );
			var carregando = renderBot( '<span class="dsi-bh-digitando">...</span>' );
			chamarBilheteiro( '' ).then( function ( data ) {
				carregando.remove();
				processarResposta( data );
			} ).catch( function ( err ) {
				carregando.remove();
				addBot( ( err && err.mensagemAmigavel ) || 'Deu um probleminha aqui, tenta recarregar a página.' );
			} );
		}

		// Abertura do modo LP (2026-09-25): a primeira pergunta ("genero")
		// e fixa e local, sem chamar o backend -- so pra poder oferecer os
		// botoes quebra-gelo junto dela. A partir da resposta (clique ou
		// texto livre) o fluxo volta a ser 100% igual ao balao padrao
		// (enviarParaExtracaoDePreferencia chama o mesmo endpoint de sempre).
		function iniciarLP() {
			addBot( 'Oi! Sou o seu curador pessoal e vou te ajudar a encontrar o que assistir hoje.' );
			addBot( 'Que gênero te chama mais atenção hoje? Escolha uma das opções abaixo ou digite livremente o que te parece mais interessante.' );
			addQuebraGelo( [ 'Ação', 'Comédia', 'Terror', 'Romance', 'Drama' ] );
		}

		// Botoes de atalho pra primeira resposta (so modo LP). De proposito
		// nao entram no historico persistido -- ver o reforco em abrir()
		// pra quem recarrega antes de responder.
		function addQuebraGelo( opcoes ) {
			var wrap = document.createElement( 'div' );
			wrap.className = 'dsi-bh-quebra-gelo-wrap';
			opcoes.forEach( function ( opcao ) {
				var botao = document.createElement( 'button' );
				botao.type = 'button';
				botao.className = 'dsi-bh-quebra-gelo';
				botao.textContent = opcao;
				botao.addEventListener( 'click', function () {
					wrap.remove();
					addUser( opcao );
					mensagensEnviadas++;
					salvarEstado();
					enviarParaExtracaoDePreferencia( opcao );
				} );
				wrap.appendChild( botao );
			} );
			thread.appendChild( wrap );
			thread.scrollTop = thread.scrollHeight;
		}

		// Extraida do handler de submit (2026-09-23) pra poder ser chamada de
		// dois lugares: o fluxo normal de digitar, e o escape hatch quando
		// perguntarSobreRecomendacoes() descobre que a mensagem era pedido
		// de nova recomendacao, nao pergunta sobre o que ja foi mostrado.
		function enviarParaExtracaoDePreferencia( texto ) {
			var carregando = renderBot( '<span class="dsi-bh-digitando">...</span>' );
			chamarBilheteiro( texto ).then( function ( data ) {
				carregando.remove();
				processarResposta( data );
			} ).catch( function ( err ) {
				carregando.remove();
				addBot( ( err && err.mensagemAmigavel ) || 'Deu um probleminha aqui, pode tentar de novo?' );
			} );
		}

		function capturarEmail( email ) {
			aguardandoEmail = false;
			salvarEstado();
			var carregando = renderBot( '<span class="dsi-bh-digitando">...</span>' );
			fetch( NEWSLETTER_ENDPOINT, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify( { email: email, sessao_id: sessaoId } )
			} ).then( function ( r ) {
				if ( r.status === 429 ) trackBloqueio( 'newsletter' );
				return r.json();
			} ).then( function ( data ) {
				carregando.remove();
				if ( data && data.sucesso ) {
					marcarEmailCapturado();
					// Conversao do teste de midia paga (2026-09-25). Nunca o
					// email em si no evento -- so o fato de ter cadastrado.
					track( 'widget_email_capturado', { sessao_id: sessaoId } );
					addBot( 'Prontinho, cadastro feito! 🎬 Vamos continuar de onde paramos.' );
				} else {
					addBot( ( data && data.mensagem ) || 'Não consegui cadastrar agora, mas pode seguir aproveitando as recomendações!' );
				}
			} ).catch( function () {
				carregando.remove();
				addBot( 'Não consegui cadastrar agora, mas pode seguir aproveitando as recomendações!' );
			} );
		}

		// So oferece newsletter depois de N mensagens de verdade, uma unica
		// vez por conversa, e nunca de novo pra quem ja deu o email antes
		// nesse navegador (emailJaCapturado(), chave separada e duravel --
		// ver comentario dela acima). Chamada apos toda resposta do bot
		// terminar (ver os 3 pontos de chamada abaixo).
		function talvezPedirEmail() {
			if ( pedidoEmailMostrado || aguardandoEmail || emailJaCapturado() ) return;
			if ( mensagensEnviadas < LIMITE_MENSAGENS_PEDIR_EMAIL ) return;
			pedidoEmailMostrado = true;
			aguardandoEmail     = true;
			// Solta a ancora no bloco com resenha -- o pedido de email e o
			// conteudo mais recente/acionavel agora, nao deve competir com
			// aquele scroll fixo (ver ancoraComResenha/ajustarParaTeclado
			// acima) quando o teclado do celular abrir pra responder.
			ancoraComResenha = null;
			salvarEstado();
			// Reduzida a uma linha so (2026-09-25, pedido do gestor: mensagem
			// anterior era longa demais pra um pedido no meio da conversa).
			addBot( 'Vi que você gosta de falar de filmes — que tal receber novidades direto no seu e-mail?' );
		}

		// Quem ignora o pedido de email (talvezPedirEmail acima) e segue
		// batendo papo sem gerar nenhum valor -- so custa chamada a LLM de
		// graca, sem teto alem do rate limit por IP (2026-09-24, pedido do
		// gestor). Avisa uma unica vez aos LIMITE_MENSAGENS_AVISO e oferece
		// reiniciar -- nunca reseta sozinho nem bloqueia mensagem nenhuma,
		// so avisa e deixa a pessoa escolher.
		function talvezAvisarLimite() {
			if ( avisoLimiteMostrado || emailJaCapturado() ) return;
			if ( mensagensEnviadas < LIMITE_MENSAGENS_AVISO ) return;
			avisoLimiteMostrado = true;
			ancoraComResenha = null; // mesmo motivo do talvezPedirEmail acima
			salvarEstado();
			addAvisoLimite();
		}

		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			var texto = input.value.trim();
			if ( ! texto ) return;
			addUser( texto );
			input.value = '';
			mensagensEnviadas++;
			salvarEstado();
			// Mensagem seguinte ao pedido de email (ver talvezPedirEmail) --
			// so intercepta se parecer um email de verdade; se a pessoa
			// ignorar e mandar outra coisa, segue o fluxo normal sem travar.
			if ( aguardandoEmail && EMAIL_REGEX.test( texto ) ) {
				capturarEmail( texto );
				return;
			}
			aguardandoEmail = false;
			// Depois que a recomendacao ja apareceu, a proxima mensagem e
			// PERGUNTA sobre o que foi mostrado ("tem o De Niro?"), nao nova
			// preferencia -- vai pra rota separada que responde com base nos
			// dados reais dos filmes, em vez de cair na extracao de
			// preferencia de novo (ver perguntasEncerradas acima).
			if ( perguntasEncerradas ) {
				perguntarSobreRecomendacoes( texto );
				return;
			}
			enviarParaExtracaoDePreferencia( texto );
		} );

		function perguntarSobreRecomendacoes( texto ) {
			var carregando = renderBot( '<span class="dsi-bh-digitando">...</span>' );
			fetch( PERGUNTAR_ENDPOINT, {
				method: 'POST',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify( { pergunta: texto, itens: ultimosItensRecomendados, sessao_id: sessaoId } )
			} ).then( function ( r ) {
				return r.json().then( function ( body ) {
					if ( ! r.ok ) {
						if ( r.status === 429 ) trackBloqueio( 'perguntar' );
						var erro = new Error( ( body && body.erro ) || ( 'http ' + r.status ) );
						erro.mensagemAmigavel = body && body.erro;
						throw erro;
					}
					return body;
				} );
			} ).then( function ( data ) {
				carregando.remove();
				// "como escolho outro gênero?"/"nova simulação" nao sao
				// pergunta sobre o elenco/genero dos filmes ja mostrados --
				// sao pedido de RECOMEÇAR (achado do gestor 2026-09-23: sem
				// isso, ficava preso numa recusa em loop, sem jeito de
				// voltar a digitar uma preferencia nova). Reabre a extracao
				// com a MESMA mensagem, sem pedir pra repetir.
				if ( data.pedir_nova_recomendacao ) {
					perguntasEncerradas = false;
					salvarEstado();
					enviarParaExtracaoDePreferencia( texto );
					return;
				}
				// Pedido/aviso ANTES da resposta -- ver mesmo comentario em
				// processarResposta().
				talvezPedirEmail();
				talvezAvisarLimite();
				addBot( escapeHtml( data.resposta ) );
			} ).catch( function ( err ) {
				carregando.remove();
				addBot( ( err && err.mensagemAmigavel ) || 'Não consegui responder isso agora, pode perguntar de outro jeito?' );
			} );
		}

		function notaHtml( nota ) {
			// nota vem so de post_id_gerado cruzado com a base TMDB -- cobertura
			// parcial, cresce sozinha (ver dsi_recomendar_filme). Sem nota nao
			// e erro, so nao mostra nada.
			return ( nota === null || nota === undefined ) ? '' : ' ⭐ ' + nota;
		}

		function montarCardsFilmes( itens ) {
			var html = '<p class="dsi-bh-secao-titulo">Minhas indicações para você</p>' +
				'<p class="dsi-bh-secao-apoio">Com base nas suas respostas, acredito que você vai gostar dessas produções.</p>' +
				'<div class="dsi-bh-filmes">';
			itens.forEach( function ( f, i ) {
				html += '<a class="dsi-bh-filme" href="' + f.link + '" target="_blank" rel="noopener" ' +
					'data-id="' + f.id + '" data-fonte="' + f.fonte + '" data-titulo="' + escapeHtml( f.titulo ) + '" data-posicao="' + ( i + 1 ) + '">' +
					( f.poster ? '<img src="' + f.poster + '" alt="">' : '<div class="dsi-bh-filme-sem-poster">🎬</div>' ) +
					'<div class="dsi-bh-filme-info"><strong>' + escapeHtml( f.titulo ) + notaHtml( f.nota ) + '</strong>' +
					'<p>' + escapeHtml( f.sinopse || '' ) + '</p>' +
					'<span class="dsi-bh-ver-resenha">Ver resenha →</span></div>' +
				'</a>';
			} );
			html += '</div>';
			return html;
		}

		// Lista sem resenha (2026-09-22): so existe no catalogo TMDB
		// importado, sem post no site ainda -- por isso sem link, sem botao
		// de feedback (nao tem id/fonte pra associar).
		function montarCardsSemResenha( itens ) {
			var html = '<p class="dsi-bh-secao-titulo">Você também pode gostar</p>' +
				'<p class="dsi-bh-sem-resenha-titulo">Também recomendamos (ainda sem resenha no site):</p>' +
				'<div class="dsi-bh-filmes">';
			itens.forEach( function ( f ) {
				html += '<div class="dsi-bh-filme dsi-bh-filme-sem-link">' +
					( f.poster_url ? '<img src="' + f.poster_url + '" alt="">' : '<div class="dsi-bh-filme-sem-poster">🎬</div>' ) +
					'<div class="dsi-bh-filme-info"><strong>' + escapeHtml( f.titulo ) + notaHtml( f.nota ) + '</strong>' +
					'<p>' + escapeHtml( f.sinopse || '' ) + '</p></div>' +
				'</div>';
			} );
			html += '</div>';
			return html;
		}

		function ligarCliquesFilmes( msgEl ) {
			msgEl.querySelectorAll( '.dsi-bh-filme' ).forEach( function ( link ) {
				link.addEventListener( 'click', function () {
					track( 'widget_filme_clicado', {
						filme_id: link.getAttribute( 'data-id' ),
						filme_fonte: link.getAttribute( 'data-fonte' ),
						filme_titulo: link.getAttribute( 'data-titulo' ),
						posicao: link.getAttribute( 'data-posicao' ),
						sessao_id: sessaoId,
						rodada: rodadaAtual
					} );
				} );
			} );
		}

		// render/add separados igual addBot/renderBot: renderFilmes so
		// desenha e liga os botoes (usado tambem ao restaurar do
		// localStorage); addFilmes tambem grava no historico e persiste.
		function renderFilmes( itens ) {
			var msgEl = renderBot( montarCardsFilmes( itens ) );
			msgEl.classList.add( 'dsi-bh-msg--filmes' );
			painel.classList.add( 'com-filmes' );
			ancoraComResenha = msgEl;
			ligarCliquesFilmes( msgEl );
			return msgEl;
		}
		function addFilmes( itens ) {
			var el = renderFilmes( itens );
			historico.push( { tipo: 'filmes', itens: itens } );
			salvarEstado();
			return el;
		}

		function renderSemResenha( itens ) {
			var msgEl = renderBot( montarCardsSemResenha( itens ) );
			msgEl.classList.add( 'dsi-bh-msg--filmes', 'dsi-bh-msg--externo' );
			painel.classList.add( 'com-filmes' );
			return msgEl;
		}
		function addSemResenha( itens ) {
			renderSemResenha( itens );
			historico.push( { tipo: 'sem_resenha', itens: itens } );
			salvarEstado();
		}

		// Extraido de montarCardsFilmes (2026-09-25, pedido do gestor: "a
		// pergunta pode ficar depois dos filmes externos, assim a pessoa ja
		// viu tudo -- hoje ele fica no meio e a pessoa pode ignorar"). Vira
		// mensagem propria, renderizada depois de com E sem resenha, em vez
		// de morar dentro do bloco com resenha e ficar espremida entre as
		// duas listas.
		function montarFeedback() {
			return '<div class="dsi-bh-feedback">' +
				'<span class="dsi-bh-feedback-titulo">Gostou das indicações?</span>' +
				'<div class="dsi-bh-feedback-botoes">' +
				'<button type="button" class="dsi-bh-fb" data-v="positivo">👍 Gostei</button>' +
				'<button type="button" class="dsi-bh-fb" data-v="negativo">👎 Quero outras</button>' +
				'</div></div>';
		}
		function renderFeedback() {
			var el = renderBot( montarFeedback() );
			// Mesma classe dos blocos de filme: sem isso a mensagem fica
			// dentro do balao padrao de 85% de largura com fundo proprio,
			// brigando visualmente com o card branco do .dsi-bh-feedback por
			// dentro (antes nao precisava disso porque vivia junto do bloco
			// com resenha, que ja tinha a classe).
			el.classList.add( 'dsi-bh-msg--filmes' );
			ligarBotoesFeedback( el );
			return el;
		}
		function addFeedback() {
			var el = renderFeedback();
			historico.push( { tipo: 'feedback_pedido' } );
			salvarEstado();
			return el;
		}

		// Tipo de historico proprio (2026-09-24), nao um addBot() generico:
		// precisa religar o listener do link "clique aqui" toda vez que
		// renderiza, inclusive ao restaurar() uma conversa salva -- addBot/
		// renderBot puro so seta innerHTML, sem religar nada (funciona pra
		// texto simples, nao pra HTML com botao clicavel).
		function montarAvisoLimite() {
			return 'Infelizmente, já estamos conversando há algum tempo. Para seguirmos conversando vou ter de gerar uma nova lista de recomendações com esses novos direcionais ou teremos de encerrar a conversa. Para recomeçar, ' +
				'<button type="button" class="dsi-bh-link-reiniciar">clique aqui</button>.';
		}
		function renderAvisoLimite() {
			var el = renderBot( montarAvisoLimite() );
			var link = el.querySelector( '.dsi-bh-link-reiniciar' );
			if ( link ) link.addEventListener( 'click', reiniciarConversa );
			return el;
		}
		function addAvisoLimite() {
			var el = renderAvisoLimite();
			historico.push( { tipo: 'aviso_limite' } );
			salvarEstado();
			return el;
		}

		function ligarBotoesFeedback( msgEl ) {
			var botoes = msgEl.querySelectorAll( '.dsi-bh-fb' );
			botoes.forEach( function ( btn ) {
				btn.addEventListener( 'click', function () {
					var veredito = btn.getAttribute( 'data-v' );
					// closest('.dsi-bh-feedback'), nao btn.parentNode: desde que
					// os botoes passaram a morar num sub-div (.dsi-bh-feedback-
					// botoes) pra ocupar a largura toda, parentNode so pegaria a
					// linha dos botoes e deixaria o titulo "Gostou..." pendurado.
					var linha = btn.closest( '.dsi-bh-feedback' );
					linha.innerHTML = veredito === 'positivo' ? 'Boa escolha! 🍿' : 'Poxa, vamos tentar de novo.';
					fetch( FEEDBACK_ENDPOINT, {
						method: 'POST',
						headers: { 'Content-Type': 'application/json' },
						body: JSON.stringify( { sessao_id: sessaoId, rodada: rodadaAtual, veredito: veredito } )
					} ).then( function ( r ) { return r.json(); } ).then( function ( resp ) {
						if ( veredito === 'positivo' ) return;
						rodadaAtual++;
						// "Quero outras": a proxima mensagem do usuario e NOVA
						// preferencia de novo (ex: "sem terror"), nao pergunta
						// sobre a recomendacao atual -- sem isso ela cairia
						// direto na rota de pergunta grounded em vez de voltar
						// pra extracao (ver perguntasEncerradas). Volta a virar
						// true quando a proxima leva de recomendacoes renderizar.
						perguntasEncerradas = false;
						salvarEstado();
						if ( resp.rodadas_negativas_consecutivas >= 3 ) {
							addBot( '3 tentativas sem sucesso — que tal recomeçar com outro gênero ou emoção? Me conta o que você quer agora.' );
						} else {
							addBot( 'Me conta mais alguma coisa (outro gênero, ator, "sem terror"...) que eu tento de novo.' );
						}
					} );
				} );
			} );
		}

		function buscarRecomendacoes() {
			var carregando = renderBot( 'Só um instante, escolhendo uns filmes pra você...' );
			var url = new URL( CINEQUIZ_ENDPOINT );
			var params = { limite: 5, sessao_id: sessaoId, rodada: rodadaAtual };
			if ( valorOuVazio( estado.genero ) ) params.genero = estado.genero;
			if ( valorOuVazio( estado.emocao ) ) params.emocao = estado.emocao;
			if ( valorOuVazio( estado.plataforma ) ) params.plataforma = estado.plataforma;
			if ( valorOuVazio( estado.tipo ) ) params.tipo = estado.tipo;
			if ( estado.baseado_fatos_reais === true ) params.baseado_fatos_reais = 'sim';
			if ( valorOuVazio( estado.q ) ) params.q = estado.q;
			if ( ( estado.temas || [] ).length ) params.temas = estado.temas.join( ',' );
			if ( ( estado.atores || [] ).length ) params.atores = estado.atores.join( ',' );
			if ( ( estado.exclusoes || [] ).length ) params.exclusoes = estado.exclusoes.join( ',' );
			if ( estado.confirmacoes ) params.confirmacoes = JSON.stringify( estado.confirmacoes );
			if ( excluirFilmes.length ) params.excluir_filmes = JSON.stringify( excluirFilmes );
			Object.keys( params ).forEach( function ( k ) { url.searchParams.set( k, params[ k ] ); } );

			fetch( url.toString() ).then( function ( r ) { return r.json(); } ).then( function ( data ) {
				carregando.remove();
				var itens = ( data && data.itemListElement ) || [];
				var semResenha = ( data && data.sem_resenha ) || [];
				if ( ! itens.length && ! semResenha.length ) {
					addBot( 'Não achei nada pra essa combinação ainda. Quer tentar outro gênero ou emoção?' );
					return;
				}
				if ( itens.length ) {
					itens.forEach( function ( r ) { excluirFilmes.push( { id: r.id, fonte: r.fonte } ); } );
					addFilmes( itens ); // seta ancoraComResenha internamente
				}
				if ( semResenha.length ) {
					// Achado ao vivo 2026-09-22: sem isso, "quero outras" so
					// trocava a lista com resenha -- os externos (sem_resenha)
					// nunca tinham exclusao nenhuma, entao repetiam sempre.
					semResenha.forEach( function ( r ) { excluirFilmes.push( { id: r.id, fonte: r.fonte } ); } );
					addSemResenha( semResenha );
				}
				// Pedido de feedback DEPOIS dos externos tambem (2026-09-25,
				// pedido do gestor: "hoje ele fica no meio e a pessoa pode
				// ignorar" -- ficava espremido entre a lista com resenha e a
				// lista externa). So pergunta quando houve algo com resenha
				// pra avaliar, mesmo comportamento de antes.
				if ( itens.length ) {
					addFeedback();
				}
				if ( ancoraComResenha ) {
					// Achado ao vivo 2026-09-22 (pedido do gestor): renderBot
					// sempre rola pro fim -- com os dois blocos, a tela parava
					// nos externos (sem resenha) em vez de ficar nos posts do
					// deveserisso, que sao o conteudo prioritario (tem link
					// "Ver resenha"). Forca a rolagem de volta pro topo do
					// bloco com resenha depois que os dois ja renderizaram.
					thread.scrollTop = offsetDentroDoThread( ancoraComResenha );
				}
				// A partir daqui, a proxima mensagem do usuario e pergunta
				// sobre o que foi mostrado, nao nova preferencia (ver
				// perguntasEncerradas no topo). Guarda so os campos que a
				// resposta grounded precisa -- itemListElement (com resenha)
				// usa genero/elenco/ano, sem_resenha usa generos/atores/
				// ano_lancamento; manda os dois nomes quando existirem, o
				// backend normaliza (dsi_bilheteiro_normalizar_item_pergunta).
				ultimosItensRecomendados = itens.concat( semResenha ).map( function ( f ) {
					return {
						titulo: f.titulo, sinopse: f.sinopse,
						ano: f.ano, ano_lancamento: f.ano_lancamento,
						genero: f.genero, generos: f.generos,
						direcao: f.direcao, atores: f.atores, elenco: f.elenco
					};
				} );
				perguntasEncerradas = true;
				salvarEstado();
				talvezPedirEmail();
				talvezAvisarLimite();
			} ).catch( function () {
				carregando.remove();
				addBot( 'Não consegui buscar agora, tenta de novo em instantes.' );
			} );
		}

		// Modo LP: chat ja nasce aberto na dobra, sem esperar clique no
		// balao (que nem existe aqui -- ver CSS display:none acima).
		if ( modoLP ) {
			if ( chegadaNovaDeCampanha() ) limparEstadoSalvo();
			abrir();
			if ( textoPreCarga ) input.value = textoPreCarga;
			if ( focoPreCarga ) input.focus();
			processarFilaPreCarga();
		}

		// Clique num genero ou envio feito na copia estatica, antes deste
		// script carregar (guardado em window.dsiBhFila pelo script inline
		// da LP) -- processa agora como se tivesse acontecido aqui.
		function processarFilaPreCarga() {
			var fila = window.dsiBhFila;
			window.dsiBhFila = null;
			if ( ! fila || ! fila.valor ) return;
			if ( fila.tipo === 'botao' ) {
				var botoes = thread.querySelectorAll( '.dsi-bh-quebra-gelo' );
				for ( var i = 0; i < botoes.length; i++ ) {
					if ( botoes[ i ].textContent === fila.valor ) { botoes[ i ].click(); return; }
				}
			}
			// Texto digitado, ou botao que nao existe mais (conversa salva
			// restaurada ja passou do genero) -- vira mensagem normal.
			input.value = fila.valor;
			enviarFormulario();
		}
	}

	// Quem chega de anuncio (UTM/click id na URL) comeca do zero em vez de
	// cair numa conversa antiga salva -- mas so uma vez por aba, senao um
	// simples recarregar (a URL continua com os parametros) apagaria a
	// conversa em andamento.
	function chegadaNovaDeCampanha() {
		var params = new URLSearchParams( window.location.search );
		var temCampanha = [ 'utm_source', 'utm_medium', 'utm_campaign', 'gclid', 'gbraid', 'wbraid', 'fbclid' ]
			.some( function ( p ) { return params.has( p ); } );
		if ( ! temCampanha ) return false;
		try {
			if ( sessionStorage.getItem( 'dsi_bh_lp_campanha_zerada' ) ) return false;
			sessionStorage.setItem( 'dsi_bh_lp_campanha_zerada', '1' );
		} catch ( e ) {}
		return true;
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', montarWidget );
	} else {
		montarWidget();
	}
} )();
