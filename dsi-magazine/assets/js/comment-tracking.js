( function () {
    'use strict';

    var FLAG = 'dsiCommentPending';
    var form = document.getElementById( 'dsi-commentform' );

    // Marca a intenção de envio ANTES do reload nativo (sem preventDefault —
    // o form continua submetendo normalmente via POST para wp-comments-post.php).
    if ( form ) {
        form.addEventListener( 'submit', function () {
            try {
                sessionStorage.setItem( FLAG, '1' );
            } catch ( err ) { /* sessionStorage indisponível — sem tracking, sem quebra */ }
        } );
    }

    // Checa, nesta carga de página, se é o retorno de um submit real.
    var pending;
    try {
        pending = sessionStorage.getItem( FLAG );
    } catch ( err ) {
        pending = null;
    }

    if ( ! pending ) return;

    // Consumo único — limpa a flag independente do resultado, para não
    // vazar para a próxima navegação.
    try {
        sessionStorage.removeItem( FLAG );
    } catch ( err ) {}

    var hashMatchesComment = /^#comment-\d+$/.test( window.location.hash );
    var isModerationRedirect = /[?&]unapproved=\d+/.test( window.location.search );

    if ( ( hashMatchesComment || isModerationRedirect ) && window.dataLayer ) {
        window.dataLayer.push( { event: 'comment_success' } );
    }
} )();
