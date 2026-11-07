/**
 * Bootstrap Modal легаси-shell'а: Tabler 1.2 кладёт Bootstrap в window.tabler.bootstrap,
 * base.html.twig дублирует его в window.bootstrap. null — если скрипт Tabler не загрузился.
 */
export const bootstrapModal = () => (window.bootstrap || window.tabler?.bootstrap)?.Modal ?? null;
