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
			'.dsi-bh-cabecalho{background:#1d1a14;color:#e8a83c;padding:12px 14px;display:flex;' +
			'align-items:center;justify-content:space-between;font-weight:600;}' +
			'.dsi-bh-fechar{background:none;border:none;color:#f4eee2;font-size:20px;cursor:pointer;line-height:1;}' +
			'.dsi-bh-thread{flex:1;overflow-y:auto;padding:12px;display:flex;flex-direction:column;gap:8px;}' +
			'.dsi-bh-msg{max-width:85%;padding:8px 11px;border-radius:10px;line-height:1.4;}' +
			'.dsi-bh-msg--bot{background:#ebe3d2;align-self:flex-start;border-bottom-left-radius:2px;}' +
			'.dsi-bh-msg--user{background:#c2511d;color:#fff;align-self:flex-end;border-bottom-right-radius:2px;}' +
			'.dsi-bh-digitando{opacity:.6;}' +
			'.dsi-bh-form{display:flex;gap:6px;padding:10px;border-top:1px solid #bdb29c;}' +
			'.dsi-bh-input{flex:1;padding:8px 10px;border:1px solid #bdb29c;border-radius:6px;font:inherit;}' +
			'.dsi-bh-enviar{background:#c2511d;color:#fff;border:none;border-radius:6px;padding:8px 12px;' +
			'font-weight:600;cursor:pointer;}' +
			'.dsi-bh-filmes{display:flex;flex-direction:column;gap:8px;margin-top:4px;}' +
			'.dsi-bh-filme{display:flex;gap:8px;text-decoration:none;color:inherit;background:#fff;' +
			'border-radius:8px;padding:6px;box-shadow:0 1px 4px rgba(0,0,0,.12);}' +
			'.dsi-bh-filme img{width:46px;height:68px;object-fit:cover;border-radius:4px;flex-shrink:0;}' +
			'.dsi-bh-filme-sem-poster{width:46px;height:68px;background:#ebe3d2;border-radius:4px;' +
			'display:flex;align-items:center;justify-content:center;font-size:20px;flex-shrink:0;}' +
			'.dsi-bh-filme-info strong{display:block;font-size:12px;margin-bottom:2px;}' +
			'.dsi-bh-filme-info p{margin:0;font-size:11px;color:#6a5f4d;' +
			'display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;}' +
			'.dsi-bh-feedback{margin-top:6px;font-size:12px;}' +
			'.dsi-bh-fb{background:#ebe3d2;border:1px solid #bdb29c;border-radius:6px;padding:4px 8px;' +
			'cursor:pointer;font-size:12px;margin-top:4px;margin-right:4px;}' +
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
					'<button class="dsi-bh-fechar" type="button" aria-label="Fechar">×</button>' +
				'</div>' +
				'<div class="dsi-bh-thread"></div>' +
				'<form class="dsi-bh-form">' +
					'<input type="text" class="dsi-bh-input" placeholder="Digite sua resposta..." autocomplete="off">' +
					'<button type="submit" class="dsi-bh-enviar">Enviar</button>' +
				'</form>' +
			'</div>';
		document.body.appendChild( raiz );

		var bolha  = raiz.querySelector( '.dsi-bh-bolha' );
		var painel = raiz.querySelector( '.dsi-bh-painel' );
		var fechar = raiz.querySelector( '.dsi-bh-fechar' );
		var thread = raiz.querySelector( '.dsi-bh-thread' );
		var form   = raiz.querySelector( '.dsi-bh-form' );
		var input  = raiz.querySelector( '.dsi-bh-input' );

		var iniciado        = false;
		var sessaoId        = gerarSessaoId();
		var estado          = {};
		var perguntasFeitas = 0;
		var rodadaAtual     = 1;
		var excluirFilmes   = [];

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
		function ajustarParaTeclado() {
			if ( ! window.visualViewport || window.innerWidth > 480 || ! painel.classList.contains( 'aberto' ) ) {
				limparAjusteTeclado();
				return;
			}
			var vv = window.visualViewport;
			painel.style.height = vv.height + 'px';
			painel.style.top = vv.offsetTop + 'px';
			thread.scrollTop = thread.scrollHeight;
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
				iniciar();
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

		function addBot( html ) {
			var el = document.createElement( 'div' );
			el.className = 'dsi-bh-msg dsi-bh-msg--bot';
			el.innerHTML = html;
			thread.appendChild( el );
			thread.scrollTop = thread.scrollHeight;
			return el;
		}
		function addUser( texto ) {
			var el = document.createElement( 'div' );
			el.className = 'dsi-bh-msg dsi-bh-msg--user';
			el.textContent = texto;
			thread.appendChild( el );
			thread.scrollTop = thread.scrollHeight;
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
			var carregando = addBot( '<span class="dsi-bh-digitando">...</span>' );
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
			var carregando = addBot( '<span class="dsi-bh-digitando">...</span>' );
			chamarBilheteiro( texto ).then( function ( data ) {
				carregando.remove();
				processarResposta( data );
			} ).catch( function ( err ) {
				carregando.remove();
				addBot( ( err && err.mensagemAmigavel ) || 'Deu um probleminha aqui, pode tentar de novo?' );
			} );
		} );

		function montarCardsFilmes( itens ) {
			var html = '<div class="dsi-bh-filmes">';
			itens.forEach( function ( f, i ) {
				html += '<a class="dsi-bh-filme" href="' + f.link + '" target="_blank" rel="noopener" ' +
					'data-id="' + f.id + '" data-fonte="' + f.fonte + '" data-titulo="' + escapeHtml( f.titulo ) + '" data-posicao="' + ( i + 1 ) + '">' +
					( f.poster ? '<img src="' + f.poster + '" alt="">' : '<div class="dsi-bh-filme-sem-poster">🎬</div>' ) +
					'<div class="dsi-bh-filme-info"><strong>' + escapeHtml( f.titulo ) + '</strong>' +
					'<p>' + escapeHtml( f.sinopse || '' ) + '</p></div>' +
				'</a>';
			} );
			html += '</div><div class="dsi-bh-feedback">Gostou das indicações? ' +
				'<button type="button" class="dsi-bh-fb" data-v="positivo">👍 Gostei</button>' +
				'<button type="button" class="dsi-bh-fb" data-v="negativo">👎 Quero outras</button></div>';
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

		function ligarBotoesFeedback( msgEl ) {
			var botoes = msgEl.querySelectorAll( '.dsi-bh-fb' );
			botoes.forEach( function ( btn ) {
				btn.addEventListener( 'click', function () {
					var veredito = btn.getAttribute( 'data-v' );
					var linha = btn.parentNode;
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
			var carregando = addBot( 'Só um instante, escolhendo uns filmes pra você...' );
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
				if ( ! itens.length ) {
					addBot( 'Não achei nada pra essa combinação ainda. Quer tentar outro gênero ou emoção?' );
					return;
				}
				itens.forEach( function ( r ) { excluirFilmes.push( { id: r.id, fonte: r.fonte } ); } );
				var msgEl = addBot( montarCardsFilmes( itens ) );
				ligarBotoesFeedback( msgEl );
				ligarCliquesFilmes( msgEl );
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
