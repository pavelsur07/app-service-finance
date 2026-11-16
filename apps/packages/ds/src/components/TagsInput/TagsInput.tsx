import { useId, useRef, useState, type ComponentPropsWithRef } from 'react';
import { IconX } from '@tabler/icons-react';
export interface TagsInputProps extends Omit<
  ComponentPropsWithRef<'input'>,
  'value' | 'defaultValue' | 'onChange' | 'size'
> {
  label: string;
  value: readonly string[];
  onChange: (value: string[]) => void;
  suggestions?: readonly string[];
}
export function TagsInput({
  label,
  value,
  onChange,
  suggestions = [],
  disabled = false,
  readOnly = false,
  required = false,
  name,
  className = '',
  id: inputId,
  onKeyDown,
  ...props
}: TagsInputProps) {
  const generated = useId();
  const id = inputId ?? generated;
  const [text, setText] = useState('');
  const [focused, setFocused] = useState(false);
  const [active, setActive] = useState(0);
  const root = useRef<HTMLDivElement>(null);
  const input = useRef<HTMLInputElement>(null);
  const normalize = (s: string) => s.trim().toLocaleLowerCase('ru-RU');
  const matches = suggestions.filter(
    (s, i) =>
      suggestions.indexOf(s) === i &&
      normalize(s).includes(normalize(text)) &&
      !value.some((v) => normalize(v) === normalize(s)),
  );
  const canCreate =
    text.trim() &&
    ![...value, ...matches].some((v) => normalize(v) === normalize(text));
  const options = [...matches, ...(canCreate ? [text.trim()] : [])];
  const expanded = focused && !disabled && !readOnly && options.length > 0;
  const add = (tag: string) => {
    if (disabled || readOnly || !tag.trim()) return;
    if (!value.some((v) => normalize(v) === normalize(tag)))
      onChange([...value, tag.trim()]);
    setText('');
    setActive(0);
    input.current?.focus();
  };
  const remove = (index: number) => {
    if (!disabled && !readOnly) {
      onChange(value.filter((_, i) => i !== index));
      input.current?.focus();
    }
  };
  return (
    <div
      className={className}
      onFocus={() => setFocused(true)}
      onBlur={(event) => {
        if (!event.currentTarget.contains(event.relatedTarget))
          setFocused(false);
      }}
    >
      <label htmlFor={id} className="form-label mb-1 block">
        {label}
      </label>
      <div
        ref={root}
        className="ds-tags relative flex min-h-control flex-wrap items-center gap-1.5 rounded-md border border-(--ds-input-border) bg-surface-card px-1.5 py-1"
        data-disabled={disabled || readOnly}
      >
        {value.map((tag, index) => (
          <span
            key={`${tag}-${index}`}
            className="ds-tag inline-flex items-center gap-1 rounded-md border border-line bg-surface-page py-1 pr-1 pl-2 text-sm font-semibold text-fg-secondary"
          >
            {tag}
            <button
              type="button"
              data-tag-remove
              aria-label={`Удалить ${tag}`}
              disabled={disabled || readOnly}
              className="inline-flex size-4.5 items-center justify-center rounded-sm"
              onClick={() => remove(index)}
              onKeyDown={(event) => {
                if (event.key === 'Backspace' || event.key === 'Delete') {
                  event.preventDefault();
                  remove(index);
                }
              }}
            >
              <IconX size={16} stroke={2} aria-hidden="true" />
            </button>
          </span>
        ))}
        <input
          {...props}
          ref={(node) => {
            input.current = node;
            node?.setCustomValidity(
              required && value.length === 0
                ? 'Добавьте хотя бы одну метку.'
                : '',
            );
            if (typeof props.ref === 'function') return props.ref(node);
            else if (props.ref) props.ref.current = node;
          }}
          id={id}
          disabled={disabled}
          readOnly={readOnly}
          required={required && value.length === 0}
          aria-required={required || props['aria-required']}
          role="combobox"
          aria-autocomplete="list"
          aria-expanded={expanded}
          aria-controls={`${id}-options`}
          aria-activedescendant={
            expanded
              ? `${id}-option-${Math.min(active, options.length - 1)}`
              : undefined
          }
          value={text}
          onChange={(event) => {
            setFocused(true);
            setText(event.target.value);
            setActive(0);
          }}
          className="h-6.5 min-w-20 flex-1 bg-transparent px-1.5 text-md text-fg-strong placeholder:text-fg-tertiary"
          onKeyDown={(event) => {
            onKeyDown?.(event);
            if (
              event.defaultPrevented ||
              event.nativeEvent.isComposing ||
              disabled ||
              readOnly
            )
              return;
            if (event.key === 'Escape' && expanded) {
              event.preventDefault();
              event.stopPropagation();
              setFocused(false);
            } else if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
              event.preventDefault();
              setFocused(true);
              setActive((i) =>
                options.length
                  ? (i +
                      (event.key === 'ArrowDown' ? 1 : -1) +
                      options.length) %
                    options.length
                  : 0,
              );
            } else if (event.key === 'Enter') {
              event.preventDefault();
              const tag = expanded
                ? options[Math.min(active, options.length - 1)]
                : text;
              if (tag) add(tag);
            } else if (event.key === 'Backspace' && !text) {
              root.current
                ?.querySelectorAll<HTMLButtonElement>('[data-tag-remove]')
                .item(value.length - 1)
                ?.focus();
            }
          }}
        />
        {name &&
          value.map((tag, index) => (
            <input
              key={`${tag}-${index}`}
              type="hidden"
              name={name}
              value={tag}
              disabled={disabled}
              form={props.form}
            />
          ))}
        {expanded && (
          <div
            id={`${id}-options`}
            role="listbox"
            aria-label={`${label}: варианты`}
            className="absolute top-[calc(100%+4px)] right-0 left-0 z-dropdown max-h-50 overflow-y-auto rounded-md border border-line bg-surface-card p-1 shadow-menu"
          >
            {options.map((tag, index) => (
              <button
                type="button"
                role="option"
                aria-selected={index === active}
                tabIndex={-1}
                key={tag}
                id={`${id}-option-${index}`}
                className={`block w-full rounded-md p-2 text-left text-md ${index === active ? 'bg-surface-page' : ''}`}
                onMouseDown={(event) => event.preventDefault()}
                onClick={() => add(tag)}
              >
                {canCreate && index === options.length - 1
                  ? `Создать «${tag}»`
                  : tag}
              </button>
            ))}
          </div>
        )}
      </div>
    </div>
  );
}
