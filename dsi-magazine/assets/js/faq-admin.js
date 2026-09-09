/**
 * Meta box "FAQ (Perguntas Frequentes)" — transforma o texto bruto colado em
 * boxes de pergunta/resposta editáveis individualmente, e mantém o textarea
 * escondido (dsi_faq_raw, o campo que o save_post realmente lê) sincronizado
 * a cada edição. Espelha dsi_parse_faq()/formato esperado do lado PHP
 * (functions.php) — qualquer mudança na regra de parse precisa refletir aqui
 * também.
 */
( function () {
	function parseFaqRaw( raw ) {
		var lines = raw.replace( /\r\n/g, '\n' ).split( '\n' ).map( function ( l ) {
			return l.trim();
		} );
		var pairs = [];
		var question = null;
		var paragraphs = [];
		var buffer = [];

		function flushBuffer() {
			if ( buffer.length ) {
				paragraphs.push( buffer.join( ' ' ) );
				buffer = [];
			}
		}

		lines.forEach( function ( line ) {
			if ( line !== '' && line.slice( -1 ) === '?' ) {
				flushBuffer();
				if ( question !== null && paragraphs.length ) {
					pairs.push( { question: question, answer: paragraphs.join( '\n\n' ) } );
				}
				question = line;
				paragraphs = [];
				return;
			}
			if ( question === null ) {
				return;
			}
			if ( line === '' ) {
				flushBuffer();
				return;
			}
			buffer.push( line );
		} );
		flushBuffer();
		if ( question !== null && paragraphs.length ) {
			pairs.push( { question: question, answer: paragraphs.join( '\n\n' ) } );
		}
		return pairs;
	}

	function serializeFaqPairs( pairs ) {
		return pairs
			.filter( function ( p ) {
				return p.question.trim() !== '' && p.answer.trim() !== '';
			} )
			.map( function ( p ) {
				var q = p.question.trim();
				var qLine = q.slice( -1 ) === '?' ? q : q + '?';
				return qLine + '\n\n' + p.answer.trim();
			} )
			.join( '\n\n' );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		var hidden = document.getElementById( 'dsi-faq-raw-hidden' );
		var cardsWrap = document.getElementById( 'dsi-faq-cards' );
		var pasteBox = document.getElementById( 'dsi-faq-paste' );
		var convertBtn = document.getElementById( 'dsi-faq-convert' );
		var addBtn = document.getElementById( 'dsi-faq-add' );

		if ( ! hidden || ! cardsWrap ) {
			return;
		}

		function sync() {
			var pairs = Array.prototype.map.call( cardsWrap.querySelectorAll( '.dsi-faq-card' ), function ( card ) {
				return {
					question: card.querySelector( '.dsi-faq-card__q' ).value,
					answer: card.querySelector( '.dsi-faq-card__a' ).value,
				};
			} );
			hidden.value = serializeFaqPairs( pairs );
		}

		function addCard( question, answer ) {
			var card = document.createElement( 'div' );
			card.className = 'dsi-faq-card';
			card.style.cssText = 'border:1px solid #dcdcde;border-radius:4px;padding:10px;margin-bottom:10px;background:#fff';
			card.innerHTML =
				'<input type="text" class="dsi-faq-card__q" placeholder="Pergunta (termine com ?)" style="width:100%;font-weight:600;margin-bottom:6px" />' +
				'<textarea class="dsi-faq-card__a" rows="3" placeholder="Resposta" style="width:100%;font-family:inherit"></textarea>' +
				'<button type="button" class="button dsi-faq-card__remove" style="margin-top:6px">Remover pergunta</button>';
			card.querySelector( '.dsi-faq-card__q' ).value = question || '';
			card.querySelector( '.dsi-faq-card__a' ).value = answer || '';
			card.addEventListener( 'input', sync );
			card.querySelector( '.dsi-faq-card__remove' ).addEventListener( 'click', function () {
				card.remove();
				sync();
			} );
			cardsWrap.appendChild( card );
			return card;
		}

		// Estado inicial: se já existe conteúdo salvo, transforma em boxes na hora.
		if ( hidden.value.trim() !== '' ) {
			parseFaqRaw( hidden.value ).forEach( function ( p ) {
				addCard( p.question, p.answer );
			} );
		}

		if ( convertBtn && pasteBox ) {
			convertBtn.addEventListener( 'click', function () {
				var pairs = parseFaqRaw( pasteBox.value );
				pairs.forEach( function ( p ) {
					addCard( p.question, p.answer );
				} );
				pasteBox.value = '';
				sync();
			} );
		}

		if ( addBtn ) {
			addBtn.addEventListener( 'click', function () {
				addCard( '', '' ).querySelector( '.dsi-faq-card__q' ).focus();
			} );
		}

		sync();
	} );
} )();
