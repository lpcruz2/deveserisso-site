( function () {
    'use strict';

    var buttons = document.querySelectorAll( '.dsi-aval-btn' );
    if ( ! buttons.length ) return;

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
            } );
        } );
        observer.observe( btn, { attributes: true, attributeFilter: [ 'class' ] } );
    } );
} )();
