import './bootstrap.js';
import './styles/app.css';

/**
 * Simple dropdown handler for the vertical sidebar.
 *
 * Tabler's JavaScript is normally responsible for toggling dropdown
 * menus. In environments where the library isn't loaded (for example
 * when CDN assets are unavailable), the menu items would stop opening.
 *
 * The code below reproduces the required behaviour using vanilla
 * JavaScript so that the menu continues to work even without the
 * external dependency.
 */
document.addEventListener('DOMContentLoaded', () => {
  // If Tabler's JavaScript is available, it will handle dropdowns itself.
  // The fallback logic below runs only when Tabler isn't present.
  if (typeof window.Tabler !== 'undefined') {
    return;
  }

  document
    .querySelectorAll('.navbar-vertical .nav-item.dropdown > .nav-link')
    .forEach((link) => {
      link.addEventListener('click', (event) => {
        event.preventDefault();
        // Prevent the click from bubbling up, which would
        // immediately close the menu in some browsers.
        event.stopPropagation();
        const menu = link.nextElementSibling;
        if (menu) {
          const isOpen = menu.classList.toggle('show');
          link.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        }
      });
    });
});
