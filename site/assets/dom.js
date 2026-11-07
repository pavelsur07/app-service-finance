/**
 * createElement с классом и детьми (строка → текстовый узел): разметка из данных API
 * без innerHTML и ручного экранирования.
 */
export const el = (tag, className = '', children = []) => {
  const node = document.createElement(tag);
  if (className) node.className = className;
  for (const child of children) node.append(child);
  return node;
};
