import { useEffect, useMemo, useRef, useState } from 'react';
import type { KeyboardEvent as ReactKeyboardEvent } from 'react';
import { createPortal } from 'react-dom';
import { ApiError, apiFetch } from '@/api/client';
import { formatValue, isZero } from './amount';
import type { BudgetFillHelper, BudgetFillResult, BudgetGridRow, SelectedPair } from './budget-grid.types';
import {
    MONTH_FULL,
    MONTH_SHORT,
    fillGroups,
    fillTargets,
    filterGroups,
    freeLeafIds,
    groupState,
    helperParams,
    isLocked,
    monthIndex,
    plural,
    previewLine,
    previewTable,
    quarterChips,
    setSelected,
    targetsLine,
    usesMonthPicker,
} from './fill-logic';
import styles from './BudgetGrid.module.css';

/** Карточки помощников (ref 13): название и короткая подпись; описание — справа над параметрами. */
const HELPERS: { value: BudgetFillHelper; label: string; hint: string; desc: string }[] = [
    { value: 'last_year_fact', label: 'Из факта прошлого года', hint: 'Факт того же месяца прошлого года в этой паре', desc: 'Подставит в выбранные ячейки факт того же месяца прошлого года.' },
    { value: 'average', label: 'Среднее за N месяцев', hint: 'Среднее факта за последние закрытые месяцы', desc: 'Подставит среднее факта за последние закрытые месяцы; месяц без факта считается нулём.' },
    { value: 'distribute', label: 'Распределить годовую сумму', hint: 'Поровну на 12 месяцев', desc: 'Разделит годовую сумму каждой выбранной статьи поровну на 12 месяцев.' },
    { value: 'copy_right', label: 'Копировать месяц вправо', hint: 'Значение месяца — во все месяцы правее', desc: 'Скопирует план исходного месяца во все месяцы правее него.' },
    { value: 'percent_of', label: 'X% от другой статьи', hint: 'Разово считает суммы от плана базы', desc: 'Разово посчитает план как процент от плана статьи-базы в том же месяце; знак — по направлению статьи.' },
];

const FOCUSABLE = 'button:not([disabled]), input:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])';
/** Кнопка «Помощники ▾» в шапке — туда возвращается фокус после закрытия окна. */
const RETURN_FOCUS = '[data-budget-dropdown="helpers"] > button';

export interface BudgetFillDialogProps {
    fillUrl: string;
    csrfToken: string;
    pair: SelectedPair;
    /** «Проект × ЦФО» выбранной пары. */
    sliceLabel: string;
    versionName: string;
    year: number;
    months: string[];
    rows: BudgetGridRow[];
    initialHelper: BudgetFillHelper;
    /** Есть неотправленные правки или идёт сохранение — применение ждёт. */
    busy: boolean;
    onApplied: (changed: number) => void;
    onClose: () => void;
}

const money = (value: string): string => formatValue(value, 'MONEY').whole;
const signedMoney = (value: string): string => (isZero(value) || value.startsWith('-') ? money(value) : `+${money(value)}`);

/**
 * «Быстрое заполнение» (BUDGET-SPEC §5, refs 13, 26): шаг 1 — помощник, параметры, статьи и месяцы;
 * шаг 2 — предпросмотр сервера «было → стало» и применение. Расчёт и запись — `POST …/fill`.
 */
export function BudgetFillDialog(props: BudgetFillDialogProps): JSX.Element {
    const groups = useMemo(() => fillGroups(props.rows), [props.rows]);
    const bases = useMemo(() => props.rows.filter((row) => row.format === 'MONEY'), [props.rows]);
    const lockedNames = useMemo(() => props.rows.filter((row) => row.editable && isLocked(row)).map((row) => row.name), [props.rows]);
    const [helper, setHelper] = useState<BudgetFillHelper>(props.initialHelper);
    const [step, setStep] = useState<'setup' | 'preview'>('setup');
    const [categoryIds, setCategoryIds] = useState<Set<string>>(() => new Set(freeLeafIds(groups)));
    const [months, setMonths] = useState<Set<string>>(() => new Set(props.months));
    const [query, setQuery] = useState('');
    const [average, setAverage] = useState(3);
    const [amount, setAmount] = useState('');
    const [sourceMonth, setSourceMonth] = useState(props.months[0] ?? '');
    const [percent, setPercent] = useState('');
    const [baseId, setBaseId] = useState(() => (bases.find((row) => /выручк/i.test(row.name)) ?? bases[0])?.id ?? '');
    /** Предпросмотр и тело запроса, по которому он посчитан: «Применить» отправляет именно его. */
    const [preview, setPreview] = useState<{ result: BudgetFillResult; body: Record<string, unknown> } | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [sending, setSending] = useState(false);
    const seqRef = useRef(0);
    const dialogRef = useRef<HTMLDivElement>(null);
    const cardsRef = useRef<HTMLDivElement>(null);

    // Окно открыто: страница под ним не прокручивается; фокус — на выбранного помощника.
    // Закрыто: поздний ответ отбрасывается, фокус — на «Помощники ▾».
    useEffect(() => {
        const overflow = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        cardsRef.current?.querySelector<HTMLElement>('[aria-checked="true"]')?.focus();

        return () => {
            seqRef.current += 1;
            document.body.style.overflow = overflow;
            document.querySelector<HTMLElement>(RETURN_FOCUS)?.focus();
        };
    }, []);

    // Esc и Tab — на уровне документа: после смены шага нажатая кнопка исчезает и фокус может
    // оказаться на body, окно всё равно должно закрываться и держать фокус внутри.
    const onCloseRef = useRef(props.onClose);
    onCloseRef.current = props.onClose;
    useEffect(() => {
        const onKeyDown = (event: KeyboardEvent): void => {
            const dialog = dialogRef.current;
            if (dialog === null) {
                return;
            }
            if (event.key === 'Escape') {
                event.preventDefault();
                event.stopPropagation();
                onCloseRef.current();

                return;
            }
            if (event.key !== 'Tab') {
                return;
            }
            const focusable = [...dialog.querySelectorAll<HTMLElement>(FOCUSABLE)];
            if (focusable.length === 0) {
                return;
            }
            const first = focusable[0];
            const last = focusable[focusable.length - 1];
            const inside = dialog.contains(document.activeElement);
            if (event.shiftKey && (document.activeElement === first || !inside)) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && (document.activeElement === last || !inside)) {
                event.preventDefault();
                first.focus();
            }
        };
        document.addEventListener('keydown', onKeyDown, true);

        return () => document.removeEventListener('keydown', onKeyDown, true);
    }, []);

    // Смена шага: шаг 2 — фокус на окно (читается итог), назад — на выбранного помощника.
    const firstStepRef = useRef(true);
    useEffect(() => {
        if (firstStepRef.current) {
            firstStepRef.current = false;

            return;
        }
        if (step === 'preview') {
            dialogRef.current?.focus();
        } else {
            cardsRef.current?.querySelector<HTMLElement>('[aria-checked="true"]')?.focus();
        }
    }, [step]);

    const monthPicker = usesMonthPicker(helper);
    const visible = useMemo(() => filterGroups(groups, query), [groups, query]);
    const visibleIds = useMemo(() => freeLeafIds(visible), [visible]);
    const targets = fillTargets(helper, props.rows, categoryIds, props.months, months, sourceMonth);
    const check = helperParams(helper, { average, amount, sourceMonth, percent, baseId });
    const fieldErrors = check.ok ? {} : check.errors;
    const cellCount = targets.categoryIds.length * targets.months.length;
    const selectedCount = groups.reduce((sum, group) => sum + groupState(group, categoryIds).selected, 0);
    const selectableCount = freeLeafIds(groups).length;

    const request = async (body: Record<string, unknown>, apply: boolean, confirmOverwrite: boolean): Promise<void> => {
        const seq = ++seqRef.current;
        setError(null);
        setSending(true);
        try {
            const result = await apiFetch<BudgetFillResult>(props.fillUrl, {
                method: 'POST',
                csrfToken: props.csrfToken,
                body: { ...body, apply, confirmOverwrite },
            });
            if (seq !== seqRef.current) {
                return;
            }
            if (apply) {
                props.onApplied(result.changed);
            } else {
                setPreview({ result, body });
                setStep('preview');
            }
        } catch (caught) {
            if (seq === seqRef.current) {
                setError(caught instanceof ApiError ? caught.message : 'Не удалось выполнить помощник.');
            }
        } finally {
            if (seq === seqRef.current) {
                setSending(false);
            }
        }
    };

    const showPreview = (): void => {
        if (!check.ok || cellCount === 0) {
            return;
        }
        void request({
            helper,
            project: props.pair.projectDirectionId,
            center: props.pair.responsibilityCenterId,
            categoryIds: targets.categoryIds,
            months: monthPicker ? targets.months : [],
            params: check.params,
        }, false, false);
    };

    const back = (): void => {
        seqRef.current += 1;
        setSending(false);
        setError(null);
        setPreview(null);
        setStep('setup');
    };

    const onCardsKeyDown = (event: ReactKeyboardEvent<HTMLDivElement>): void => {
        const delta = { ArrowDown: 1, ArrowRight: 1, ArrowUp: -1, ArrowLeft: -1 }[event.key];
        if (delta === undefined) {
            return;
        }
        event.preventDefault();
        const index = HELPERS.findIndex((item) => item.value === helper);
        const next = HELPERS[(index + delta + HELPERS.length) % HELPERS.length];
        setHelper(next.value);
        cardsRef.current?.querySelector<HTMLElement>(`[data-budget-fill-helper="${next.value}"]`)?.focus();
    };

    const current = HELPERS.find((item) => item.value === helper) ?? HELPERS[0];
    const table = preview === null ? null : previewTable(preview.result, props.rows, props.months);
    const changed = preview?.result.changed ?? 0;
    const overwrite = preview?.result.overwrite ?? 0;

    const setup = (
        <div className={styles.fillBody}>
            <div className={styles.fillCards} role="radiogroup" aria-label="Помощник" ref={cardsRef} onKeyDown={onCardsKeyDown}>
                {HELPERS.map((item) => (
                    <button
                        key={item.value}
                        type="button"
                        role="radio"
                        aria-checked={item.value === helper}
                        tabIndex={item.value === helper ? 0 : -1}
                        className={styles.fillCard}
                        data-budget-fill-helper={item.value}
                        onClick={() => setHelper(item.value)}
                    >
                        <b>{item.label}</b>
                        <span>{item.hint}</span>
                    </button>
                ))}
            </div>

            <div className={styles.fillSetup}>
                <div className="text-secondary">{current.desc}</div>

                {helper === 'average' && (
                    <div>
                        <label className="form-label" htmlFor="budget-fill-n">За сколько последних закрытых месяцев</label>
                        <select id="budget-fill-n" className="form-select w-auto" value={average} onChange={(event) => setAverage(Number(event.target.value))}>
                            {[3, 6, 12].map((n) => <option key={n} value={n}>{n} мес.</option>)}
                        </select>
                    </div>
                )}
                {helper === 'distribute' && (
                    <div>
                        <label className="form-label" htmlFor="budget-fill-amount">Годовая сумма на каждую выбранную статью, ₽</label>
                        <input
                            id="budget-fill-amount"
                            className={`form-control ${styles.fillField}${fieldErrors.amount !== undefined ? ' is-invalid' : ''}`}
                            inputMode="decimal"
                            value={amount}
                            aria-invalid={fieldErrors.amount !== undefined}
                            aria-describedby="budget-fill-amount-error budget-fill-amount-hint"
                            onChange={(event) => setAmount(event.target.value)}
                        />
                        <div className="invalid-feedback d-block" id="budget-fill-amount-error" role="alert">{fieldErrors.amount}</div>
                        <div className={styles.fillHint} id="budget-fill-amount-hint">Делится поровну на 12 месяцев, остаток копеек — в декабрь. Расход вводите положительным.</div>
                    </div>
                )}
                {helper === 'copy_right' && (
                    <div>
                        <label className="form-label" htmlFor="budget-fill-source">Исходный месяц</label>
                        <select id="budget-fill-source" className="form-select w-auto" value={sourceMonth} onChange={(event) => setSourceMonth(event.target.value)}>
                            {props.months.slice(0, -1).map((month) => <option key={month} value={month}>{MONTH_FULL[monthIndex(month)]}</option>)}
                        </select>
                    </div>
                )}
                {helper === 'percent_of' && (
                    <div className="d-flex flex-wrap gap-3">
                        <div>
                            <label className="form-label" htmlFor="budget-fill-percent">Процент, %</label>
                            <input
                                id="budget-fill-percent"
                                className={`form-control ${styles.fillPercent}${fieldErrors.percent !== undefined ? ' is-invalid' : ''}`}
                                inputMode="decimal"
                                value={percent}
                                aria-invalid={fieldErrors.percent !== undefined}
                                aria-describedby="budget-fill-percent-error"
                                onChange={(event) => setPercent(event.target.value)}
                            />
                            <div className="invalid-feedback d-block" id="budget-fill-percent-error" role="alert">{fieldErrors.percent}</div>
                        </div>
                        <div>
                            <label className="form-label" htmlFor="budget-fill-base">От какой статьи</label>
                            <select
                                id="budget-fill-base"
                                className={`form-select ${styles.fillBase}${fieldErrors.baseId !== undefined ? ' is-invalid' : ''}`}
                                value={baseId}
                                aria-describedby="budget-fill-base-error"
                                onChange={(event) => setBaseId(event.target.value)}
                            >
                                {bases.map((row) => <option key={row.id} value={row.id}>{' '.repeat((row.level - 1) * 2)}{row.name}</option>)}
                            </select>
                            <div className="invalid-feedback d-block" id="budget-fill-base-error" role="alert">{fieldErrors.baseId}</div>
                        </div>
                    </div>
                )}

                <section aria-labelledby="budget-fill-articles">
                    <div className={styles.fillSectionHead}>
                        <span id="budget-fill-articles" className="form-label mb-0">
                            Статьи <span className={styles.fillCount} data-budget-fill-count>· Выбрано {selectedCount} из {selectableCount}</span>
                        </span>
                        <span>
                            <button type="button" className="btn btn-link btn-sm p-0" onClick={() => setCategoryIds((ids) => setSelected(ids, visibleIds, true))}>все</button>
                            <button type="button" className="btn btn-link btn-sm p-0 ms-3" onClick={() => setCategoryIds((ids) => setSelected(ids, visibleIds, false))}>ни одной</button>
                        </span>
                    </div>
                    <input
                        type="search"
                        className="form-control mb-2"
                        placeholder="Найти статью"
                        aria-label="Найти статью"
                        value={query}
                        onChange={(event) => setQuery(event.target.value)}
                    />
                    <div className={styles.fillList} role="group" aria-labelledby="budget-fill-articles" data-budget-fill-list>
                        {visible.map((group) => {
                            const state = groupState(group, categoryIds);
                            const free = group.leaves.filter((row) => !isLocked(row)).map((row) => row.id);

                            return (
                                <div key={group.id} role="group" aria-label={group.name}>
                                    <label className={`${styles.fillRow} ${styles.fillGroupRow}`}>
                                        <input
                                            type="checkbox"
                                            className="form-check-input m-0"
                                            checked={state.state === 'on'}
                                            disabled={state.total === 0}
                                            aria-checked={state.state === 'mixed' ? 'mixed' : state.state === 'on'}
                                            ref={(element) => {
                                                if (element !== null) {
                                                    element.indeterminate = state.state === 'mixed';
                                                }
                                            }}
                                            onChange={() => setCategoryIds((ids) => setSelected(ids, free, state.state !== 'on'))}
                                        />
                                        <span className={styles.fillRowName}>{group.name}</span>
                                        <span className={styles.fillCount}>{state.selected} из {state.total}</span>
                                    </label>
                                    {group.leaves.map((row) => {
                                        const locked = isLocked(row);

                                        return (
                                            <label key={row.id} className={`${styles.fillRow} ${styles.fillLeafRow}${locked ? ` ${styles.fillLocked}` : ''}`}>
                                                <input
                                                    type="checkbox"
                                                    className="form-check-input m-0"
                                                    checked={!locked && categoryIds.has(row.id)}
                                                    disabled={locked}
                                                    onChange={() => setCategoryIds((ids) => setSelected(ids, [row.id], !ids.has(row.id)))}
                                                />
                                                <span className={styles.fillRowName}>{row.name}</span>
                                                {locked && <span className={styles.fillBadge}>правило</span>}
                                            </label>
                                        );
                                    })}
                                </div>
                            );
                        })}
                        {visible.length === 0 && <div className={styles.fillEmpty}>Ничего не найдено</div>}
                    </div>
                    {lockedNames.length > 0 && (
                        <div className={styles.fillHint}>Статьи с правилом «% от строки» ({lockedNames.join(', ')}) считаются сами и не изменяются.</div>
                    )}
                </section>

                <section aria-labelledby="budget-fill-months">
                    <div className={styles.fillSectionHead}>
                        <span id="budget-fill-months" className="form-label mb-0">Месяцы</span>
                    </div>
                    {monthPicker ? (
                        <>
                            <div className={styles.fillChips} role="group" aria-label="Быстрый выбор месяцев">
                                {quarterChips(props.months, months).map((chip) => (
                                    <button key={chip.label} type="button" className={styles.chip} aria-pressed={chip.on} onClick={() => setMonths((set) => setSelected(set, chip.months, !chip.on))}>
                                        {chip.label}
                                    </button>
                                ))}
                                <button type="button" className={styles.chip} aria-pressed={props.months.every((month) => months.has(month))} onClick={() => setMonths(new Set(props.months))}>Все</button>
                                <button type="button" className={styles.chip} aria-pressed={false} onClick={() => setMonths(new Set())}>Сбросить</button>
                            </div>
                            <div className={styles.fillMonths} role="group" aria-labelledby="budget-fill-months">
                                {props.months.map((month) => (
                                    <button
                                        key={month}
                                        type="button"
                                        className={styles.chip}
                                        aria-pressed={months.has(month)}
                                        aria-label={MONTH_FULL[monthIndex(month)]}
                                        onClick={() => setMonths((set) => setSelected(set, [month], !set.has(month)))}
                                    >
                                        {MONTH_SHORT[monthIndex(month)]}
                                    </button>
                                ))}
                            </div>
                        </>
                    ) : (
                        <div className="text-secondary">
                            {helper === 'distribute' ? 'Сумма делится на все 12 месяцев.' : 'Месяцы определяются исходным месяцем: все правее него.'}
                        </div>
                    )}
                </section>
            </div>
        </div>
    );

    const previewStep = preview !== null && table !== null && (
        <div className={styles.fillPreview} data-budget-fill-preview>
            <div className="fw-semibold mb-2" role="status">
                {previewLine(preview.result)}
                {preview.result.skipped > 0 ? `. Пропущено ячеек плана продаж и правил: ${preview.result.skipped}` : ''}
            </div>
            {overwrite > 0 && (
                <div className={`alert alert-warning ${styles.fillWarn}`} role="alert">
                    Перезаписать {overwrite} {plural(overwrite, 'заполненную ячейку', 'заполненные ячейки', 'заполненных ячеек')}? Отмены нет — прежние
                    значения видны в ячейках «было …», вернуться можно только кнопкой «Назад».
                </div>
            )}
            {table.rows.length > 0 && (
                <div className={styles.fillTableWrap}>
                    <table className={styles.fillTable} aria-label="Предпросмотр изменений">
                        <thead>
                            <tr>
                                <th scope="col">Статья</th>
                                {table.months.map((month) => <th key={month} scope="col">{MONTH_SHORT[monthIndex(month)]}</th>)}
                                <th scope="col" className={styles.fillDeltaCol}>Изменение</th>
                            </tr>
                        </thead>
                        <tbody>
                            {table.rows.map((row) => (
                                <tr key={row.id}>
                                    <th scope="row">{row.name}</th>
                                    {row.cells.map((cell) => (
                                        <td
                                            key={cell.month}
                                            className={cell.amount === null ? styles.fillSame : cell.overwrite ? styles.fillOverwrite : undefined}
                                            data-budget-fill-cell={cell.amount === null ? 'same' : cell.overwrite ? 'overwrite' : 'new'}
                                        >
                                            {cell.amount === null ? (
                                                <>
                                                    <span className={styles.fillNew}>{money(cell.previous)}</span>
                                                    <span className={styles.fillOld}>без изменений</span>
                                                </>
                                            ) : (
                                                <>
                                                    <span className={styles.fillNew}>{money(cell.amount)}</span>
                                                    {!isZero(cell.previous) && <del className={styles.fillOld}>было {money(cell.previous)}</del>}
                                                </>
                                            )}
                                        </td>
                                    ))}
                                    <td className={styles.fillDeltaCol}>{signedMoney(row.delta)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </div>
    );

    return createPortal(
        <div
            className={styles.fillScrim}
            onMouseDown={(event) => {
                if (event.target === event.currentTarget) {
                    props.onClose();
                }
            }}
        >
            <div
                ref={dialogRef}
                className={styles.fillDialog}
                role="dialog"
                aria-modal="true"
                aria-labelledby="budget-fill-title"
                tabIndex={-1}
                data-budget-fill
            >
                <div className={styles.fillHead}>
                    <div className={styles.fillHeadText}>
                        <h2 id="budget-fill-title" className={styles.fillTitle}>Быстрое заполнение</h2>
                        <div className="text-secondary">
                            Разрез: <b className="text-body">{props.sliceLabel}</b> · вариант «{props.versionName}» · {props.year}
                        </div>
                    </div>
                    <div className="d-flex align-items-center gap-3">
                        <ol className={styles.fillSteps} aria-label="Шаги">
                            <li aria-current={step === 'setup' ? 'step' : undefined}>1 Настройка</li>
                            <li aria-current={step === 'preview' ? 'step' : undefined}>2 Проверка</li>
                        </ol>
                        <button type="button" className="btn-close" aria-label="Закрыть" onClick={props.onClose} />
                    </div>
                </div>

                {step === 'setup' ? setup : previewStep}

                <div className={styles.fillFoot}>
                    <div className={styles.fillFootInfo}>
                        {error !== null && <div className="text-danger" role="alert" data-budget-fill-error>{error}</div>}
                        {step === 'setup' && <div className="fw-medium" data-budget-fill-targets>{targetsLine(targets, monthPicker)}</div>}
                        {step === 'preview' && props.busy && changed > 0 && <div className="text-secondary" role="status">Дождитесь сохранения правок в сетке.</div>}
                    </div>
                    <div className="d-flex flex-wrap gap-2">
                        {step === 'preview' && <button type="button" className="btn" onClick={back}>← Назад</button>}
                        <button type="button" className="btn" onClick={props.onClose}>Отмена</button>
                        {step === 'setup' ? (
                            <button type="button" className="btn btn-primary" disabled={sending || !check.ok || cellCount === 0} onClick={showPreview}>
                                {sending ? 'Считаю…' : 'Показать изменения'}
                            </button>
                        ) : changed === 0 ? (
                            <button type="button" className="btn btn-primary" onClick={props.onClose}>Закрыть без изменений</button>
                        ) : (
                            <button
                                type="button"
                                className="btn btn-primary"
                                disabled={sending || props.busy}
                                data-budget-fill-apply
                                onClick={() => preview !== null && void request(preview.body, true, overwrite > 0)}
                            >
                                {sending ? 'Применяю…' : `Применить (${changed})`}
                            </button>
                        )}
                    </div>
                </div>
            </div>
        </div>,
        document.body,
    );
}
