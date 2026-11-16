import { describe, expect, it } from 'vitest';
import { filterTree, treeRows, type TreePickerItem } from './tree';
const items: readonly TreePickerItem[] = [
  {
    id: 'group',
    label: 'Расходы',
    children: [
      { id: 'rent', label: 'Аренда' },
      {
        id: 'nested',
        label: 'Персонал',
        children: [{ id: 'payroll', label: 'Зарплата', searchText: 'salary' }],
      },
    ],
  },
  { id: 'empty', label: 'Пустой раздел', children: [] },
];
describe('tree visibility and context', () => {
  it('preserves all ancestors and exact leaf path on a deep search', () => {
    const result = filterTree(items, ' SALARY ');
    expect(treeRows(result).map((row) => row.item.id)).toEqual([
      'group',
      'nested',
      'payroll',
    ]);
    expect(
      treeRows(result)
        .at(-1)
        ?.path.map((item) => item.label),
    ).toEqual(['Расходы', 'Персонал', 'Зарплата']);
  });
  it('expands matching parent context without losing its descendants', () =>
    expect(
      treeRows(filterTree(items, 'Расходы')).map((row) => row.item.id),
    ).toEqual(['group', 'rent', 'nested', 'payroll']));
  it('does not mutate a tree or expansion set while filtering', () => {
    const expanded = new Set(['group']);
    const before = JSON.stringify(items);
    filterTree(items, 'зарплата');
    expect(treeRows(items, expanded).map((row) => row.item.id)).toEqual([
      'group',
      'rent',
      'nested',
      'empty',
    ]);
    expect(JSON.stringify(items)).toBe(before);
    expect([...expanded]).toEqual(['group']);
  });
  it('empty query retains original identity and unmatched query is empty', () => {
    expect(filterTree(items, ' ')).toBe(items);
    expect(filterTree(items, 'missing')).toEqual([]);
  });
  it('retains empty branches as non-leaf nodes', () =>
    expect(treeRows(items).at(-1)?.item.children).toEqual([]));
});
