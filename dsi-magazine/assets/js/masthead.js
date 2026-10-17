/**
 * masthead.js — Mobile nav toggle
 */
(function () {
  'use strict';

  document.addEventListener('DOMContentLoaded', function () {
    const btns = document.querySelectorAll('.dsi-masthead__burger');

    btns.forEach(btn => {
      const targetId = btn.getAttribute('aria-controls');
      const drawer   = document.getElementById(targetId);
      if (!drawer) return;

      btn.addEventListener('click', function () {
        const isOpen = this.getAttribute('aria-expanded') === 'true';
        this.setAttribute('aria-expanded', String(!isOpen));
        if (isOpen) {
          drawer.hidden = true;
        } else {
          drawer.hidden = false;
        }
      });
    });
  });
  // Submenu do celular: fechado por padrao, abre no botao ao lado do item pai.
  // Os links continuam no HTML (so o CSS esconde), entao nada muda para indexacao.
  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.dsi-mobile-nav__list').forEach(function (lista) {
      const pais = lista.querySelectorAll(':scope > .menu-item-has-children');
      if (!pais.length) return;
      pais.forEach(function (pai) {
        const sub = pai.querySelector(':scope > .sub-menu');
        const link = pai.querySelector(':scope > a');
        if (!sub || !link) return;
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'dsi-mobile-nav__toggle';
        btn.setAttribute('aria-expanded', 'false');
        btn.setAttribute('aria-label', 'Abrir ' + link.textContent.trim());
        btn.addEventListener('click', function () {
          const aberto = pai.classList.toggle('is-open');
          btn.setAttribute('aria-expanded', String(aberto));
        });
        link.after(btn);
      });
      lista.classList.add('has-toggle');
    });
  });
})();
