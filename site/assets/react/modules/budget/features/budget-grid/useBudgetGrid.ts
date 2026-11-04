import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { ApiError, apiFetch } from '@/api/client';
import { addAmounts } from './amount';
import type { BudgetGridData, BudgetGridProps, PendingCell, SaveState, SelectedPair } from './budget-grid.types';
import type { BudgetSlice } from './slice';
import { sliceParams, sliceToPair } from './slice';

const SAVE_DELAY_MS = 1000;

export interface UseBudgetGridResult {
    data: BudgetGridData | null;
    loading: boolean;
    loadError: string | null;
    saveState: SaveState;
    saveError: string | null;
    /** Сервер отказал в правке (вариант утвердили в другой вкладке) — дальше только чтение. */
    lockedMessage: string | null;
    /** Правки, не дошедшие до сервера (показываются после ошибки или блокировки). */
    unsaved: PendingCell[];
    setCells: (cells: PendingCell[]) => void;
    retry: () => void;
    hasPending: () => boolean;
    flushNow: (keepalive: boolean) => void;
    /** Сервер сам изменил ячейки пары (помощник): отправленные правки поверх ответа больше не держим. */
    reloadAfterServerWrite: () => void;
}

export const pendingKey = (cell: PendingCell): string =>
    `${cell.rowId}|${cell.month}|${cell.projectDirectionId}|${cell.responsibilityCenterId}`;

const samePair = (cell: PendingCell, pair: SelectedPair | null): boolean =>
    pair !== null && cell.projectDirectionId === pair.projectDirectionId && cell.responsibilityCenterId === pair.responsibilityCenterId;

/**
 * Данные сетки варианта для разреза и автосохранение правок.
 *
 * Правка хранит свою пару «проект × ЦФО» и живёт в overlay поверх ответа сервера,
 * пока перечитанный ответ её не подтвердит: перезагрузка итогов после сохранения
 * и смена пары не теряют введённое. Повтор пачки безопасен — сервер делает upsert.
 */
export function useBudgetGrid(props: BudgetGridProps, slice: BudgetSlice): UseBudgetGridResult {
    // Пара — только при обоих измерениях; сумма по одному измерению или всем — просмотр.
    const pair = useMemo(() => sliceToPair(slice), [slice]);
    const [server, setServer] = useState<BudgetGridData | null>(null);
    const [loading, setLoading] = useState(true);
    const [loadError, setLoadError] = useState<string | null>(null);
    const [overlay, setOverlay] = useState<Map<string, PendingCell>>(() => new Map());
    const [saveState, setSaveState] = useState<SaveState>('saved');
    const [saveError, setSaveError] = useState<string | null>(null);
    const [lockedMessage, setLockedMessage] = useState<string | null>(null);

    const overlayRef = useRef(overlay);
    /** Ключи правок, ещё не отправленных или упавших при отправке. */
    const unsentRef = useRef<Set<string>>(new Set());
    const timerRef = useRef<number | null>(null);
    const savingRef = useRef(false);
    const lockedRef = useRef(false);
    const loadSeqRef = useRef(0);
    const pairRef = useRef(pair);
    pairRef.current = pair;

    const gridUrl = useMemo((): string => {
        if (slice.project === null && slice.center === null) {
            return props.gridUrl;
        }

        return `${props.gridUrl}?${new URLSearchParams(sliceParams(slice)).toString()}`;
    }, [props.gridUrl, slice]);

    const updateOverlay = useCallback((change: (current: Map<string, PendingCell>) => Map<string, PendingCell>): void => {
        const next = change(overlayRef.current);
        overlayRef.current = next;
        setOverlay(next);
    }, []);

    const load = useCallback(async (): Promise<void> => {
        const seq = ++loadSeqRef.current;
        try {
            const data = await apiFetch<BudgetGridData>(gridUrl);
            if (seq !== loadSeqRef.current) {
                return;
            }
            setServer(data);
            setLoadError(null);
            // Правки этой пары, которые сервер уже вернул, больше не нужны поверх ответа.
            updateOverlay((current) => {
                const next = new Map(current);
                for (const [key, cell] of current) {
                    if (unsentRef.current.has(key) || !samePair(cell, pair)) {
                        continue;
                    }
                    const row = data.rows.find((candidate) => candidate.id === cell.rowId);
                    const confirmed = row !== undefined
                        && addAmounts([row.values[cell.month] ?? '0']) === addAmounts([cell.amount])
                        && (row.comments[cell.month] ?? null) === cell.comment;
                    if (confirmed || row === undefined) {
                        next.delete(key);
                    }
                }

                return next;
            });
        } catch (error) {
            if (seq === loadSeqRef.current) {
                setLoadError(error instanceof ApiError ? error.message : 'Не удалось загрузить сетку.');
            }
        } finally {
            if (seq === loadSeqRef.current) {
                setLoading(false);
            }
        }
    }, [gridUrl, pair, updateOverlay]);

    // Смена пары: данные прежней пары не показываются и не правятся, пока не придут новые.
    useEffect(() => {
        setServer(null);
        setLoading(true);
        void load();
    }, [load]);

    const loadRef = useRef(load);
    loadRef.current = load;

    const flush = useCallback(async (keepalive = false): Promise<void> => {
        if (savingRef.current || lockedRef.current || unsentRef.current.size === 0) {
            return;
        }
        const keys = [...unsentRef.current];
        const cells = keys
            .map((key) => overlayRef.current.get(key))
            .filter((cell): cell is PendingCell => cell !== undefined);
        unsentRef.current = new Set();
        savingRef.current = true;
        setSaveState('saving');
        let succeeded = false;
        try {
            await apiFetch<{ saved: number }>(props.cellsUrl, {
                method: 'POST',
                csrfToken: props.csrfToken,
                keepalive,
                body: {
                    cells: cells.map((cell) => ({
                        plCategoryId: cell.rowId,
                        month: cell.month,
                        projectDirectionId: cell.projectDirectionId,
                        responsibilityCenterId: cell.responsibilityCenterId,
                        amount: cell.amount,
                        comment: cell.comment,
                    })),
                },
            });
            succeeded = true;
            setSaveError(null);
            setSaveState('saved');
            // Отправленные правки чужих пар подтверждать нечем — убрать сразу.
            updateOverlay((current) => {
                const next = new Map(current);
                for (const cell of cells) {
                    const key = pendingKey(cell);
                    if (!unsentRef.current.has(key) && next.get(key) === cell && !samePair(cell, pairRef.current)) {
                        next.delete(key);
                    }
                }

                return next;
            });
            if (!keepalive) {
                void loadRef.current();
            }
        } catch (error) {
            // Неотправленное возвращается в очередь: «Повторить» или следующая правка пошлёт его.
            for (const key of keys) {
                unsentRef.current.add(key);
            }
            setSaveState('failed');
            if (error instanceof ApiError && error.status === 409) {
                lockedRef.current = true;
                setLockedMessage(error.message);
            }
            setSaveError(error instanceof ApiError ? error.message : 'Не удалось сохранить.');
        } finally {
            savingRef.current = false;
        }
        // Правки, пришедшие во время отправки, уходят следующей пачкой сразу.
        if (succeeded && unsentRef.current.size > 0) {
            void flushRef.current();
        }
    }, [props.cellsUrl, props.csrfToken, updateOverlay]);

    const flushRef = useRef(flush);
    flushRef.current = flush;

    const setCells = useCallback((cells: PendingCell[]): void => {
        if (lockedRef.current || cells.length === 0) {
            return;
        }
        updateOverlay((current) => {
            const next = new Map(current);
            for (const cell of cells) {
                next.set(pendingKey(cell), cell);
            }

            return next;
        });
        for (const cell of cells) {
            unsentRef.current.add(pendingKey(cell));
        }
        if (timerRef.current !== null) {
            window.clearTimeout(timerRef.current);
        }
        timerRef.current = window.setTimeout(() => {
            timerRef.current = null;
            void flushRef.current();
        }, SAVE_DELAY_MS);
    }, [updateOverlay]);

    const retry = useCallback((): void => {
        void flushRef.current();
    }, []);

    /** Есть правки, которые ещё не подтвердил сервер (очередь или запрос в пути). */
    const hasPending = useCallback((): boolean => unsentRef.current.size > 0 || savingRef.current, []);

    /** Отправить очередь сейчас; `keepalive` — при уходе со страницы. */
    const flushNow = useCallback((keepalive: boolean): void => {
        if (timerRef.current !== null) {
            window.clearTimeout(timerRef.current);
            timerRef.current = null;
        }
        void flushRef.current(keepalive);
    }, []);

    useEffect(() => () => {
        if (timerRef.current !== null) {
            window.clearTimeout(timerRef.current);
        }
    }, []);

    const data = useMemo((): BudgetGridData | null => {
        if (server === null) {
            return null;
        }
        const edits = [...overlay.values()].filter((cell) => samePair(cell, pair));
        if (edits.length === 0) {
            return server;
        }

        return {
            ...server,
            rows: server.rows.map((row) => {
                const rowEdits = edits.filter((cell) => cell.rowId === row.id);
                if (rowEdits.length === 0) {
                    return row;
                }
                const values = { ...row.values };
                const comments = { ...row.comments };
                for (const cell of rowEdits) {
                    values[cell.month] = cell.amount;
                    if (cell.comment === null) {
                        delete comments[cell.month];
                    } else {
                        comments[cell.month] = cell.comment;
                    }
                }

                return { ...row, values, comments, total: addAmounts(Object.values(values)) };
            }),
        };
    }, [server, overlay, pair]);

    const unsaved = useMemo(
        (): PendingCell[] => (saveState === 'failed' || lockedMessage !== null ? [...overlay.values()] : []),
        [overlay, saveState, lockedMessage],
    );

    const reloadAfterServerWrite = useCallback((): void => {
        updateOverlay((current) => {
            const next = new Map(current);
            for (const [key, cell] of current) {
                if (!unsentRef.current.has(key) && samePair(cell, pair)) {
                    next.delete(key);
                }
            }

            return next;
        });
        void load();
    }, [load, pair, updateOverlay]);

    return { data, loading, loadError, saveState, saveError, lockedMessage, unsaved, setCells, retry, hasPending, flushNow, reloadAfterServerWrite };
}
