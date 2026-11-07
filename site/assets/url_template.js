/**
 * Адрес маршрута с id из data-атрибута: Twig отдаёт path() с плейсхолдером-UUID
 * (requirements маршрутов не примут '__ID__'), модуль подставляет настоящий id.
 */
export const ID_PLACEHOLDER = '00000000-0000-0000-0000-000000000000';
export const withId = (template, id) => template.replace(ID_PLACEHOLDER, encodeURIComponent(id));
