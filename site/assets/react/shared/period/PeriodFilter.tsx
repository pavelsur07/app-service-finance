import { useEffect, useId, useMemo, useRef, useState } from 'react';
import type { FormEvent, KeyboardEvent } from 'react';
import { buildPeriodPresets, formatDateRu } from './periodPresets';

export interface PeriodFilterProps {
    from: string;
    to: string;
    onChange: (from: string, to: string) => void;
}

/**
 * Период по DS, как фильтр «Транзакции ДДС» (`transaction/_filter_bar.html.twig`):
 * сегменты пресетов + кнопка с диапазоном, окно «Дата с / Дата по» → «Применить».
 * Одно место ввода дат: пресет подсвечен, когда диапазон совпал с ним, иначе подсвечена
 * кнопка диапазона. Окно — под состоянием React (не Bootstrap JS): Esc и клик мимо закрывают.
 */
export function PeriodFilter({ from, to, onChange }: PeriodFilterProps): JSX.Element {
    const presets = useMemo(() => buildPeriodPresets(), []);
    const activePreset = presets.find((p) => p.from === from && p.to === to) ?? null;

    const [open, setOpen] = useState(false);
    const [draftFrom, setDraftFrom] = useState(from);
    const [draftTo, setDraftTo] = useState(to);
    const rootRef = useRef<HTMLDivElement>(null);
    const buttonRef = useRef<HTMLButtonElement>(null);
    const firstInputRef = useRef<HTMLInputElement>(null);
    const popId = useId();

    useEffect(() => {
        if (!open) {
            return undefined;
        }
        const onPointer = (event: PointerEvent): void => {
            if (rootRef.current !== null && !rootRef.current.contains(event.target as Node)) {
                setOpen(false);
            }
        };
        document.addEventListener('pointerdown', onPointer);
        firstInputRef.current?.focus();

        return () => document.removeEventListener('pointerdown', onPointer);
    }, [open]);

    const toggle = (): void => {
        if (!open) {
            setDraftFrom(from);
            setDraftTo(to);
        }
        setOpen(!open);
    };

    const close = (): void => {
        setOpen(false);
        buttonRef.current?.focus({ preventScroll: true });
    };

    const onKeyDown = (event: KeyboardEvent<HTMLDivElement>): void => {
        if (open && event.key === 'Escape') {
            event.preventDefault();
            close();
        }
    };

    const invalid = draftFrom === '' || draftTo === '' || draftFrom > draftTo;

    const apply = (event: FormEvent<HTMLFormElement>): void => {
        event.preventDefault();
        if (invalid) {
            return;
        }
        onChange(draftFrom, draftTo);
        close();
    };

    const rangeLabel = `${formatDateRu(from) || '…'} — ${formatDateRu(to) || '…'}`;

    return (
        <>
            <div className="vf-seg" role="group" aria-label="Период">
                {presets.map((preset) => {
                    const isActive = preset === activePreset;

                    return (
                        <button
                            key={preset.label}
                            type="button"
                            className={isActive ? 'active' : undefined}
                            aria-pressed={isActive}
                            title={`${formatDateRu(preset.from)} — ${formatDateRu(preset.to)}`}
                            onClick={() => onChange(preset.from, preset.to)}
                        >
                            {preset.label}
                        </button>
                    );
                })}
            </div>

            <div className="dropdown" ref={rootRef} onKeyDown={onKeyDown}>
                <button
                    ref={buttonRef}
                    type="button"
                    className={`btn vf-ops-range${activePreset === null ? ' active' : ''}`}
                    aria-haspopup="dialog"
                    aria-expanded={open}
                    aria-controls={open ? popId : undefined}
                    aria-label={`Выбрать период: ${rangeLabel}`}
                    onClick={toggle}
                >
                    <i className="ti ti-calendar" aria-hidden="true" />
                    {rangeLabel}
                </button>
                {open && (
                    <div
                        id={popId}
                        className="dropdown-menu show vf-ops-pop vf-period-pop"
                        role="dialog"
                        aria-label="Выбрать период"
                    >
                        <form onSubmit={apply} noValidate>
                            <div className="vf-ops-pop__body vf-ops-pop__pair">
                                <div>
                                    <label className="form-label" htmlFor={`${popId}-from`}>Дата с</label>
                                    <input
                                        ref={firstInputRef}
                                        type="date"
                                        id={`${popId}-from`}
                                        className="form-control"
                                        value={draftFrom}
                                        max={draftTo || undefined}
                                        onChange={(e) => setDraftFrom(e.target.value)}
                                    />
                                </div>
                                <div>
                                    <label className="form-label" htmlFor={`${popId}-to`}>Дата по</label>
                                    <input
                                        type="date"
                                        id={`${popId}-to`}
                                        className="form-control"
                                        value={draftTo}
                                        min={draftFrom || undefined}
                                        onChange={(e) => setDraftTo(e.target.value)}
                                    />
                                </div>
                            </div>
                            <div className="vf-ops-pop__footer">
                                <button type="submit" className="btn btn-primary btn-sm" disabled={invalid}>
                                    Применить
                                </button>
                            </div>
                        </form>
                    </div>
                )}
            </div>
        </>
    );
}
