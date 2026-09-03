( function () {
    'use strict';

    var wraps = document.querySelectorAll( '.dsi-aval-wrap' );
    if ( ! wraps.length ) return;

    wraps.forEach( function ( wrap ) {
        var buttons = wrap.querySelectorAll( '.dsi-aval-btn' );
        if ( ! buttons.length ) return;

        // O throttle server-side do mu-plugin (1 voto/hora/IP/tipo) não está
        // sendo aplicado na prática — trava client-side como mitigação: após
        // o 1º voto confirmado nesta página, desabilita os 3 botões do widget.
        // Não sobrevive a reload/nova aba (mesma limitação que a própria
        // classe 'voted' do mu-plugin já tem).
        function lockWrap() {
            buttons.forEach( function ( b ) { b.disabled = true; } );
        }

        buttons.forEach( function ( btn ) {
            var observer = new MutationObserver( function ( mutations ) {
                mutations.forEach( function ( mutation ) {
                    if ( mutation.attributeName !== 'class' ) return;
                    if ( ! btn.classList.contains( 'voted' ) ) return;
                    if ( btn.dataset.dsiTracked ) return; // já reportado — evita duplicar em mutações repetidas

                    btn.dataset.dsiTracked = '1';
                    if ( window.dataLayer ) {
                        window.dataLayer.push( {
                            event:          'avaliacao_filme',
                            avaliacao_tipo: btn.dataset.tipo || ''
                        } );
                    }
                    lockWrap();
                } );
            } );
            observer.observe( btn, { attributes: true, attributeFilter: [ 'class' ] } );
        } );
    } );
} )();
