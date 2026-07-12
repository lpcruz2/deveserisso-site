/**
 * post-toc.js — Índice do post com IntersectionObserver
 * Extrai todos os h2/h3 do conteúdo, gera o TOC e destaca o título visível.
 */
(function () {
  'use strict';

  const tocNav   = document.getElementById('dsi-toc-nav');
  const tocEl    = document.getElementById('dsi-toc');
  const content  = document.getElementById('dsi-post-content');

  if (!tocNav || !content) return;

  // ---- 1. Coleta headings (apenas H2 no índice) ----
  const headings = Array.from(content.querySelectorAll('h2'));

  if (headings.length < 2) {
    // Esconde o TOC se não há headings suficientes
    if (tocEl) tocEl.setAttribute('data-empty', '');
    return;
  }

  // ---- 2. Garante IDs nos headings ----
  headings.forEach((el, i) => {
    if (!el.id) {
      el.id = 'toc-' + i + '-' + el.textContent
        .toLowerCase()
        .replace(/[^\w\s-]/g, '')
        .replace(/\s+/g, '-')
        .slice(0, 40);
    }
  });

  // ---- 3. Constrói o TOC ----
  const fragment = document.createDocumentFragment();

  headings.forEach(el => {
    const a = document.createElement('a');
    a.href  = '#' + el.id;
    a.className = 'dsi-toc__link' + (el.tagName === 'H3' ? ' dsi-toc__link--h3' : '');
    a.textContent = el.textContent;
    a.dataset.target = el.id;
    fragment.appendChild(a);
  });

  tocNav.appendChild(fragment);

  // ---- 4. IntersectionObserver: destaca link do heading visível ----
  const links = tocNav.querySelectorAll('.dsi-toc__link');
  const linkMap = {};
  links.forEach(l => { linkMap[l.dataset.target] = l; });

  let activeId = null;

  const observer = new IntersectionObserver(
    (entries) => {
      entries.forEach(entry => {
        const id = entry.target.id;
        if (entry.isIntersecting) {
          // Remove ativo anterior
          if (activeId && linkMap[activeId]) {
            linkMap[activeId].classList.remove('dsi-toc__link--active');
          }
          activeId = id;
          if (linkMap[id]) {
            linkMap[id].classList.add('dsi-toc__link--active');
          }
        }
      });
    },
    {
      rootMargin: '-10% 0px -80% 0px', // ativa quando o heading entra no topo da viewport
      threshold: 0,
    }
  );

  headings.forEach(el => observer.observe(el));

  // ---- 5. Clique suave com offset ----
  links.forEach(link => {
    link.addEventListener('click', function (e) {
      e.preventDefault();
      const target = document.getElementById(this.dataset.target);
      if (!target) return;
      const top = target.getBoundingClientRect().top + window.scrollY - 80; // 80px de offset p/ masthead sticky
      window.scrollTo({ top, behavior: 'smooth' });
    });
  });
})();
