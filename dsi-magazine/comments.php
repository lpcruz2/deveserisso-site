<?php
/**
 * comments.php — Seção de comentários DSI Magazine
 */
defined( 'ABSPATH' ) || exit;

if ( post_password_required() ) {
    return;
}

$comment_count = (int) get_comments_number();
?>

<section class="dsi-comments" id="comments" aria-label="Comentários">

    <!-- Cabeçalho -->
    <div class="dsi-comments__head">
        <div class="dsi-comments__head-text">
            <p class="dsi-comments__eyebrow">※ Debate dos leitores</p>
            <h2 class="dsi-comments__title">
                <?php if ( $comment_count > 0 ) :
                    echo number_format_i18n( $comment_count );
                    echo $comment_count === 1 ? ' <em>comentário</em>' : ' <em>comentários</em>';
                else : ?>
                    Seja o <em>primeiro</em> a comentar
                <?php endif; ?>
            </h2>
        </div>
        <span class="dsi-rule"></span>
    </div>

    <!-- Lista de comentários existentes -->
    <?php if ( have_comments() ) : ?>
        <ol class="dsi-comments__list">
            <?php wp_list_comments( [
                'style'      => 'ol',
                'short_ping' => true,
                'avatar_size'=> 56,
                'callback'   => 'dsi_render_comment',
                'end-callback' => '__return_false',
            ] ); ?>
        </ol>

        <?php if ( get_comment_pages_count() > 1 && get_option( 'page_comments' ) ) : ?>
            <nav class="dsi-comments__pagination" aria-label="Páginas de comentários">
                <?php previous_comments_link( '← Comentários anteriores' ); ?>
                <?php next_comments_link( 'Próximos comentários →' ); ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>

    <!-- Formulário -->
    <?php if ( comments_open() ) : ?>
    <div class="dsi-comments__form-wrap">
        <div class="dsi-comments__form-header">
            <p class="dsi-comments__form-eyebrow">※ Sua opinião importa</p>
            <h3 class="dsi-comments__form-title">Deixe um <em>comentário</em></h3>
        </div>

        <?php
        $commenter = wp_get_current_commenter();

        $fields = [
            'author' => '<div class="dsi-comments__field-row">'
                . '<div class="dsi-comments__field">'
                . '<label for="author" class="dsi-comments__label">Nome <span>*</span></label>'
                . '<input id="author" name="author" type="text" class="dsi-comments__input" value="' . esc_attr( $commenter['comment_author'] ) . '" required placeholder="Seu nome">'
                . '</div>',
            'email'  => '<div class="dsi-comments__field">'
                . '<label for="email" class="dsi-comments__label">E-mail <span>*</span></label>'
                . '<input id="email" name="email" type="email" class="dsi-comments__input" value="' . esc_attr( $commenter['comment_author_email'] ) . '" required placeholder="seu@email.com">'
                . '</div>'
                . '</div>',
            'url'    => '',
        ];

        comment_form( [
            'fields'               => $fields,
            'comment_field'        => '<div class="dsi-comments__field">'
                . '<label for="comment" class="dsi-comments__label">Comentário <span>*</span></label>'
                . '<textarea id="comment" name="comment" class="dsi-comments__textarea" rows="7" required placeholder="Escreva seu comentário…"></textarea>'
                . '</div>',
            'title_reply'          => '',
            'title_reply_before'   => '',
            'title_reply_after'    => '',
            'cancel_reply_before'  => '<p class="dsi-comments__cancel">',
            'cancel_reply_after'   => '</p>',
            'cancel_reply_link'    => 'Cancelar resposta',
            'comment_notes_before' => '',
            'comment_notes_after'  => '',
            'label_submit'         => 'Publicar comentário →',
            'class_submit'         => 'dsi-comments__submit',
            'class_form'           => 'dsi-comments__form',
            'id_form'              => 'dsi-commentform',
            'submit_field'         => '<div class="dsi-comments__submit-wrap">%1$s %2$s</div>',
        ] );
        ?>
    </div>
    <?php else : ?>
        <p class="dsi-comments__closed">Os comentários estão fechados para este artigo.</p>
    <?php endif; ?>

</section>
