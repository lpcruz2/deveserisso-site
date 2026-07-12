<?php
/**
 * newsletter.php — Seção de newsletter reutilizável
 * Incluir em qualquer template: get_template_part('template-parts/newsletter')
 *
 * @param string $eyebrow  Label acima do título (default: "A carta da semana")
 * @param string $heading  Título principal (default: padrão editorial)
 * @param string $btn_text Texto do botão (default: "Assinar")
 */
$eyebrow  = $args['eyebrow']  ?? '';
$heading  = $args['heading']  ?? null;
$btn_text = $args['btn_text'] ?? 'Assinar';

// URL de cadastro da newsletter (Mailchimp / ConvertKit / etc.)
$form_action = apply_filters( 'dsi_newsletter_action', home_url( '/newsletter-signup/' ) );
?>
<section class="dsi-newsletter" aria-labelledby="dsi-nl-heading">
    <div class="dsi-newsletter__inner">

        <p class="dsi-newsletter__eyebrow"><?php echo esc_html( $eyebrow ); ?></p>

        <h2 class="dsi-newsletter__heading" id="dsi-nl-heading">
            <?php if ( $heading ) : ?>
                <?php echo wp_kses( $heading, [ 'em' => [], 'strong' => [], 'span' => [ 'class' => [] ] ] ); ?>
            <?php else : ?>
                Nunca mais fique <em>sem saber o que assistir</em>
            <?php endif; ?>
        </h2>

        <p class="dsi-newsletter__desc">
            Uma carta editorial com as melhores recomendações da semana,
            escrita à mão pela redação. Sem clickbait, sem spam.
        </p>

        <form class="dsi-newsletter__form"
              id="dsi-nl-form"
              aria-label="Formulário de newsletter"
              toolname="assinar_newsletter"
              tooldescription="Assina a newsletter semanal do Deveserisso com recomendações de filmes, séries e programação de TV"
              toolautosubmit
              novalidate>
            <label for="dsi-nl-email" class="screen-reader-text">Seu endereço de email</label>
            <input type="email"
                   id="dsi-nl-email"
                   name="email"
                   class="dsi-newsletter__input"
                   placeholder="seu@email.com"
                   required
                   autocomplete="email"
                   toolparamdescription="Endereço de email para receber a newsletter semanal com recomendações editoriais">
            <button type="submit" class="dsi-newsletter__btn">
                <?php echo esc_html( $btn_text ); ?>
            </button>
        </form>
        <p class="dsi-newsletter__feedback" id="dsi-nl-feedback" aria-live="polite" style="display:none"></p>

    </div>
</section>
