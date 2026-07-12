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
})();
