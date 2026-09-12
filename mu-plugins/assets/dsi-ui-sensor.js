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

	function markEvent() {
		var now = performance.now();
		if ( firstActionAt === null ) { firstActionAt = now - startedAt; }
		if ( lastEventAt !== null && ieis.length < MAX_EVENTS ) {
			ieis.push( now - lastEventAt );
		}
		lastEventAt = now;
	}

	window.addEventListener( 'mousemove', function () {
		mouseMoved = true;
	}, { passive: true } );

	window.addEventListener( 'click', function ( e ) {
		markEvent();
		totalClicks++;
		if ( firstClickHadMouseMove === null ) { firstClickHadMouseMove = mouseMoved; }
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
			had_mousemove_before_first_click: firstClickHadMouseMove
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
