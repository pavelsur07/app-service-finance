import { useEffect, useMemo, useRef, useState } from 'react';
import { ApiError, apiFetch } from '@/api/client';
import { formatPercent, parseAmountInput } from './amount';
import type { BudgetGridRow, SelectedPair } from './budget-grid.types';
import styles from './BudgetGrid.module.css';

export interface BudgetRulePanelProps {
    rulesUrl: string;
    rulesDeleteUrl: string;
    csrfToken: string;
    pair: SelectedPair;
    rows: BudgetGridRow[];
    /** Есть неотправленные правки или идёт сохранение — правило ждёт. */
    busy: boolean;
    onChanged: (message: string) => void;
    onClose: () => void;
}

/**
 * Правило «X % от строки» (задача pl-budget, этап 9): статья пары считается сама — X % от плана
 * строки-базы в том же месяце, знак по направлению статьи; меняется база — сервер пересчитывает.
 * Задать / изменить / снять; расчёт и проверки (цепочки, база с правилом) — на сервере.
 */
export function BudgetRulePanel(props: BudgetRulePanelProps): JSX.Element {
    const targets = useMemo(() => props.rows.filter((row) => row.type === 'LEAF_INPUT' && (row.editable || row.rule !== null)), [props.rows]);
    const ruled = useMemo(() => props.rows.filter((row) => row.rule !== null), [props.rows]);
    const [targetId, setTargetId] = useState(() => targets.find((row) => row.rule === null && row.flow === 'expense')?.id ?? targets[0]?.id ?? '');
    const bases = useMemo(
        () => props.rows.filter((row) => row.format === 'MONEY' && row.rule === null && row.id !== targetId),
        [props.rows, targetId],
    );
    const [baseId, setBaseId] = useState(() => (props.rows.find((row) => row.format === 'MONEY' && row.rule === null && /выручк/i.test(row.name)) ?? props.rows.find((row) => row.format === 'MONEY'))?.id ?? '');
    const [percent, setPercent] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [sending, setSending] = useState(false);
    const seqRef = useRef(0);
    // Панель размонтирована (сменили пару, закрыли): поздний ответ не должен вызвать onChanged.
    useEffect(() => () => {
        seqRef.current += 1;
    }, []);

    const send = async (url: string, body: Record<string, string>, message: string): Promise<void> => {
        const seq = ++seqRef.current;
        setError(null);
        setSending(true);
        try {
            await apiFetch<unknown>(url, { method: 'POST', csrfToken: props.csrfToken, body });
            if (seq === seqRef.current) {
                props.onChanged(message);
            }
        } catch (caught) {
            if (seq === seqRef.current) {
                setError(caught instanceof ApiError ? caught.message : 'Не удалось сохранить правило.');
            }
        } finally {
            if (seq === seqRef.current) {
                setSending(false);
            }
        }
    };

    const save = (): void => {
        const parsed = parseAmountInput(percent);
        if (parsed === null || parsed.startsWith('-') || parsed === '0.00') {
            setError('Введите процент больше нуля, например 12,5.');

            return;
        }
        void send(props.rulesUrl, {
            project: props.pair.projectDirectionId,
            center: props.pair.responsibilityCenterId,
            categoryId: targetId,
            baseCategoryId: baseId,
            percent: parsed,
        }, 'Правило сохранено, суммы пересчитаны.');
    };

    const edit = (row: BudgetGridRow): void => {
        if (row.rule === null) {
            return;
        }
        setTargetId(row.id);
        setBaseId(row.rule.baseCategoryId);
        setPercent(formatPercent(row.rule.percent).replace(/\s%$/, ''));
        setError(null);
    };

    return (
        <section className={styles.fillPanel} aria-labelledby="budget-rule-title" data-budget-rule-panel>
            <div className="d-flex align-items-center gap-2 mb-2">
                <h2 id="budget-rule-title" className="h4 mb-0">Правило «% от строки»</h2>
                <button type="button" className="btn btn-sm ms-auto" onClick={props.onClose}>Закрыть</button>
            </div>
            <p className="small text-secondary mb-2">
                Статья считается сама: X % от плана строки-базы в том же месяце, расход — с минусом. Меняется база — суммы
                пересчитываются при сохранении. Статьи с правилом в базу не входят; вручную такую статью не правят.
            </p>

            <fieldset disabled={sending} className="row g-2 align-items-end">
                <div className="col-md-4">
                    <label className="form-label" htmlFor="budget-rule-target">Статья</label>
                    <select id="budget-rule-target" className="form-select form-select-sm" value={targetId} onChange={(event) => { setTargetId(event.target.value); setError(null); }}>
                        {targets.map((row) => <option key={row.id} value={row.id}>{row.name}{row.rule !== null ? ' (есть правило)' : ''}</option>)}
                    </select>
                </div>
                <div className="col-md-4">
                    <label className="form-label" htmlFor="budget-rule-base">От строки</label>
                    <select id="budget-rule-base" className="form-select form-select-sm" value={baseId} onChange={(event) => { setBaseId(event.target.value); setError(null); }}>
                        {bases.map((row) => <option key={row.id} value={row.id}>{' '.repeat((row.level - 1) * 2)}{row.name}</option>)}
                    </select>
                </div>
                <div className="col-6 col-md-2">
                    <label className="form-label" htmlFor="budget-rule-percent">Процент, %</label>
                    <input id="budget-rule-percent" className="form-control form-control-sm" inputMode="decimal" value={percent} onChange={(event) => { setPercent(event.target.value); setError(null); }} />
                </div>
                <div className="col-6 col-md-2">
                    <button type="button" className="btn btn-sm btn-primary w-100" disabled={props.busy || targetId === '' || baseId === ''} onClick={save}>
                        Сохранить правило
                    </button>
                </div>
            </fieldset>

            {error !== null && <div className="text-danger mt-2" role="alert">{error}</div>}
            {props.busy && <div className="text-secondary mt-2" role="status">Дождитесь сохранения правок в сетке.</div>}

            {ruled.length > 0 && (
                <ul className="list-unstyled small mt-3 mb-0" data-budget-rule-list>
                    {ruled.map((row) => row.rule !== null && (
                        <li key={row.id} className="d-flex flex-wrap align-items-center gap-2 mb-1">
                            <span>{row.name} — {formatPercent(row.rule.percent)} от «{row.rule.baseName}»</span>
                            <button type="button" className="btn btn-link btn-sm p-0" disabled={sending} onClick={() => edit(row)}>изменить</button>
                            <button
                                type="button"
                                className="btn btn-link btn-sm p-0 text-danger"
                                disabled={sending || props.busy}
                                onClick={() => void send(props.rulesDeleteUrl, { project: props.pair.projectDirectionId, center: props.pair.responsibilityCenterId, categoryId: row.id }, 'Правило снято, суммы остались как введённые.')}
                            >
                                снять
                            </button>
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}
