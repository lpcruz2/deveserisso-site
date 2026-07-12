( function () {
    'use strict';

    var form     = document.getElementById( 'dsi-nl-form' );
    var feedback = document.getElementById( 'dsi-nl-feedback' );

    if ( ! form || ! window.dsiNewsletter ) return;

    form.addEventListener( 'submit', function ( e ) {
        e.preventDefault();

        var emailInput = form.querySelector( 'input[type="email"]' );
        var email      = emailInput ? emailInput.value.trim() : '';
        if ( ! email ) {
            if ( e.agentInvoked ) e.respondWith( Promise.resolve( 'Validation failed: email (obrigatório)' ) );
            return;
        }

        var btn = form.querySelector( 'button[type="submit"]' );
        var originalLabel = btn.textContent;
        btn.disabled    = true;
        btn.textContent = '…';

        var data = new FormData();
        data.append( 'action', 'dsi_newsletter_subscribe' );
        data.append( 'nonce',  dsiNewsletter.nonce );
        data.append( 'email',  email );

        var resultPromise = fetch( dsiNewsletter.ajaxUrl, { method: 'POST', body: data } )
            .then( function ( r ) { return r.json(); } )
            .then( function ( res ) {
                if ( res.success ) {
                    form.style.display          = 'none';
                    feedback.style.display      = 'block';
                    feedback.style.color        = '#c2511d';
                    feedback.textContent        = res.data.message;
                } else {
                    feedback.style.display      = 'block';
                    feedback.style.color        = '#b00020';
                    feedback.textContent        = res.data.message;
                    btn.disabled                = false;
                    btn.textContent             = originalLabel;
                }
                return res.data.message;
            } )
            .catch( function () {
                feedback.style.display      = 'block';
                feedback.style.color        = '#b00020';
                feedback.textContent        = 'Erro de conexão. Tente novamente.';
                btn.disabled                = false;
                btn.textContent             = originalLabel;
                return 'Erro de conexão. Tente novamente.';
            } );

        if ( e.agentInvoked ) e.respondWith( resultPromise );
    } );
} )();
