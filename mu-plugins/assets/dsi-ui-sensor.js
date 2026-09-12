(function () {
	'use strict';

	// Sensor de comportamento de UI -- detecta se quem esta navegando e um
	// agente de IA pilotando o navegador de verdade (tipo computer-use/
	// Midscene.js), nao um humano nem um crawler HTTP comum. So envia dados
	// quando pelo menos um sinal de automacao dispara: pageview normal de
	// humano nunca gera nenhum tráfego nem linha no banco. Nunca captura o
	// CARACTERE digitado -- so a classificacao estrutural/imprimivel e o
	// timing (ver mu-plugins/dsi-ui-sensor.php).

	if ( window.__dsiUiSensorLoaded ) { return; }
	window.__dsiUiSensorLoaded = true;

	var cfg = window.dsiUiSensor || {};
	if ( ! cfg.endpoint ) { return; }

	var STRUCTURAL_KEYS = [ 'Enter', 'Tab', 'Escape', 'Backspace', 'Delete',
		'ArrowUp', 'ArrowDown', 'ArrowLeft', 'ArrowRight' ];

	var MAX_EVENTS = 500; // limite de memoria -- stats ja convergem bem antes disso

	var startedAt            = performance.now();
	var lastEventAt          = null;
	var firstActionAt        = null;
	var ieis                 = [];
	var clicksX              = [];
	var clicksY              = [];
	var clickTopCount        = 0;
	var linkClicks           = 0;
	var totalClicks          = 0;
	var totalScrolls         = 0;
	var totalKeydowns        = 0;
	var totalFocus           = 0;
	var structuralKeydowns   = 0;
	var printableKeydowns    = 0;
	var scrollDepths         = [];
	var mouseMoved           = false;
	var firstClickHadMouseMove = null;
	var flushed              = false;

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

	function markEvent() {
		var now = performance.now();
		if ( firstActionAt === null ) { firstActionAt = now - startedAt; }
		if ( lastEventAt !== null && ieis.length < MAX_EVENTS ) {
			ieis.push( now - lastEventAt );
		}
		lastEventAt = now;
	}

	window.addEventListener( 'mousemove', function ( e ) {
		mouseMoved = true;
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
		markEvent();
		totalClicks++;
		if ( firstClickHadMouseMove === null ) {
			firstClickHadMouseMove = mouseMoved;

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

	window.addEventListener( 'scroll', function () {
		markEvent();
		totalScrolls++;
		var doc = document.documentElement;
		var max = ( doc.scrollHeight - doc.clientHeight ) || 1;
		scrollDepths.push( Math.min( 100, Math.max( 0, ( doc.scrollTop / max ) * 100 ) ) );
	}, { passive: true } );

	window.addEventListener( 'keydown', function ( e ) {
		markEvent();
		totalKeydowns++;
		if ( STRUCTURAL_KEYS.indexOf( e.key ) !== -1 ) {
			structuralKeydowns++;
		} else if ( e.key && e.key.length === 1 ) {
			printableKeydowns++;
		}
	}, { passive: true, capture: true } );

	window.addEventListener( 'focusin', function ( e ) {
		var tag = e.target && e.target.tagName;
		if ( tag === 'INPUT' || tag === 'TEXTAREA' ) {
			markEvent();
			totalFocus++;
		}
	}, { passive: true } );

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

	// Sinais de automacao baratos de calcular, sem nenhum classificador --
	// servem so pra decidir SE vale enviar o beacon. Pageview onde nada
	// disso dispara nao gera trafego nenhum.
	function heuristicaAutomacao() {
		var motivos = [];

		if ( navigator.webdriver === true ) { motivos.push( 'webdriver' ); }

		if ( totalClicks >= 1 && firstClickHadMouseMove === false ) {
			motivos.push( 'clique_sem_mousemove' );
		}

		// Pega o caso "educado": a ferramenta simula mousemove (nao cai no
		// motivo acima), mas o caminho e sintetico -- poucos pontos ou quase
		// uma linha reta ate o alvo, cobrindo distancia grande demais pra
		// ser coincidencia. Mouse humano de verdade tem dezenas de pontos
		// (a maioria dos navegadores reporta mousemove em alta frequencia) e
		// trajetoria com curvatura (tremor motor), raramente perto de 1.0.
		if ( firstClickHadMouseMove === true && firstClickPathPoints !== null &&
			firstClickStraightLineDist !== null && firstClickStraightLineDist >= 60 ) {
			var poucosPontos = firstClickPathPoints <= 3;
			var quaseReta    = firstClickStraightness !== null && firstClickStraightness < 1.03;
			if ( poucosPontos || quaseReta ) {
				motivos.push( 'movimento_mouse_sintetico' );
			}
		}

		var m = mean( ieis );
		var s = std( ieis, m );
		if ( m !== null && s !== null && m > 0 && ( s / m ) < 0.05 && ieis.length >= 5 ) {
			motivos.push( 'timing_regular_demais' );
		}

		var vpAutomacaoComum = ( window.innerWidth === 1280 && window.innerHeight === 768 ) ||
			( window.innerWidth === 1920 && window.innerHeight === 1080 ) ||
			( window.innerWidth === 800 && window.innerHeight === 600 );
		if ( vpAutomacaoComum && navigator.plugins.length === 0 ) {
			motivos.push( 'viewport_automacao_sem_plugins' );
		}

		if ( navigator.languages && navigator.languages.length === 0 ) {
			motivos.push( 'sem_idiomas' );
		}

		return motivos;
	}

	function montaPayload( motivos ) {
		var m = mean( ieis );
		var s = std( ieis, m );

		return {
			trace_id: cfg.traceId,
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
			first_click_straightness: firstClickStraightness
		};
	}

	function flush() {
		if ( flushed ) { return; }
		if ( totalClicks === 0 && totalScrolls === 0 && totalKeydowns === 0 ) { return; }

		var motivos = heuristicaAutomacao();
		if ( motivos.length === 0 ) { return; } // comportamento normal -- nada enviado

		flushed = true;
		var body = JSON.stringify( montaPayload( motivos ) );
		if ( navigator.sendBeacon ) {
			navigator.sendBeacon( cfg.endpoint, new Blob( [ body ], { type: 'text/plain' } ) );
		} else {
			fetch( cfg.endpoint, { method: 'POST', body: body, keepalive: true } );
		}
	}

	document.addEventListener( 'visibilitychange', function () {
		if ( document.visibilityState === 'hidden' ) { flush(); }
	} );
	window.addEventListener( 'pagehide', flush );
})();
