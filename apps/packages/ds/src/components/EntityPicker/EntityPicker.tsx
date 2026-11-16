import { useEffect, useId, useRef, useState, type ReactNode } from 'react';
import { IconSearch, IconCheck } from '@tabler/icons-react';
import { Modal, type ModalProps } from '../Modal/Modal';
import { Button } from '../Button/Button';
import { InputGroup } from '../InputGroup/InputGroup';
import { EmptyState } from '../EmptyState/EmptyState';
export interface EntityPickerItem {
  id: string;
  label: string;
  meta?: ReactNode;
  searchText?: string;
  leading?: ReactNode;
  trailing?: ReactNode;
  disabled?: boolean;
}
export interface EntityPickerProps extends Omit<
  ModalProps,
  'onClose' | 'onChange' | 'children' | 'footer' | 'initialFocusRef'
> {
  items: readonly EntityPickerItem[];
  value: string | null;
  onChange: (value: string | null) => void;
  onOpenChange: (open: boolean) => void;
  searchable?: boolean;
  allowClear?: boolean;
}
export function EntityPicker(props: EntityPickerProps) {
  return props.open ? <EntityPickerContent {...props} /> : null;
}
function EntityPickerContent({
  open,
  onOpenChange,
  items,
  value,
  onChange,
  searchable = true,
  allowClear = true,
  ...props
}: EntityPickerProps) {
  const [draft, setDraft] = useState(value),
    [query, setQuery] = useState(''),
    [active, setActive] = useState<string | null>(value);
  const search = useRef<HTMLInputElement>(null),
    options = useRef(new Map<string, HTMLButtonElement>());
  const id = useId();
  const matches = items.filter((item) =>
    `${item.label} ${item.searchText ?? ''}`
      .toLocaleLowerCase('ru-RU')
      .includes(query.trim().toLocaleLowerCase('ru-RU')),
  );
  const enabled = matches.filter((item) => !item.disabled),
    current = enabled.find((item) => item.id === active) ?? enabled[0];
  useEffect(() => {
    if (open && current)
      options.current.get(current.id)?.scrollIntoView({ block: 'nearest' });
  }, [open, current]);
  const valid =
    draft === null
      ? allowClear
      : items.some((item) => item.id === draft && !item.disabled);
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
              onClick={() => setDraft(null)}
              disabled={draft === null}
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
            icon={<IconSearch size={18} stroke={2} />}
            aria-label="Поиск"
            role="combobox"
            aria-expanded={true}
            aria-controls={`${id}-list`}
            aria-activedescendant={
              current ? `${id}-option-${items.indexOf(current)}` : undefined
            }
            aria-autocomplete="list"
            value={query}
            onChange={(event) => {
              setQuery(event.target.value);
              setActive(null);
            }}
            onKeyDown={(event) => {
              const index = enabled.findIndex(
                (item) => item.id === current?.id,
              );
              let next: EntityPickerItem | undefined;
              if (event.key === 'ArrowDown')
                next = enabled[(index + 1) % enabled.length];
              if (event.key === 'ArrowUp')
                next = enabled[(index - 1 + enabled.length) % enabled.length];
              if (event.key === 'Home') next = enabled[0];
              if (event.key === 'End') next = enabled.at(-1);
              if (next) {
                event.preventDefault();
                setActive(next.id);
              }
              if (
                event.key === 'Enter' &&
                current &&
                !event.nativeEvent.isComposing
              ) {
                event.preventDefault();
                setDraft(current.id);
              }
            }}
          />
        </div>
      )}
      <div
        id={`${id}-list`}
        role="listbox"
        aria-label="Варианты"
        className="ds-picker-list"
      >
        {matches.map((item) => (
          <button
            ref={(node) => {
              if (node) options.current.set(item.id, node);
              else options.current.delete(item.id);
            }}
            type="button"
            id={`${id}-option-${items.indexOf(item)}`}
            key={item.id}
            role="option"
            aria-selected={draft === item.id}
            disabled={item.disabled}
            tabIndex={!searchable && current?.id === item.id ? 0 : -1}
            className={`ds-picker-row ${draft === item.id ? 'bg-surface-row-selected' : current?.id === item.id ? 'bg-surface-page' : ''}`}
            onMouseDown={(event) => {
              if (searchable) event.preventDefault();
            }}
            onClick={() => {
              setDraft(item.id);
              setActive(item.id);
            }}
            onKeyDown={(event) => {
              if (searchable) return;
              const index = enabled.findIndex(
                (option) => option.id === item.id,
              );
              const next =
                event.key === 'ArrowDown'
                  ? enabled[(index + 1) % enabled.length]
                  : event.key === 'ArrowUp'
                    ? enabled[(index - 1 + enabled.length) % enabled.length]
                    : event.key === 'Home'
                      ? enabled[0]
                      : event.key === 'End'
                        ? enabled.at(-1)
                        : undefined;
              if (next) {
                event.preventDefault();
                setActive(next.id);
                options.current.get(next.id)?.focus();
              }
            }}
          >
            {item.leading}
            <span className="min-w-0 flex-1">
              <span className="block truncate font-semibold">{item.label}</span>
              {item.meta && (
                <span className="mt-0.5 block text-xs text-fg-tertiary">
                  {item.meta}
                </span>
              )}
            </span>
            {item.trailing}
            {draft === item.id && (
              <IconCheck size={18} stroke={2} aria-hidden="true" />
            )}
          </button>
        ))}
      </div>
      {!matches.length && (
        <EmptyState title="Ничего не найдено">
          Измените поисковый запрос.
        </EmptyState>
      )}
    </Modal>
  );
}
