import { useMemo, useRef, useState } from 'react';
import { ApiError, apiFetch } from '@/api/client';
import { formatValue } from './amount';
import type { BudgetGridRow, BudgetImportResult, SelectedPair } from './budget-grid.types';
import styles from './BudgetGrid.module.css';

const MONTH_LABELS = ['янв', 'фев', 'мар', 'апр', 'май', 'июн', 'июл', 'авг', 'сен', 'окт', 'ноя', 'дек'];
const MAX_BYTES = 2 * 1024 * 1024;

export interface BudgetImportPanelProps {
    importUrl: string;
    csrfToken: string;
    pair: SelectedPair;
    pairLabel: string;
    rows: BudgetGridRow[];
    /** Есть неотправленные правки или идёт сохранение — загрузка ждёт. */
    busy: boolean;
    onApplied: (changed: number) => void;
    onClose: () => void;
}

const monthLabel = (month: string): string => MONTH_LABELS[Number(month.slice(5, 7)) - 1] ?? month;

/**
 * Загрузка плана пары из xlsx (формат кнопки «Excel», задача pl-budget, этап 7): файл →
 * «Показать изменения» (предпросмотр сервера: ячейки, предупреждения, пропущенные строки) →
 * «Применить»; перезапись заполненных — отдельной кнопкой. Запись — на сервере (`POST …/import`).
 */
export function BudgetImportPanel(props: BudgetImportPanelProps): JSX.Element {
    const [file, setFile] = useState<File | null>(null);
    const [preview, setPreview] = useState<BudgetImportResult | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [sending, setSending] = useState(false);
    const seqRef = useRef(0);
    const names = useMemo(() => new Map(props.rows.map((row) => [row.id, row.name])), [props.rows]);

    const request = async (apply: boolean, confirmOverwrite: boolean): Promise<void> => {
        if (file === null) {
            setError('Выберите файл xlsx.');

            return;
        }
        if (file.size > MAX_BYTES) {
            setError('Файл больше 2 МБ.');

            return;
        }
        const seq = ++seqRef.current;
        const body = new FormData();
        body.append('file', file);
        body.append('project', props.pair.projectDirectionId);
        body.append('center', props.pair.responsibilityCenterId);
        body.append('apply', apply ? '1' : '');
        body.append('confirmOverwrite', confirmOverwrite ? '1' : '');
        setError(null);
        setSending(true);
        try {
            const result = await apiFetch<BudgetImportResult>(props.importUrl, { method: 'POST', csrfToken: props.csrfToken, body });
            if (seq !== seqRef.current) {
                return;
            }
            if (apply) {
                props.onApplied(result.changed);
            } else {
                setPreview(result);
            }
        } catch (caught) {
            if (seq === seqRef.current) {
                setError(caught instanceof ApiError ? caught.message : 'Не удалось загрузить файл.');
            }
        } finally {
            if (seq === seqRef.current) {
                setSending(false);
            }
        }
    };

    return (
        <section className={styles.fillPanel} aria-labelledby="budget-import-title" data-budget-import>
            <div className="d-flex align-items-center gap-2 mb-2">
                <h2 id="budget-import-title" className="h4 mb-0">Загрузка из Excel</h2>
                <button type="button" className="btn btn-sm ms-auto" onClick={props.onClose}>Закрыть</button>
            </div>
            <p className="small text-secondary mb-2">
                Файл — выгрузка кнопкой «Excel», суммы правятся в нём. Загружается в пару «{props.pairLabel}».
                Статьи расходов — положительными; пустая ячейка — ноль; итоги и статьи, которых нет в файле, не меняются.
            </p>
            <fieldset disabled={sending}>
                <label className="form-label" htmlFor="budget-import-file">Файл .xlsx</label>
                <input
                    id="budget-import-file"
                    type="file"
                    accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                    className="form-control form-control-sm"
                    onChange={(event) => {
                        seqRef.current += 1;
                        setFile(event.target.files?.[0] ?? null);
                        setPreview(null);
                        setError(null);
                    }}
                />
            </fieldset>

            {error !== null && <div className="text-danger mt-2" role="alert">{error}</div>}
            {props.busy && <div className="text-secondary mt-2" role="status">Дождитесь сохранения правок в сетке.</div>}

            {preview !== null && (
                <div className="mt-3" role="status" data-budget-import-preview>
                    {preview.warnings.length > 0 && (
                        <ul className="text-warning small mb-2">
                            {preview.warnings.map((warning) => <li key={warning}>{warning}</li>)}
                        </ul>
                    )}
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
                                        {names.get(cell.plCategoryId) ?? 'статья'} · {monthLabel(cell.month)}: {formatValue(cell.previous, 'MONEY').whole} → {formatValue(cell.amount, 'MONEY').whole}
                                    </li>
                                ))}
                                {preview.cells.length > 8 && <li>и ещё {preview.cells.length - 8}</li>}
                            </ul>
                        </>
                    )}
                    {preview.ignored.length > 0 && (
                        <details className="small mt-2" data-budget-import-ignored>
                            <summary>Не загружаются строк: {preview.ignored.length}</summary>
                            <ul className="mb-0">
                                {preview.ignored.map((line) => <li key={line}>{line}</li>)}
                            </ul>
                        </details>
                    )}
                </div>
            )}

            <div className="d-flex flex-wrap gap-2 mt-3">
                <button type="button" className="btn btn-sm" disabled={sending || file === null} onClick={() => void request(false, false)}>Показать изменения</button>
                {preview !== null && preview.changed > 0 && (
                    preview.overwrite > 0 ? (
                        <button type="button" className="btn btn-sm btn-danger" disabled={sending || props.busy} onClick={() => void request(true, true)}>
                            Перезаписать заполненные ({preview.overwrite}) и применить
                        </button>
                    ) : (
                        <button type="button" className="btn btn-sm btn-primary" disabled={sending || props.busy} onClick={() => void request(true, false)}>
                            Применить
                        </button>
                    )
                )}
            </div>
        </section>
    );
}
