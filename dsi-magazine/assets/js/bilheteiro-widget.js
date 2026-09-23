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

	// GTM ja roda no site inteiro (container GTM-NHRLL7) -- manda o evento
	// pro dataLayer de sempre. Sem isso nao existe NENHUM jeito de saber
	// quantos filmes foram clicados: o log do bilheteiro (wp_dsi_bilheteiro_log)
	// so guarda quais filmes foram recomendados e o like/dislike agregado da
	// rodada, nunca qual link especifico a pessoa abriu.
	function track( eventName, params ) {
		if ( ! window.dataLayer ) return;
		window.dataLayer.push( Object.assign( { event: eventName }, params || {} ) );
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
			'.dsi-bh-msg--user{background:#c2511d;color:#fff;align-self:flex-end;border-bottom-right-radius:2px;}' +
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
			'.dsi-bh-input{flex:1;padding:8px 10px;border:1px solid #bdb29c;border-radius:6px;font:inherit;}' +
			'.dsi-bh-enviar{background:#c2511d;color:#fff;border:none;border-radius:6px;padding:8px 12px;' +
			'font-weight:600;cursor:pointer;}' +
			'.dsi-bh-filmes{display:flex;flex-direction:column;gap:8px;margin-top:4px;}' +
			/* Boxes de filme mais altos (2026-09-23, pedido do gestor: "mais
			   espaco pro texto sobre o filme e ver resenha") -- padding
			   6px -> 12px so pra dar folga, sem mexer no tamanho do poster. */
			'.dsi-bh-filme{display:flex;gap:8px;text-decoration:none;color:inherit;background:#fff;' +
			'border-radius:8px;padding:12px;box-shadow:0 1px 4px rgba(0,0,0,.12);}' +
			'.dsi-bh-filme img{width:46px;height:68px;object-fit:cover;border-radius:4px;flex-shrink:0;}' +
			'.dsi-bh-filme-sem-poster{width:46px;height:68px;background:#ebe3d2;border-radius:4px;' +
			'display:flex;align-items:center;justify-content:center;font-size:20px;flex-shrink:0;}' +
			/* Fontes da indicacao aumentadas (2026-09-22/23, pedido do
			   gestor: "a letra da indicacao esta muito pequena", depois
			   "titulo do filme, sinopse e ver resenha podem ser um pouco
			   maiores"). */
			'.dsi-bh-filme-info strong{display:block;font-size:14px;margin-bottom:2px;}' +
			'.dsi-bh-filme-info p{margin:0;font-size:13px;color:#6a5f4d;' +
			'display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;}' +
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
			'max-width:100%;max-height:100%;border-radius:0;}}';
		document.head.appendChild( style );
	}

	function montarWidget() {
		injetarEstilos();

		var raiz = document.createElement( 'div' );
		raiz.className = 'dsi-bh-widget';
		raiz.innerHTML =
			'<button class="dsi-bh-bolha" type="button" aria-expanded="false">' +
				'<span class="dsi-bh-bolha-emoji" aria-hidden="true">🎬</span> O que assistir hoje?' +
			'</button>' +
			'<div class="dsi-bh-painel">' +
				'<div class="dsi-bh-cabecalho">' +
					'<span>Curadoria Deveserisso!</span>' +
					'<span>' +
						'<button class="dsi-bh-reiniciar" type="button" title="Começar uma nova busca">↺</button>' +
						'<button class="dsi-bh-expandir" type="button" title="Expandir chat">⤢</button>' +
						'<button class="dsi-bh-fechar" type="button" aria-label="Fechar">×</button>' +
					'</span>' +
				'</div>' +
				'<div class="dsi-bh-thread"></div>' +
				'<form class="dsi-bh-form">' +
					'<input type="text" class="dsi-bh-input" placeholder="Digite sua resposta..." autocomplete="off">' +
					'<button type="submit" class="dsi-bh-enviar">Enviar</button>' +
				'</form>' +
			'</div>';
		document.body.appendChild( raiz );

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

		function salvarEstado() {
			salvarEstadoFn( {
				sessaoId: sessaoId,
				estado: estado,
				perguntasFeitas: perguntasFeitas,
				rodadaAtual: rodadaAtual,
				excluirFilmes: excluirFilmes,
				historico: historico,
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

		function abrir() {
			painel.classList.add( 'aberto' );
			bolha.setAttribute( 'aria-expanded', 'true' );
			if ( ! iniciado ) {
				iniciado = true;
				var salvo = carregarEstadoSalvo();
				if ( salvo && salvo.historico && salvo.historico.length ) {
					restaurar( salvo );
				} else {
					iniciar();
				}
			}
			ajustarParaTeclado();
			input.focus();
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
		reiniciarBtn.addEventListener( 'click', function () {
			limparEstadoSalvo();
			sessaoId        = gerarSessaoId();
			estado          = {};
			perguntasFeitas = 0;
			rodadaAtual     = 1;
			excluirFilmes   = [];
			historico       = [];
			thread.innerHTML = '';
			iniciar();
		} );

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
			sessaoId        = dados.sessaoId || sessaoId;
			estado          = dados.estado || {};
			perguntasFeitas = dados.perguntasFeitas || 0;
			rodadaAtual     = dados.rodadaAtual || 1;
			excluirFilmes   = dados.excluirFilmes || [];
			historico       = dados.historico || [];
			historico.forEach( function ( item ) {
				if ( item.tipo === 'bot' ) renderBot( item.html );
				else if ( item.tipo === 'user' ) renderUser( item.texto );
				else if ( item.tipo === 'filmes' ) renderFilmes( item.itens );
				else if ( item.tipo === 'sem_resenha' ) renderSemResenha( item.itens );
			} );
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

		form.addEventListener( 'submit', function ( e ) {
			e.preventDefault();
			var texto = input.value.trim();
			if ( ! texto ) return;
			addUser( texto );
			input.value = '';
			var carregando = renderBot( '<span class="dsi-bh-digitando">...</span>' );
			chamarBilheteiro( texto ).then( function ( data ) {
				carregando.remove();
				processarResposta( data );
			} ).catch( function ( err ) {
				carregando.remove();
				addBot( ( err && err.mensagemAmigavel ) || 'Deu um probleminha aqui, pode tentar de novo?' );
			} );
		} );

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
			html += '</div><div class="dsi-bh-feedback">' +
				'<span class="dsi-bh-feedback-titulo">Gostou das indicações?</span>' +
				'<div class="dsi-bh-feedback-botoes">' +
				'<button type="button" class="dsi-bh-fb" data-v="positivo">👍 Gostei</button>' +
				'<button type="button" class="dsi-bh-fb" data-v="negativo">👎 Quero outras</button>' +
				'</div></div>';
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
			ancoraComResenha = msgEl;
			ligarBotoesFeedback( msgEl );
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
			return msgEl;
		}
		function addSemResenha( itens ) {
			renderSemResenha( itens );
			historico.push( { tipo: 'sem_resenha', itens: itens } );
			salvarEstado();
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
				if ( ancoraComResenha ) {
					// Achado ao vivo 2026-09-22 (pedido do gestor): renderBot
					// sempre rola pro fim -- com os dois blocos, a tela parava
					// nos externos (sem resenha) em vez de ficar nos posts do
					// deveserisso, que sao o conteudo prioritario (tem link
					// "Ver resenha"). Forca a rolagem de volta pro topo do
					// bloco com resenha depois que os dois ja renderizaram.
					thread.scrollTop = offsetDentroDoThread( ancoraComResenha );
				}
			} ).catch( function () {
				carregando.remove();
				addBot( 'Não consegui buscar agora, tenta de novo em instantes.' );
			} );
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', montarWidget );
	} else {
		montarWidget();
	}
} )();
