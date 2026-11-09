/**
 * Деревья справочников (templates/pl_category/index.html.twig, templates/cashflow_category/index.html.twig):
 * кнопка узла показывает или прячет его прямой список потомков. Корень — [data-category-tree="<префикс>"],
 * префикс задаёт классы: <префикс>-toggle, <префикс>-node, <префикс>-children (ищутся по всей странице).
 */
export function initCategoryTree() {
  document.querySelectorAll('[data-category-tree]').forEach((root) => {
    const prefix = root.dataset.categoryTree;
    document.querySelectorAll(`.${prefix}-toggle`).forEach((button) => {
      button.addEventListener('click', () => {
        const node = button.closest(`.${prefix}-node`);
        const children = node && Array.from(node.children).find((child) => child.classList.contains(`${prefix}-children`));
        if (!children) return;
        const isHidden = children.hasAttribute('hidden');
        children.toggleAttribute('hidden', !isHidden);
        button.classList.toggle('collapsed', !isHidden);
        button.setAttribute('aria-expanded', String(isHidden));
      });
    });
  });
}
