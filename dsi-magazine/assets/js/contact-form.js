( function () {
    'use strict';

    var form     = document.getElementById( 'dsi-contact-form' );
    var feedback = document.getElementById( 'dsi-contact-feedback' );

    if ( ! form || ! window.dsiContactForm ) return;

    form.addEventListener( 'submit', function ( e ) {
        e.preventDefault();

        var nome     = form.querySelector( '#dsi-contact-nome' ).value.trim();
        var email    = form.querySelector( '#dsi-contact-email' ).value.trim();
        var mensagem = form.querySelector( '#dsi-contact-mensagem' ).value.trim();

        if ( ! nome || ! email || ! mensagem ) return;

        var btn = form.querySelector( 'button[type="submit"]' );
        var originalLabel = btn.textContent;
        btn.disabled    = true;
        btn.textContent = '…';

        var data = new FormData();
        data.append( 'action',   'dsi_contact_submit' );
        data.append( 'nonce',    dsiContactForm.nonce );
        data.append( 'nome',     nome );
        data.append( 'email',    email );
        data.append( 'mensagem', mensagem );
        data.append( 'site',     form.querySelector( '#dsi-contact-site' ).value );

        fetch( dsiContactForm.ajaxUrl, { method: 'POST', body: data } )
            .then( function ( r ) { return r.json(); } )
            .then( function ( res ) {
                if ( res.success ) {
                    form.style.display     = 'none';
                    feedback.style.display = 'block';
                    feedback.style.color   = '#c2511d';
                    feedback.textContent   = res.data.message;
                } else {
                    feedback.style.display = 'block';
                    feedback.style.color   = '#b00020';
                    feedback.textContent   = res.data.message;
                    btn.disabled           = false;
                    btn.textContent        = originalLabel;
                }
            } )
            .catch( function () {
                feedback.style.display = 'block';
                feedback.style.color   = '#b00020';
                feedback.textContent   = 'Erro de conexão. Tente novamente.';
                btn.disabled           = false;
                btn.textContent        = originalLabel;
            } );
    } );
} )();
