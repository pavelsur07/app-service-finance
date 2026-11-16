import { useId, useRef, useState } from 'react';
import { IconSearch, IconChevronRight, IconCheck } from '@tabler/icons-react';
import { Modal, type ModalProps } from '../Modal/Modal';
import { Button } from '../Button/Button';
import { InputGroup } from '../InputGroup/InputGroup';
import { EmptyState } from '../EmptyState/EmptyState';
import { filterTree, treeRows, type TreePickerItem } from './tree';
export type { TreePickerItem } from './tree';
export interface TreePickerProps extends Omit<
  ModalProps,
  'onClose' | 'onChange' | 'children' | 'footer' | 'initialFocusRef'
> {
  items: readonly TreePickerItem[];
  value: string | null;
  onChange: (value: string | null) => void;
  onOpenChange: (open: boolean) => void;
  searchable?: boolean;
  allowClear?: boolean;
}
export function TreePicker(props: TreePickerProps) {
  return props.open ? <TreePickerContent {...props} /> : null;
}
function TreePickerContent({
  open,
  onOpenChange,
  items,
  value,
  onChange,
  searchable = true,
  allowClear = true,
  ...props
}: TreePickerProps) {
  const all = treeRows(items);
  const selectedPath = all.find((row) => row.item.id === value)?.path ?? [];
  const [draft, setDraft] = useState(value),
    [query, setQuery] = useState(''),
    [expanded, setExpanded] = useState<Set<string>>(
      () => new Set(selectedPath.slice(0, -1).map((item) => item.id)),
    ),
    [active, setActive] = useState<string | null>(value);
  const search = useRef<HTMLInputElement>(null),
    refs = useRef(new Map<string, HTMLLIElement>());
  const id = useId();
  const filtered = filterTree(items, query),
    rows = treeRows(filtered, query.trim() ? undefined : expanded),
    enabled = rows.filter(
      (row) => row.item.children !== undefined || !row.item.disabled,
    ),
    current = enabled.find((row) => row.item.id === active) ?? enabled[0];
  const draftRow = all.find((row) => row.item.id === draft);
  const valid =
    draft === null
      ? allowClear
      : !!draftRow &&
        draftRow.item.children === undefined &&
        !draftRow.item.disabled;
  const focus = (next: string | undefined) => {
    if (next) {
      setActive(next);
      refs.current.get(next)?.focus();
    }
  };
  const toggle = (node: string) =>
    setExpanded((previous) => {
      const next = new Set(previous);
      if (next.has(node)) next.delete(node);
      else next.add(node);
      return next;
    });
  const render = (nodes: readonly TreePickerItem[], level: number) =>
    nodes.map((item) => {
      const branch = item.children !== undefined,
        isOpen = branch && (!!query.trim() || expanded.has(item.id));
      const index = all.findIndex((row) => row.item.id === item.id);
      const term = query.trim().toLocaleLowerCase('ru-RU'),
        match = term ? item.label.toLocaleLowerCase('ru-RU').indexOf(term) : -1;
      return (
        <li
          ref={(node) => {
            if (node) refs.current.set(item.id, node);
            else refs.current.delete(item.id);
          }}
          key={item.id}
          id={`${id}-node-${index}`}
          role="treeitem"
          aria-labelledby={`${id}-name-${index}`}
          aria-level={level}
          aria-expanded={branch ? isOpen : undefined}
          aria-selected={branch ? undefined : draft === item.id}
          aria-disabled={!branch && item.disabled ? true : undefined}
          tabIndex={item.id === current?.item.id ? 0 : -1}
          onFocus={(event) => {
            if (event.target === event.currentTarget) setActive(item.id);
          }}
          className="rounded-md outline-offset-[-2px]"
          onKeyDown={(event) => {
            if (
              event.target !== event.currentTarget ||
              event.nativeEvent.isComposing
            )
              return;
            const pos = enabled.findIndex((row) => row.item.id === item.id);
            let next: string | undefined;
            if (event.key === 'ArrowDown') next = enabled[pos + 1]?.item.id;
            if (event.key === 'ArrowUp') next = enabled[pos - 1]?.item.id;
            if (event.key === 'Home') next = enabled[0]?.item.id;
            if (event.key === 'End') next = enabled.at(-1)?.item.id;
            if (event.key === 'ArrowRight' && branch) {
              if (!isOpen) toggle(item.id);
              else
                next = enabled.find((row) => row.parent === item.id)?.item.id;
            }
            if (event.key === 'ArrowLeft') {
              if (branch && isOpen && !query.trim()) toggle(item.id);
              else
                next =
                  rows.find((row) => row.item.id === item.id)?.parent ??
                  undefined;
            }
            if (event.key === 'Enter' || event.key === ' ') {
              if (branch) {
                if (!query.trim()) toggle(item.id);
              } else if (!item.disabled) setDraft(item.id);
            }
            if (
              [
                'ArrowDown',
                'ArrowUp',
                'ArrowRight',
                'ArrowLeft',
                'Home',
                'End',
                'Enter',
                ' ',
              ].includes(event.key)
            ) {
              event.preventDefault();
              event.stopPropagation();
              focus(next);
            }
          }}
        >
          <div
            className={`ds-picker-row ${draft === item.id ? 'bg-surface-row-selected font-bold text-primary-hover' : branch ? 'font-semibold text-fg-tertiary' : 'font-medium'} ${item.disabled && !branch ? 'opacity-50' : ''}`}
            onClick={(event) => {
              event.stopPropagation();
              focus(item.id);
              if (branch) {
                if (!query.trim()) toggle(item.id);
              } else if (!item.disabled) setDraft(item.id);
            }}
          >
            <span
              aria-hidden="true"
              className="inline-flex size-4 shrink-0 items-center"
            >
              {branch && (
                <IconChevronRight
                  size={16}
                  stroke={2}
                  className={isOpen ? 'rotate-90' : ''}
                />
              )}
            </span>
            <span
              id={`${id}-name-${index}`}
              className="min-w-0 flex-1 truncate"
            >
              {match < 0 ? (
                item.label
              ) : (
                <>
                  {item.label.slice(0, match)}
                  <mark className="rounded-xs bg-warning-bg px-0.5 font-bold text-warning-text">
                    {item.label.slice(match, match + term.length)}
                  </mark>
                  {item.label.slice(match + term.length)}
                </>
              )}
            </span>
            {item.meta && (
              <span className="text-sm font-bold text-fg-tertiary tabular-nums">
                {item.meta}
              </span>
            )}
            {draft === item.id && (
              <IconCheck size={18} stroke={2} aria-hidden="true" />
            )}
          </div>
          {branch && isOpen && (
            <ul role="group" className="ml-4">
              {render(item.children ?? [], level + 1)}
            </ul>
          )}
        </li>
      );
    });
  return (
    <Modal
      {...props}
      open={open}
      onClose={() => onOpenChange(false)}
      initialFocusRef={searchable ? search : undefined}
      className={`ds-picker ${props.className ?? ''}`}
      footer={
        <>
          {allowClear && (
            <Button
              variant="ghost"
              className="mr-auto"
              disabled={draft === null}
              onClick={() => setDraft(null)}
            >
              Сбросить
            </Button>
          )}
          <Button variant="secondary" onClick={() => onOpenChange(false)}>
            Отмена
          </Button>
          <Button
            disabled={!valid || draft === value}
            onClick={() => {
              if (valid) {
                onChange(draft);
                onOpenChange(false);
              }
            }}
          >
            Выбрать
          </Button>
        </>
      }
    >
      {searchable && (
        <div className="ds-picker-search">
          <InputGroup
            ref={search}
            aria-label="Поиск"
            icon={<IconSearch size={18} stroke={2} />}
            value={query}
            onChange={(event) => {
              setQuery(event.target.value);
              setActive(null);
            }}
            onKeyDown={(event) => {
              if (event.key === 'ArrowDown' && !event.nativeEvent.isComposing) {
                event.preventDefault();
                focus(current?.item.id);
              }
            }}
          />
        </div>
      )}
      {rows.length ? (
        <ul role="tree" aria-label="Варианты" className="ds-picker-list">
          {render(filtered, 1)}
        </ul>
      ) : (
        <EmptyState title="Ничего не найдено">
          Измените поисковый запрос.
        </EmptyState>
      )}
      {draftRow && (
        <div
          className="border-t border-line-soft bg-surface-subtle px-5 py-2 text-sm text-fg-tertiary"
          aria-label="Путь выбора"
        >
          {draftRow.path.map((item) => item.label).join(' / ')}
        </div>
      )}
    </Modal>
  );
}
