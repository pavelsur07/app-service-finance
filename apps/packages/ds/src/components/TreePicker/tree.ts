import type { ReactNode } from 'react';
export interface TreePickerItem {
  id: string;
  label: string;
  meta?: ReactNode;
  searchText?: string;
  disabled?: boolean;
  children?: readonly TreePickerItem[];
}
export interface TreeRow {
  item: TreePickerItem;
  parent: string | null;
  level: number;
  path: readonly TreePickerItem[];
}
export function treeRows(
  items: readonly TreePickerItem[],
  expanded?: ReadonlySet<string>,
): TreeRow[] {
  const rows: TreeRow[] = [];
  const visit = (
    nodes: readonly TreePickerItem[],
    parent: string | null,
    level: number,
    path: readonly TreePickerItem[],
  ) => {
    for (const item of nodes) {
      const next = [...path, item];
      rows.push({ item, parent, level, path: next });
      if (item.children && (!expanded || expanded.has(item.id)))
        visit(item.children, item.id, level + 1, next);
    }
  };
  visit(items, null, 1, []);
  return rows;
}
export function filterTree(
  items: readonly TreePickerItem[],
  query: string,
): readonly TreePickerItem[] {
  const term = query.trim().toLocaleLowerCase('ru-RU');
  if (!term) return items;
  return items.flatMap((item) => {
    if (
      `${item.label} ${item.searchText ?? ''}`
        .toLocaleLowerCase('ru-RU')
        .includes(term)
    )
      return [item];
    const children = item.children ? filterTree(item.children, term) : [];
    return children.length ? [{ ...item, children }] : [];
  });
}
