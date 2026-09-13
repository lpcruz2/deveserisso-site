(function () {
	'use strict';

	// Sensor de comportamento de UI -- mede se quem esta navegando e um agente
	// pilotando o navegador de verdade, nao um humano. Nunca captura o
	// CARACTERE digitado -- so a classificacao estrutural/imprimivel e o
	// timing (ver dsi-ui-sensor.php).
	//
	// Envia beacon em dois casos:
	//   1. algum sinal de automacao disparou (deteccao)
	//   2. a sessao caiu na amostra de baseline (denominador estatistico)
	// Fora esses dois, nao gera trafego nem linha no banco.
	//
	// Navegador agentico como Claude no Chrome / ChatGPT Atlas / Perplexity
	// Comet manda User-Agent de Chrome puro -- confirmado ao vivo e pela
	// documentacao dos proprios fornecedores. Nao existe deteccao por header:
	// so comportamento, e so agregado por SESSAO (a assinatura e "muitas
	// paginas em janela curta, ritmo constante, poucas acoes por pagina",
	// que nao aparece olhando um pageview isolado).

	// Versao do CONJUNTO DE REGRAS (limiares, sinais, gatilhos). Sobe 1 toda
	// vez que um limiar ou sinal muda, pra nunca ficar impossivel saber qual
	// regra gerou qual linha antiga depois de um ajuste futuro.
	//
	// Changelog:
	//  v1 (2026-09-13): estado em que o versionamento comecou -- 10 sinais
	//     (webdriver, clique_sem_mousemove, movimento_mouse_sintetico,
	//     timing_regular_demais, viewport_automacao_sem_plugins, sem_idiomas,
	//     clique_duracao_impossivel, digitacao_impossivel,
	//     scroll_multiplo_viewport, evento_nao_confiavel) + cobertura de
	//     input/beforeinput adicionada na mesma versao.
	//  v2 (2026-09-13): rodada de correcoes apos revisao externa. Muda o
	//     significado de 3 sinais, entao NAO compare taxa de v1 com v2:
	//     - session_id agora nasce no CLIENTE (crypto.randomUUID). Em v1 ele
	//       reaproveitava o uuid impresso pelo servidor, que era cacheado
	//       junto com o HTML: dezenas de visitantes distintos caiam na mesma
	//       "sessao" (confirmado nos dados: 1 sessao com 30 paginas e 28 IPs).
	//       O uuid do servidor continua indo no payload como render_id -- duas
	//       linhas com o mesmo render_id e session_id diferentes = cache HIT,
	//       health check da coleta de graca.
	//     - `digitacao_impossivel`: pareamento keydown/keyup passa a ser por
	//       e.code. Em v1 uma variavel global unica era sobrescrita pela tecla
	//       seguinte, e rollover de digitacao humana rapida (keydown a,
	//       keydown b, keyup a) produzia dwell minusculo ou NEGATIVO -- falso
	//       positivo em digitador rapido.
	//     - `evento_nao_confiavel` foi dividido em clique_nao_confiavel /
	//       tecla_nao_confiavel / input_nao_confiavel. Colapsar os tres
	//       destruia informacao ja provada util (Manus seta value via JS sem
	//       digitar; Comet vaza no clique) e, pior, um gerenciador de senha
	//       preenchendo formulario (isTrusted=false em input) virava "agente".
	//     - `timing_regular_demais` passa a ser calculado SEM eventos de
	//       scroll. Scroll e entregue atrelado ao frame (~16,7ms), entao um
	//       flick continuo sozinho podia render CV < 0,05 sem automacao.
	//     - `scroll_multiplo_viewport`: tolerancia agora em pixels absolutos
	//       (+-4px, era +-2% do viewport = +-20px) e parada no FIM do
	//       documento e explicitamente excluida. Novos campos
	//       doc_scroll_max_px / n_scroll_stops / n_scroll_stops_multiplo pra
	//       nunca mais precisar reconstituir isso por query manual.
	//     - first_click_dwell_ms agora usa o mousedown DAQUELE clique (em v1
	//       era o primeiro mousedown da pagina inteira) e vai bruto, podendo
	//       ser negativo -- o clamp em 0 do servidor transformava dwell
	//       negativo de evento sintetico em "0ms", que passava o teste < 20ms
	//       e virava deteccao por artefato, nao por medicao.
	//     - n_untrusted_click/n_untrusted_input contam so o evento final
	//       (click/input), nao o par com mousedown/beforeinput -- somar os
	//       dois inflava a contagem em ate 2x quando o mesmo disparo sintetico
	//       gerava ambos.
	//     - parada de scroll pendente (<150ms desde o ultimo evento) e
	//       resolvida na hora do flush, nao só pelo debounce sozinho -- uma
	//       saida rapida de pagina (o padrao de um agente) podia acontecer
	//       antes do timer disparar, perdendo justamente a posicao final.
	//  v3 (2026-09-13): 3 sinais novos, comparados contra a literatura
	//     (FP-Agent, arXiv:2605.01247; "Whose Agent Are You?", arXiv:2606.20910):
	//     - `fetch_metadata_impossivel` / `client_hints_incoerente` (o calculo
	//       em si sobreviveu, mas o TRANSPORTE mudou na v4 -- ver abaixo).
	//     - `total_mouse_dist_px`: soma da distancia percorrida pelo mouse na
	//       sessao inteira (nao so ate o primeiro clique, que ja tinhamos).
	//       Campo observacional, sem limiar/motivo associado -- mesmo padrao
	//       de click_x_std/click_y_std.
	//  v4 (2026-09-13): revisao externa independente (outro modelo, com
	//     evidencia ao vivo contra producao) achou 2 bugs reais introduzidos
	//     pelas proprias correcoes anteriores, mais outros 5 problemas:
	//     - **headerFlags REMOVIDO do cliente.** A v3 calculava
	//       fetch_metadata_impossivel/client_hints_incoerente no servidor e
	//       serializava o resultado dentro de cfg -- que vive no MESMO HTML
	//       cacheavel que o comentario de sessao, algumas linhas abaixo,
	//       ja avisava pra nunca carregar estado por-visitante ("o bug do
	//       session_id em v1"). Confirmado ao vivo: um curl com header hostil
	//       muda o headerFlags gravado naquele HTML -- se esse HTML entrar em
	//       cache, todo visitante seguinte da mesma URL herdaria o motivo de
	//       OUTRA pessoa, e o gate do flush() (que a v3 tambem afrouxou pra
	//       dispensar interacao quando ha headerFlags) mandaria um beacon de
	//       "deteccao" sem nenhum clique/scroll/tecla ter acontecido de
	//       verdade. Os dois motivos continuam existindo, mas agora sao
	//       calculados E GRAVADOS direto no PHP (dsi_uisensor_grava_header_flags,
	//       chamado em wp_footer), nunca passam pelo cliente/beacon -- e como
	//       so rodam em cache MISS (unico momento em que o hook realmente
	//       executa), nunca atribuem o header de um visitante a outro.
	//     - `clique_duracao_impossivel` disparava em TODO toque de celular.
	//       mousedown/mouseup/click sintetizados a partir do mesmo toque
	//       carregam o mesmo timeStamp -> dwell exatamente 0 -> passa o teste
	//       "< 20ms" (que a v2, com razao, parou de mascarar com clamp). 9 de
	//       9 cliques touch na base de producao tinham dwell=0. Corrigido:
	//       um toque nos ~1s antes do clique desarma essa heuristica pra
	//       aquele clique.
	//     - Auto-repeat de tecla (segurar Arrow/PageDown) nao era excluido --
	//       o SO gera keydown em intervalo fixo (~30ms, CV baixo) com um so
	//       keyup, disparando digitacao_impossivel E timing_regular_demais
	//       num comportamento humano banal. Corrigido: `e.repeat` descarta o
	//       evento antes de qualquer contagem.
	//     - O primeiro `visibilitychange` marcava `flushed = true` e
	//       congelava a coleta pro resto da pagina -- trocar de aba (comum em
	//       humano, raro num agente em tarefa unica) truncava sistematicamente
	//       o lado humano da comparacao. Agora so pagehide/beforeunload
	//       fecham o beacon (`flushed = true`); visibilitychange reenvia (o
	//       servidor faz upsert por trace_id, fica so a versao mais recente),
	//       e um reenvio sem novidade nenhuma e descartado no proprio cliente
	//       (assinatura de contagem inalterada).
	//     - `document.documentElement.scrollTop` assume standards mode;
	//       trocado por `document.scrollingElement`, que nao tem essa
	//       dependencia e custa o mesmo.
	//     - `dev_traffic` deixou de ser lido do payload do cliente (qualquer
	//       um podia se autodeclarar tetefego interno e sumir das contas). O
	//       marcador `?dsi_debug=1` agora vira cookie de sessao gravado pelo
	//       PROPRIO servidor (dsi_uisensor_debug_cookie, hook `init`) -- o
	//       cliente nao precisa mais fazer nada, localStorage removido.
	var RULESET_VERSION = 4;

	if ( window.__dsiUiSensorLoaded ) { return; }
	window.__dsiUiSensorLoaded = true;

	var cfg = window.dsiUiSensor || {};
	if ( ! cfg.endpoint ) { return; }

	function novoId() {
		try {
			if ( window.crypto && typeof crypto.randomUUID === 'function' ) {
				return crypto.randomUUID();
			}
		} catch ( e ) {}
		return 'f' + Date.now().toString( 36 ) + Math.random().toString( 36 ).slice( 2, 12 );
	}

	// -------------------------------------------------------------------
	// SESSAO -- sessionStorage, nao cookie: morre ao fechar a aba, nao
	// atravessa abas nem visitas futuras. E identidade de sessao de
	// navegacao, nao de pessoa.
	//
	// O id NUNCA vem do servidor: o HTML e cacheado (LiteSpeed/Cloudflare) e
	// um uuid impresso no render vira o mesmo id pra todo mundo que receber
	// aquela pagina do cache.
	//
	// O sorteio da amostra e decidido UMA VEZ por sessao, nunca por pagina:
	// amostrar pagina a pagina deixaria buracos no meio da sessao e
	// corromperia justamente as features de ritmo entre paginas.
	// -------------------------------------------------------------------
	var pageviewId = novoId();
	var sess = { id: pageviewId, index: 1, msSincePrev: null, sampled: false, degradado: true };

	try {
		var agora = Date.now();
		var id    = sessionStorage.getItem( 'dsi_sess_id' );

		if ( ! id ) {
			id = novoId();
			sessionStorage.setItem( 'dsi_sess_id', id );
			sessionStorage.setItem( 'dsi_sess_n', '1' );
			sessionStorage.setItem( 'dsi_sess_sampled', Math.random() < ( cfg.baselineRate || 0 ) ? '1' : '0' );
			sess.index = 1;
		} else {
			var n = parseInt( sessionStorage.getItem( 'dsi_sess_n' ) || '1', 10 ) + 1;
			sessionStorage.setItem( 'dsi_sess_n', String( n ) );
			sess.index = n;

			var tprev = parseInt( sessionStorage.getItem( 'dsi_sess_tprev' ) || '0', 10 );
			if ( tprev > 0 && agora > tprev ) { sess.msSincePrev = agora - tprev; }
		}

		sessionStorage.setItem( 'dsi_sess_tprev', String( agora ) );

		sess.id        = id;
		sess.sampled   = sessionStorage.getItem( 'dsi_sess_sampled' ) === '1';
		sess.degradado = false;
	} catch ( e ) {
		// sessionStorage bloqueado (janela privada, cookies desativados).
		// Segue funcionando como sensor por pageview, sem agregacao. O id de
		// sessao cai no id do pageview -- unico por visita, nao compartilhado.
	}

	var STRUCTURAL_KEYS = [ 'Enter', 'Tab', 'Escape', 'Backspace', 'Delete',
		'ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight' ];

	var MAX_EVENTS = 500; // limite de memoria -- stats ja convergem bem antes disso

	var startedAt            = performance.now();
	var lastEventAt          = null;   // qualquer evento (inclui scroll)
	var lastActionAt         = null;   // acao deliberada (exclui scroll)
	var firstActionAt        = null;
	var ieis                 = [];     // todos os eventos -- so estatistica descritiva
	var ieisAcao             = [];     // sem scroll -- base do timing_regular_demais
	var clicksX              = [];
	var clicksY              = [];
	var clickTopCount        = 0;
	var linkClicks           = 0;
	var totalClicks          = 0;
	var totalScrolls         = 0;
	var totalKeydowns        = 0;
	var totalFocus           = 0;
	var totalInputs          = 0;
	var structuralKeydowns   = 0;
	var printableKeydowns    = 0;
	var scrollDepths         = [];
	var mouseMoved           = false;
	var firstClickHadMouseMove = null;
	var flushed              = false;

	// Elemento que realmente rola no documento -- document.documentElement
	// assume standards mode; scrollingElement nao tem essa dependencia e
	// custa o mesmo (achado em revisao externa, 2026-09-13).
	var scrollEl = document.scrollingElement || document.documentElement;

	// Contadores de isTrusted=false por TIPO de evento. Um booleano unico
	// (como em v1) nao distingue "agente clicou via JS" de "gerenciador de
	// senha preencheu o campo", e as duas coisas nao valem a mesma evidencia.
	var naoConfiavel = { click: 0, mousedown: 0, keydown: 0, input: 0, beforeinput: 0 };

	// Caminho do mouse desde o ultimo clique -- zerado a cada clique. Serve
	// pra distinguir movimento humano (dezenas de pontos, trajetoria curva,
	// tremor motor) de movimento sintetico (poucos pontos, quase uma linha
	// reta) que ferramentas "educadas" de automacao geram pra simular
	// mousemove sem serem realmente humanas. So guarda coordenadas, nunca
	// conteudo da pagina.
	var MAX_PATH_POINTS = 200;
	var pathPoints = [];
	var firstClickPathPoints = null;
	var firstClickStraightness = null;
	var firstClickStraightLineDist = null;

	// Distancia total percorrida pelo mouse na SESSAO inteira (nao reinicia a
	// cada clique, diferente de pathPoints acima). Achado na literatura
	// (FP-Agent/"Whose Agent Are You?"): agente baseado em coordenada de
	// pixel percorre uma distancia acumulada bem maior que um agente baseado
	// em referencia de acessibilidade, pra a mesma tarefa. So observacional
	// por enquanto -- sem limiar definido, mesmo padrao de click_x_std.
	var totalMouseDistPx = 0;
	var lastMouseX = null;
	var lastMouseY = null;

	// Duracao do clique/tecla (mousedown->mouseup, keydown->keyup) -- achado
	// fazendo engenharia reversa ao vivo no Claude no Chrome em 2026-09-13:
	// o clique dele tem ~2-6ms entre pressionar e soltar, e a digitacao
	// ~0.3-0.5ms entre tecla e tecla. Mao humana nunca faz isso -- o tempo
	// minimo de um clique deliberado fica na casa de dezenas de ms (contracao
	// muscular + atuacao mecanica do botao), e digitacao rapida de verdade
	// raramente passa de ~8-10 teclas/segundo (~100ms/tecla). Nao captura
	// qual tecla foi solta, so o timestamp.
	var lastMousedownAt = null;   // o mousedown DAQUELE clique, nao o 1o da pagina
	var firstClickDwellMs = null;
	var firstClickFromTouch = null;
	var keyDwellMs = [];
	var keydownPorTecla = Object.create( null );
	var MAX_KEY_DWELL_SAMPLES = 50;

	// Toque sintetiza mousedown/mouseup/click no mesmo turno, com o MESMO
	// timeStamp -- dwell sai exatamente 0, que e um valor "< 20ms" legitimo
	// desde que a v2 parou de clampar dwell negativo em 0 (correcao certa,
	// pelo motivo certo -- so que 0 tambem e o que o mobile emite de
	// verdade). Confirmado em producao: 9 de 9 cliques em sessao com toque
	// tinham dwell=0. Um toque nos ~1s antes do clique desarma a heuristica
	// de duracao pra aquele clique especifico.
	var lastTouchEndAt = null;
	window.addEventListener( 'touchend', function ( e ) {
		lastTouchEndAt = e.timeStamp;
	}, { passive: true } );

	window.addEventListener( 'mousedown', function ( e ) {
		lastMousedownAt = e.timeStamp;
		if ( e.isTrusted === false ) { naoConfiavel.mousedown++; }
	}, { passive: true, capture: true } );

	function markEvent( ehScroll ) {
		var now = performance.now();
		if ( firstActionAt === null ) { firstActionAt = now - startedAt; }

		if ( lastEventAt !== null && ieis.length < MAX_EVENTS ) {
			ieis.push( now - lastEventAt );
		}
		lastEventAt = now;

		if ( ! ehScroll ) {
			if ( lastActionAt !== null && ieisAcao.length < MAX_EVENTS ) {
				ieisAcao.push( now - lastActionAt );
			}
			lastActionAt = now;
		}
	}

	window.addEventListener( 'mousemove', function ( e ) {
		mouseMoved = true;
		if ( lastMouseX !== null ) {
			totalMouseDistPx += Math.hypot( e.clientX - lastMouseX, e.clientY - lastMouseY );
		}
		lastMouseX = e.clientX;
		lastMouseY = e.clientY;
		if ( pathPoints.length < MAX_PATH_POINTS ) {
			pathPoints.push( { x: e.clientX, y: e.clientY } );
		}
	}, { passive: true } );

	function pathLength( pts ) {
		var total = 0;
		for ( var i = 1; i < pts.length; i++ ) {
			total += Math.hypot( pts[ i ].x - pts[ i - 1 ].x, pts[ i ].y - pts[ i - 1 ].y );
		}
		return total;
	}

	window.addEventListener( 'click', function ( e ) {
		markEvent( false );
		totalClicks++;
		if ( e.isTrusted === false ) { naoConfiavel.click++; }
		if ( firstClickHadMouseMove === null ) {
			firstClickHadMouseMove = mouseMoved;

			// Bruto de proposito, inclusive negativo: evento sintetico pode
			// trazer timeStamp anterior ao mousedown, e clampar isso em 0
			// fazia o valor passar o teste de "< 20ms" por artefato.
			firstClickDwellMs = lastMousedownAt !== null ? ( e.timeStamp - lastMousedownAt ) : null;
			firstClickFromTouch = lastTouchEndAt !== null && ( e.timeStamp - lastTouchEndAt ) < 1000;

			// So calcula pra quem realmente teve mousemove -- senao ja cai em
			// clique_sem_mousemove, sinal mais forte e mais barato.
			if ( mouseMoved && pathPoints.length >= 2 ) {
				var reta = Math.hypot(
					pathPoints[ pathPoints.length - 1 ].x - pathPoints[ 0 ].x,
					pathPoints[ pathPoints.length - 1 ].y - pathPoints[ 0 ].y
				);
				firstClickPathPoints      = pathPoints.length;
				firstClickStraightLineDist = reta;
				firstClickStraightness    = reta > 0 ? pathLength( pathPoints ) / reta : null;
			}
		}
		pathPoints = []; // reinicia o caminho pro proximo clique

		if ( clicksX.length < MAX_EVENTS ) {
			clicksX.push( e.clientX );
			clicksY.push( e.clientY );
			if ( e.clientY < window.innerHeight * 0.25 ) { clickTopCount++; }
		}
		var el = e.target;
		if ( el && el.closest && el.closest( 'a[href]' ) ) { linkClicks++; }
	}, { passive: true, capture: true } );

	// Posicao absoluta em pixels, alem do percentual -- necessario pra
	// heuristica de multiplo de viewport abaixo (percentual sozinho nao
	// revela isso, porque a mesma "distancia em telas" vira uma % diferente
	// em cada pagina dependendo do tamanho do artigo).
	var scrollDepthsPx = [];

	// PARADAS de scroll: posicao onde o scroll ficou parado >=150ms. E isso
	// que distingue mecanismo de passo fixo (paradas em 1x, 2x, 3x a tela) de
	// coincidencia (uma parada isolada perto de um multiplo).
	var MAX_SCROLL_STOPS = 100;
	var scrollStops = [];
	var scrollStopTimer = null;
	var scrollStopPendente = false;

	// Extraida do setTimeout pra poder ser chamada tambem de flush(): uma
	// saida rapida de pagina (o padrao de um agente, ironicamente) pode
	// acontecer ANTES dos 150ms de debounce -- o timer pendente nunca dispara
	// sozinho porque a pagina esta sendo desmontada, e a posicao final (a
	// mais provavel de ser a parada que importa) some silenciosamente.
	function registraParada() {
		scrollStopPendente = false;
		if ( scrollStops.length < MAX_SCROLL_STOPS ) {
			scrollStops.push( scrollEl.scrollTop );
		}
	}

	window.addEventListener( 'scroll', function () {
		markEvent( true );
		totalScrolls++;
		var max = ( scrollEl.scrollHeight - scrollEl.clientHeight ) || 1;
		if ( scrollDepths.length < MAX_EVENTS ) {
			scrollDepths.push( Math.min( 100, Math.max( 0, ( scrollEl.scrollTop / max ) * 100 ) ) );
		}
		if ( scrollDepthsPx.length < MAX_EVENTS ) { scrollDepthsPx.push( scrollEl.scrollTop ); }

		if ( scrollStopTimer ) { clearTimeout( scrollStopTimer ); }
		scrollStopPendente = true;
		scrollStopTimer = setTimeout( registraParada, 150 );
	}, { passive: true } );

	window.addEventListener( 'keydown', function ( e ) {
		// Segurar uma tecla (Arrow/PageDown pra rolar um artigo longo, por
		// exemplo) gera keydown repetido pelo SO em intervalo fixo, com um so
		// keyup no final -- comportamento humano banal que, sem este corte,
		// disparava digitacao_impossivel (dwell = ultimo keydown ate o unico
		// keyup, artificialmente baixo) E timing_regular_demais (intervalos
		// quase identicos entre as repeticoes).
		if ( e.repeat ) { return; }

		markEvent( false );
		totalKeydowns++;
		if ( e.isTrusted === false ) { naoConfiavel.keydown++; }
		if ( STRUCTURAL_KEYS.indexOf( e.key ) !== -1 ) {
			structuralKeydowns++;
		} else if ( e.key && e.key.length === 1 ) {
			printableKeydowns++;
		}
		// Pareamento por tecla, nao por variavel global: digitacao humana
		// rapida tem rollover (keydown a -> keydown b -> keyup a), e uma
		// variavel unica produzia dwell minusculo ou negativo nesse caso.
		keydownPorTecla[ e.code || e.key ] = e.timeStamp;
	}, { passive: true, capture: true } );

	window.addEventListener( 'keyup', function ( e ) {
		var chave = e.code || e.key;
		var inicio = keydownPorTecla[ chave ];
		if ( inicio !== undefined ) {
			var dwell = e.timeStamp - inicio;
			if ( dwell >= 0 && keyDwellMs.length < MAX_KEY_DWELL_SAMPLES ) {
				keyDwellMs.push( dwell );
			}
			delete keydownPorTecla[ chave ];
		}
	}, { passive: true, capture: true } );

	window.addEventListener( 'focusin', function ( e ) {
		var tag = e.target && e.target.tagName;
		if ( tag === 'INPUT' || tag === 'TEXTAREA' ) {
			markEvent( false );
			totalFocus++;
		}
	}, { passive: true } );

	// Cobre o caso que nenhum listener acima ve: um agente que preenche um
	// campo direto (elemento.value = "x" + dispatchEvent) em vez de simular
	// tecla por tecla -- confirmado no Manus (n_inputs=3, n_keydowns=0). So
	// conta ocorrencia e isTrusted -- nunca le o valor preenchido.
	//
	// ATENCAO na interpretacao: gerenciador de senha (1Password, Bitwarden,
	// LastPass), tradutor e extensao de acessibilidade tambem preenchem campo
	// via script e tambem chegam com isTrusted=false. Por isso este sinal e
	// um motivo SEPARADO do clique -- input sintetico e ambiguo por natureza,
	// clique sintetico em link/botao nao e.
	window.addEventListener( 'beforeinput', function ( e ) {
		if ( e.isTrusted === false ) { naoConfiavel.beforeinput++; }
	}, { passive: true, capture: true } );

	window.addEventListener( 'input', function ( e ) {
		markEvent( false );
		totalInputs++;
		if ( e.isTrusted === false ) { naoConfiavel.input++; }
	}, { passive: true, capture: true } );

	function mean( arr ) {
		if ( ! arr.length ) { return null; }
		var s = 0;
		for ( var i = 0; i < arr.length; i++ ) { s += arr[ i ]; }
		return s / arr.length;
	}

	function std( arr, m ) {
		if ( arr.length < 2 || m === null ) { return null; }
		var s = 0;
		for ( var i = 0; i < arr.length; i++ ) { s += Math.pow( arr[ i ] - m, 2 ); }
		return Math.sqrt( s / arr.length );
	}

	function percentile( arr, p ) {
		if ( ! arr.length ) { return null; }
		var sorted = arr.slice().sort( function ( a, b ) { return a - b; } );
		var idx    = Math.min( sorted.length - 1, Math.floor( p * sorted.length ) );
		return sorted[ idx ];
	}

	// Paradas de scroll que caem em multiplo inteiro da altura da janela,
	// excluindo o fim do documento. Tolerancia em PIXEL absoluto: a antiga
	// (+-2% do multiplo) dava +-20px num viewport de 1000px, ou seja ~4% de
	// chance de acerto aleatorio por pagina -- alto demais pra medir um
	// fenomeno de poucos por cento.
	var TOL_PX = 4;

	function contaParadasEmMultiplo() {
		var vh = window.innerHeight;
		if ( ! vh ) { return { total: 0, multiplos: 0, maiorMultiplo: 0 }; }

		var docMax = ( scrollEl.scrollHeight - scrollEl.clientHeight ) || 0;

		var multiplos = 0;
		var maior     = 0;

		for ( var i = 0; i < scrollStops.length; i++ ) {
			var pos = scrollStops[ i ];

			// Parada no fim do documento nao e evidencia de nada: e onde
			// qualquer leitor que terminou a pagina para.
			if ( docMax > 0 && Math.abs( pos - docMax ) <= TOL_PX ) { continue; }

			var k = Math.round( pos / vh );
			if ( k >= 1 && Math.abs( pos - k * vh ) <= TOL_PX ) {
				multiplos++;
				if ( k > maior ) { maior = k; }
			}
		}

		return { total: scrollStops.length, multiplos: multiplos, maiorMultiplo: maior };
	}

	// Sinais de automacao baratos de calcular, sem nenhum classificador --
	// servem so pra decidir SE vale enviar o beacon. Pageview onde nada
	// disso dispara nao gera trafego nenhum.
	function heuristicaAutomacao( paradas ) {
		var motivos = [];

		if ( navigator.webdriver === true ) { motivos.push( 'webdriver' ); }

		if ( totalClicks >= 1 && firstClickHadMouseMove === false ) {
			motivos.push( 'clique_sem_mousemove' );
		}

		// Pega o caso "educado": a ferramenta simula mousemove (nao cai no
		// motivo acima), mas o caminho e sintetico -- poucos pontos ou quase
		// uma linha reta ate o alvo, cobrindo distancia grande demais pra
		// ser coincidencia.
		if ( firstClickHadMouseMove === true && firstClickPathPoints !== null &&
			firstClickStraightLineDist !== null && firstClickStraightLineDist >= 60 ) {
			var poucosPontos = firstClickPathPoints <= 3;
			var quaseReta    = firstClickStraightness !== null && firstClickStraightness < 1.03;
			if ( poucosPontos || quaseReta ) {
				motivos.push( 'movimento_mouse_sintetico' );
			}
		}

		// SEM scroll: evento de scroll e entregue atrelado ao frame (~16,7ms),
		// entao um unico flick continuo de trackpad/celular gerava um fluxo
		// quase uniforme e podia render CV < 0,05 sem nenhuma automacao.
		var mAcao = mean( ieisAcao );
		var sAcao = std( ieisAcao, mAcao );
		if ( mAcao !== null && sAcao !== null && mAcao > 0 && ( sAcao / mAcao ) < 0.05 && ieisAcao.length >= 5 ) {
			motivos.push( 'timing_regular_demais' );
		}

		var vpAutomacaoComum = ( window.innerWidth === 1280 && window.innerHeight === 768 ) ||
			( window.innerWidth === 1920 && window.innerHeight === 1080 ) ||
			( window.innerWidth === 800 && window.innerHeight === 600 );
		if ( vpAutomacaoComum && navigator.plugins && navigator.plugins.length === 0 ) {
			motivos.push( 'viewport_automacao_sem_plugins' );
		}

		if ( navigator.languages && navigator.languages.length === 0 ) {
			motivos.push( 'sem_idiomas' );
		}

		// Limiares bem abaixo do minimo humano plausivel. Exige dwell >= 0 e
		// que o clique nao tenha vindo de um toque de tela (compat events de
		// touch sintetizam mousedown/mouseup/click no mesmo turno, com o
		// mesmo timeStamp -- dwell sai exatamente 0, confirmado em 9 de 9
		// cliques touch na base de producao). Valor negativo indica
		// timeStamp inconsistente de evento sintetico, ja coberto por
		// clique_nao_confiavel -- nao deve entrar aqui disfarcado de
		// "clique rapido".
		if ( firstClickDwellMs !== null && firstClickDwellMs >= 0 && firstClickDwellMs < 20 && ! firstClickFromTouch ) {
			motivos.push( 'clique_duracao_impossivel' );
		}

		if ( keyDwellMs.length >= 2 ) {
			var mKeyDwell = mean( keyDwellMs );
			if ( mKeyDwell !== null && mKeyDwell < 15 ) {
				motivos.push( 'digitacao_impossivel' );
			}
		}

		// Achado ao vivo no Perplexity Comet em 2026-09-13 e confirmado nos
		// dados depois: 5 artigos de tamanhos diferentes pararam todos em
		// exatamente 3604px (4 x 901px de viewport) com max_scroll_pct entre
		// 38,9% e 49,9% -- ou seja, parou na METADE de cada artigo, nao no
		// fim. Fim de documento daria percentual identico (100%) e pixel
		// diferente; passo fixo da o oposto. Mecanismo confirmado.
		//
		// Rodadas posteriores, com prompt pedindo "role ate o final", deram
		// max_scroll_pct=100 e zero multiplo: o mesmo produto tem mais de um
		// caminho de execucao, roteado pelo pedido. Por isso este sinal exige
		// parada fora do fim do documento e o painel pede repeticao em 2+
		// paginas da sessao antes de tratar como evidencia.
		if ( paradas.multiplos >= 1 && paradas.maiorMultiplo >= 2 ) {
			motivos.push( 'scroll_multiplo_viewport' );
		}

		// Tipados de proposito -- ver comentario no listener de input.
		if ( naoConfiavel.click > 0 || naoConfiavel.mousedown > 0 ) {
			motivos.push( 'clique_nao_confiavel' );
		}
		if ( naoConfiavel.keydown > 0 ) {
			motivos.push( 'tecla_nao_confiavel' );
		}
		if ( naoConfiavel.input > 0 || naoConfiavel.beforeinput > 0 ) {
			motivos.push( 'input_nao_confiavel' );
		}

		return motivos;
	}

	function montaPayload( motivos, paradas ) {
		var m = mean( ieis );
		var s = std( ieis, m );
		var mAcao = mean( ieisAcao );

		return {
			trace_id: pageviewId,          // id do PAGEVIEW, gerado no cliente
			render_id: cfg.traceId || '',  // uuid do servidor -- cacheavel de proposito
			session_id: sess.id,
			page_index: sess.index,
			ms_since_prev_page: sess.msSincePrev,
			sampled: sess.sampled,
			session_degradada: sess.degradado,
			url_path: location.pathname,
			motivos: motivos,
			viewport_w: window.innerWidth,
			viewport_h: window.innerHeight,
			screen_w: screen.width,
			screen_h: screen.height,
			touch_points: navigator.maxTouchPoints || 0,
			plugins_count: navigator.plugins ? navigator.plugins.length : 0,
			languages: ( navigator.languages || [] ).join( ',' ),
			timezone_offset_min: new Date().getTimezoneOffset(),
			hardware_concurrency: navigator.hardwareConcurrency || 0,
			navigator_webdriver: navigator.webdriver === true,
			n_clicks: totalClicks,
			n_scrolls: totalScrolls,
			n_keydowns: totalKeydowns,
			n_focus: totalFocus,
			t_first_action_ms: firstActionAt !== null ? Math.round( firstActionAt ) : null,
			mean_iei_ms: m,
			std_iei_ms: s,
			p10_iei_ms: percentile( ieis, 0.10 ),
			p90_iei_ms: percentile( ieis, 0.90 ),
			mean_iei_acao_ms: mAcao,
			std_iei_acao_ms: std( ieisAcao, mAcao ),
			click_x_std: std( clicksX, mean( clicksX ) ),
			click_y_std: std( clicksY, mean( clicksY ) ),
			click_top_frac: totalClicks > 0 ? clickTopCount / totalClicks : null,
			link_click_ratio: totalClicks > 0 ? linkClicks / totalClicks : null,
			structural_key_ratio: ( structuralKeydowns + printableKeydowns ) > 0
				? structuralKeydowns / ( structuralKeydowns + printableKeydowns )
				: null,
			max_scroll_pct: scrollDepths.length ? Math.max.apply( null, scrollDepths ) : null,
			mean_scroll_pct: mean( scrollDepths ),
			had_mousemove_before_first_click: firstClickHadMouseMove,
			first_click_path_points: firstClickPathPoints,
			first_click_straightness: firstClickStraightness,
			first_click_dwell_ms: firstClickDwellMs,
			mean_key_dwell_ms: keyDwellMs.length ? mean( keyDwellMs ) : null,
			max_scroll_px: scrollDepthsPx.length ? Math.max.apply( null, scrollDepthsPx ) : null,
			doc_scroll_max_px: Math.max( 0, ( scrollEl.scrollHeight - scrollEl.clientHeight ) || 0 ),
			n_scroll_stops: paradas.total,
			n_scroll_stops_multiplo: paradas.multiplos,
			n_inputs: totalInputs,
			// Um evento sintetico costuma disparar o par inteiro (mousedown+click,
			// beforeinput+input), entao somar os dois inflava a contagem em ate 2x.
			// O contador guarda so o evento final -- o motivo continua olhando o
			// par (ver acima), pra nao perder o caso de mousedown/beforeinput
			// sintetico sem o evento final correspondente.
			n_untrusted_click: naoConfiavel.click,
			n_untrusted_key: naoConfiavel.keydown,
			n_untrusted_input: naoConfiavel.input,
			total_mouse_dist_px: Math.round( totalMouseDistPx ),
			ruleset_version: RULESET_VERSION
		};
	}

	// Assinatura barata do que ja foi enviado -- evita reenviar um beacon
	// identico quando visibilitychange dispara mais de uma vez sem nenhuma
	// novidade (ex.: usuario troca de aba varias vezes sem interagir).
	var lastSentSignature = null;
	function assinaturaAtual() {
		return totalClicks + '|' + totalScrolls + '|' + totalKeydowns + '|' + totalInputs;
	}

	// flush(final): `final` marca um gatilho terminal (pagehide/beforeunload)
	// -- so esses travam `flushed`. visibilitychange e reenviavel: trocar de
	// aba e comportamento humano banal (muito mais comum em humano lendo um
	// artigo do que num agente executando uma tarefa unica em foco continuo),
	// e travar no primeiro envio truncava sistematicamente esse lado da
	// comparacao (menos clique, menos scroll, menos distancia de mouse do
	// que a visita real teve). O servidor faz upsert por trace_id -- cada
	// reenvio substitui a linha anterior da MESMA pageview, nunca duplica.
	function flush( final ) {
		if ( flushed ) { return; }

		// Pageview sem nenhuma interacao nao entra -- nem como deteccao nem
		// como baseline. O criterio e o MESMO dos dois lados de proposito: a
		// taxa que o painel calcula e "entre pageviews com alguma interacao",
		// e so se mantem honesta se numerador e denominador excluirem
		// exatamente a mesma coisa. Consequencia conhecida e documentada:
		// agente puramente leitor (caso Manus etapa 1) e invisivel aqui.
		if ( totalClicks === 0 && totalScrolls === 0 && totalKeydowns === 0 && totalInputs === 0 ) { return; }

		// A propria saida da pagina e evidencia de que o scroll parou ali --
		// resolve na mao o que o debounce nao teve tempo de resolver sozinho
		// (ver comentario em registraParada). So age se o timer ainda nao
		// tiver disparado por conta propria (scrollStopPendente evita
		// registrar a mesma parada duas vezes).
		//
		// Residuo aceito: flush() tambem roda em 'visibilitychange' (troca de
		// aba), que pode acontecer no MEIO de uma rolagem continua -- nesse
		// caso a "parada" registrada aqui e so um instantaneo de passagem, nao
		// uma parada de verdade. Quantificado e considerado baixo risco: com
		// tolerancia de 4px num viewport de ~900px, uma posicao arbitraria cai
		// num multiplo por coincidencia em ~1% dos casos, e o motivo ainda
		// exige >=2x de multiplo pra disparar. Nao vale trocar por "nao
		// registrar nada na saida" -- isso perderia o caso mais frequente dos
		// dois, que e a saida rapida de pagina que este fix corrige acima.
		if ( scrollStopPendente ) {
			clearTimeout( scrollStopTimer );
			registraParada();
		}

		if ( ! final ) {
			var assinatura = assinaturaAtual();
			if ( assinatura === lastSentSignature ) { return; }
			lastSentSignature = assinatura;
		}

		var paradas = contaParadasEmMultiplo();
		var motivos = heuristicaAutomacao( paradas );

		// Sem motivo e fora da amostra: comportamento normal, nada enviado.
		if ( motivos.length === 0 && ! sess.sampled ) { return; }

		if ( final ) { flushed = true; }
		var body = JSON.stringify( montaPayload( motivos, paradas ) );
		if ( navigator.sendBeacon ) {
			navigator.sendBeacon( cfg.endpoint, new Blob( [ body ], { type: 'text/plain' } ) );
		} else {
			fetch( cfg.endpoint, { method: 'POST', body: body, keepalive: true } );
		}
	}

	document.addEventListener( 'visibilitychange', function () {
		if ( document.visibilityState === 'hidden' ) { flush( false ); }
	} );
	window.addEventListener( 'pagehide', function () { flush( true ); } );

	// 'beforeunload' como terceiro gatilho -- achado ao vivo em 2026-09-12:
	// navegacao disparada pela extensao Claude no Chrome (via clique real num
	// link OU via troca de URL programatica) NAO dispara 'pagehide' nem
	// 'visibilitychange' no documento que sai, mas 'beforeunload' dispara
	// sempre (confirmado com probes via Image() em 3 repeticoes seguidas).
	// Sem isso, exatamente o trafego que o sensor existe pra medir era o que
	// mais escapava da captura. flush() e idempotente (guarda `flushed`),
	// entao ter 3 gatilhos nao gera beacon duplicado.
	//
	// CUSTO CONHECIDO: 'unload' inviabiliza o back/forward cache em
	// Chrome/Firefox e nao e usado aqui; 'beforeunload' e tolerado nesses
	// dois, mas o Safari historicamente exclui do cache paginas que registram
	// esse listener. Trade-off aceito de propria vontade: sem ele o sensor
	// perde a maior parte da navegacao agentica. Reavaliar se o site passar a
	// depender de navegacao "voltar" pra metrica de negocio.
	window.addEventListener( 'beforeunload', function () { flush( true ); } );
})();
