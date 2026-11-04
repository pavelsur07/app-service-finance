import { useMemo, useState } from 'react';
import { ApiError, apiFetch } from '@/api/client';
import { formatValue, parseAmountInput } from './amount';
import type { BudgetFillHelper, BudgetFillResult, BudgetGridRow, SelectedPair } from './budget-grid.types';
import styles from './BudgetGrid.module.css';

const MONTH_LABELS = ['янв', 'фев', 'мар', 'апр', 'май', 'июн', 'июл', 'авг', 'сен', 'окт', 'ноя', 'дек'];

const HELPERS: { value: BudgetFillHelper; label: string; hint: string }[] = [
    { value: 'last_year_fact', label: 'Из факта прошлого года', hint: 'Факт того же месяца прошлого года в этой паре.' },
    { value: 'average', label: 'Среднее за N месяцев', hint: 'Среднее факта за последние закрытые месяцы; месяц без факта считается нулём.' },
    { value: 'distribute', label: 'Распределить годовую сумму', hint: 'Поровну на 12 месяцев, остаток копеек — в декабрь. Расход вводите положительным.' },
    { value: 'copy_right', label: 'Копировать месяц вправо', hint: 'Сумма месяца — во все месяцы правее.' },
    { value: 'percent_of', label: 'X% от другой статьи', hint: 'Процент от плана статьи-базы в том же месяце; знак — по направлению статьи.' },
];

export interface BudgetFillPanelProps {
    fillUrl: string;
    csrfToken: string;
    pair: SelectedPair;
    months: string[];
    rows: BudgetGridRow[];
    /** Есть неотправленные правки или идёт сохранение — помощник ждёт. */
    busy: boolean;
    onApplied: (changed: number) => void;
    onClose: () => void;
}

const monthLabel = (month: string): string => MONTH_LABELS[Number(month.slice(5, 7)) - 1] ?? month;

const money = (value: string): string => {
    const formatted = formatValue(value, 'MONEY');

    return formatted.whole;
};

/**
 * Помощники заполнения плана (ui-ux.md, экран 4): выбор помощника, статей и месяцев →
 * «Показать изменения» (предпросмотр сервера) → «Применить»; перезапись заполненных ячеек —
 * отдельной кнопкой подтверждения. Расчёт и запись — на сервере (`POST …/fill`).
 */
export function BudgetFillPanel(props: BudgetFillPanelProps): JSX.Element {
    const leaves = useMemo(() => props.rows.filter((row) => row.editable), [props.rows]);
    const bases = useMemo(() => props.rows.filter((row) => row.format === 'MONEY'), [props.rows]);
    const [helper, setHelper] = useState<BudgetFillHelper>('last_year_fact');
    const [categoryIds, setCategoryIds] = useState<Set<string>>(() => new Set(leaves.map((row) => row.id)));
    const [months, setMonths] = useState<Set<string>>(() => new Set(props.months));
    const [average, setAverage] = useState(3);
    const [amount, setAmount] = useState('');
    const [sourceMonth, setSourceMonth] = useState(props.months[0] ?? '');
    const [percent, setPercent] = useState('');
    const [baseId, setBaseId] = useState(() => (bases.find((row) => /выручк/i.test(row.name)) ?? bases[0])?.id ?? '');
    const [preview, setPreview] = useState<BudgetFillResult | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [sending, setSending] = useState(false);

    const usesMonths = helper !== 'distribute' && helper !== 'copy_right';
    const names = useMemo(() => new Map(props.rows.map((row) => [row.id, row.name])), [props.rows]);
    const reset = (): void => {
        setPreview(null);
        setError(null);
    };

    const params = (): Record<string, unknown> | null => {
        switch (helper) {
            case 'average':
                return { months: average };
            case 'distribute': {
                const parsed = parseAmountInput(amount);
                if (parsed === null || parsed === '0.00') {
                    setError('Введите годовую сумму, например 1 200 000.');

                    return null;
                }

                return { amount: parsed };
            }
            case 'copy_right':
                return { sourceMonth };
            case 'percent_of': {
                const parsed = parseAmountInput(percent);
                if (parsed === null || parsed.startsWith('-')) {
                    setError('Введите процент, например 12,5.');

                    return null;
                }

                return { percent: parsed, baseCategoryId: baseId };
            }
            default:
                return {};
        }
    };

    const send = async (apply: boolean, confirmOverwrite: boolean): Promise<void> => {
        setError(null);
        if (categoryIds.size === 0) {
            setError('Выберите хотя бы одну статью.');

            return;
        }
        if (usesMonths && months.size === 0) {
            setError('Выберите хотя бы один месяц.');

            return;
        }
        const helperParams = params();
        if (helperParams === null) {
            return;
        }
        setSending(true);
        try {
            const result = await apiFetch<BudgetFillResult>(props.fillUrl, {
                method: 'POST',
                csrfToken: props.csrfToken,
                body: {
                    helper,
                    project: props.pair.projectDirectionId,
                    center: props.pair.responsibilityCenterId,
                    categoryIds: leaves.filter((row) => categoryIds.has(row.id)).map((row) => row.id),
                    months: usesMonths ? props.months.filter((month) => months.has(month)) : [],
                    params: helperParams,
                    apply,
                    confirmOverwrite,
                },
            });
            if (apply) {
                props.onApplied(result.changed);
            } else {
                setPreview(result);
            }
        } catch (caught) {
            setError(caught instanceof ApiError ? caught.message : 'Не удалось выполнить помощник.');
        } finally {
            setSending(false);
        }
    };

    const toggle = (set: Set<string>, value: string): Set<string> => {
        const next = new Set(set);
        if (next.has(value)) {
            next.delete(value);
        } else {
            next.add(value);
        }

        return next;
    };

    return (
        <section className={styles.fillPanel} aria-labelledby="budget-fill-title" data-budget-fill>
            <div className="d-flex align-items-center gap-2 mb-2">
                <h2 id="budget-fill-title" className="h4 mb-0">Помощник заполнения</h2>
                <button type="button" className="btn btn-sm ms-auto" onClick={props.onClose}>Закрыть</button>
            </div>

            <div className="row g-3">
                <div className="col-md-4">
                    <label className="form-label" htmlFor="budget-fill-helper">Помощник</label>
                    <select
                        id="budget-fill-helper"
                        className="form-select form-select-sm"
                        value={helper}
                        onChange={(event) => {
                            setHelper(event.target.value as BudgetFillHelper);
                            reset();
                        }}
                    >
                        {HELPERS.map((item) => <option key={item.value} value={item.value}>{item.label}</option>)}
                    </select>
                    <div className="form-hint small text-secondary mt-1">{HELPERS.find((item) => item.value === helper)?.hint}</div>

                    {helper === 'average' && (
                        <div className="mt-2">
                            <label className="form-label" htmlFor="budget-fill-n">Месяцев</label>
                            <select id="budget-fill-n" className="form-select form-select-sm w-auto" value={average} onChange={(event) => { setAverage(Number(event.target.value)); reset(); }}>
                                {[3, 6, 12].map((n) => <option key={n} value={n}>{n}</option>)}
                            </select>
                        </div>
                    )}
                    {helper === 'distribute' && (
                        <div className="mt-2">
                            <label className="form-label" htmlFor="budget-fill-amount">Сумма за год, ₽</label>
                            <input id="budget-fill-amount" className="form-control form-control-sm" inputMode="decimal" value={amount} onChange={(event) => { setAmount(event.target.value); reset(); }} />
                        </div>
                    )}
                    {helper === 'copy_right' && (
                        <div className="mt-2">
                            <label className="form-label" htmlFor="budget-fill-source">Исходный месяц</label>
                            <select id="budget-fill-source" className="form-select form-select-sm w-auto" value={sourceMonth} onChange={(event) => { setSourceMonth(event.target.value); reset(); }}>
                                {props.months.slice(0, -1).map((month) => <option key={month} value={month}>{monthLabel(month)}</option>)}
                            </select>
                        </div>
                    )}
                    {helper === 'percent_of' && (
                        <div className="mt-2 d-flex flex-column gap-2">
                            <div>
                                <label className="form-label" htmlFor="budget-fill-percent">Процент, %</label>
                                <input id="budget-fill-percent" className="form-control form-control-sm" inputMode="decimal" value={percent} onChange={(event) => { setPercent(event.target.value); reset(); }} />
                            </div>
                            <div>
                                <label className="form-label" htmlFor="budget-fill-base">От статьи</label>
                                <select id="budget-fill-base" className="form-select form-select-sm" value={baseId} onChange={(event) => { setBaseId(event.target.value); reset(); }}>
                                    {bases.map((row) => <option key={row.id} value={row.id}>{' '.repeat((row.level - 1) * 2)}{row.name}</option>)}
                                </select>
                            </div>
                        </div>
                    )}
                </div>

                <fieldset className="col-md-5">
                    <legend className="form-label">
                        Статьи ({categoryIds.size} из {leaves.length}){' '}
                        <button type="button" className="btn btn-link btn-sm p-0 ms-2" onClick={() => { setCategoryIds(new Set(leaves.map((row) => row.id))); reset(); }}>все</button>
                        <button type="button" className="btn btn-link btn-sm p-0 ms-2" onClick={() => { setCategoryIds(new Set()); reset(); }}>ни одной</button>
                    </legend>
                    <div className={styles.fillList}>
                        {leaves.map((row) => (
                            <label key={row.id} className="form-check mb-1">
                                <input type="checkbox" className="form-check-input" checked={categoryIds.has(row.id)} onChange={() => { setCategoryIds((current) => toggle(current, row.id)); reset(); }} />
                                <span className="form-check-label">{row.name}</span>
                            </label>
                        ))}
                    </div>
                </fieldset>

                {usesMonths && (
                    <fieldset className="col-md-3">
                        <legend className="form-label">
                            Месяцы{' '}
                            <button type="button" className="btn btn-link btn-sm p-0 ms-2" onClick={() => { setMonths(new Set(props.months)); reset(); }}>все</button>
                        </legend>
                        <div className={styles.fillMonths}>
                            {props.months.map((month) => (
                                <label key={month} className="form-check mb-1">
                                    <input type="checkbox" className="form-check-input" checked={months.has(month)} onChange={() => { setMonths((current) => toggle(current, month)); reset(); }} />
                                    <span className="form-check-label">{monthLabel(month)}</span>
                                </label>
                            ))}
                        </div>
                    </fieldset>
                )}
            </div>

            {error !== null && <div className="text-danger mt-2" role="alert">{error}</div>}
            {props.busy && <div className="text-secondary mt-2" role="status">Дождитесь сохранения правок в сетке.</div>}

            {preview !== null && (
                <div className="mt-3" role="status" data-budget-fill-preview>
                    {preview.changed === 0 ? (
                        <div>Менять нечего: суммы уже такие.{preview.skipped > 0 ? ` Пропущено ячеек плана продаж: ${preview.skipped}.` : ''}</div>
                    ) : (
                        <>
                            <div className="fw-semibold">
                                Будет изменено ячеек: {preview.changed}
                                {preview.overwrite > 0 ? `, из них заполненных: ${preview.overwrite}` : ''}
                                {preview.skipped > 0 ? `. Пропущено ячеек плана продаж: ${preview.skipped}` : ''}.
                            </div>
                            <ul className="small text-secondary mb-0 mt-1">
                                {preview.cells.slice(0, 8).map((cell) => (
                                    <li key={`${cell.plCategoryId}|${cell.month}`}>
                                        {names.get(cell.plCategoryId)} · {monthLabel(cell.month)}: {money(cell.previous)} → {money(cell.amount)}
                                    </li>
                                ))}
                                {preview.cells.length > 8 && <li>и ещё {preview.cells.length - 8}</li>}
                            </ul>
                        </>
                    )}
                </div>
            )}

            <div className="d-flex flex-wrap gap-2 mt-3">
                <button type="button" className="btn btn-sm" disabled={sending} onClick={() => void send(false, false)}>Показать изменения</button>
                {preview !== null && preview.changed > 0 && (
                    preview.overwrite > 0 ? (
                        <button type="button" className="btn btn-sm btn-danger" disabled={sending || props.busy} onClick={() => void send(true, true)}>
                            Перезаписать заполненные ({preview.overwrite}) и применить
                        </button>
                    ) : (
                        <button type="button" className="btn btn-sm btn-primary" disabled={sending || props.busy} onClick={() => void send(true, false)}>
                            Применить
                        </button>
                    )
                )}
            </div>
        </section>
    );
}
