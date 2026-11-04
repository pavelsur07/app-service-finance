import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import type { ClipboardEvent, KeyboardEvent, MouseEvent } from 'react';
import { inputToStored, isZero, parseAmountInput, storedToInput } from './amount';
import type { BudgetFillHelper, BudgetGridProps, BudgetGridRow, CellPosition, PendingCell, SelectedPair } from './budget-grid.types';
import { BudgetFillPanel } from './BudgetFillPanel';
import { BudgetImportPanel } from './BudgetImportPanel';
import { BudgetRulePanel } from './BudgetRulePanel';
import { BudgetGridView } from './BudgetGridView';
import type { CommentDraft, VisibleRow } from './BudgetGridView';
import { BudgetSlicePills } from './BudgetSlicePills';
import { HeadSlot, HelpersMenu, SaveIndicator } from './BudgetHeadControls';
import type { BudgetSlice } from './slice';
import { initialSlice, sliceKey, sliceParams, sliceSumLabel, sliceToPair } from './slice';
import { useBudgetGrid } from './useBudgetGrid';

const COLLAPSED_STORAGE_KEY = 'vf.budget.collapsed';
const MONTH_NAMES = ['январь', 'февраль', 'март', 'апрель', 'май', 'июнь', 'июль', 'август', 'сентябрь', 'октябрь', 'ноябрь', 'декабрь'];

function readCollapsed(): Set<string> {
    try {
        const raw = window.localStorage.getItem(COLLAPSED_STORAGE_KEY);
        const parsed: unknown = raw === null ? [] : JSON.parse(raw);

        return new Set(Array.isArray(parsed) ? parsed.filter((id): id is string => typeof id === 'string') : []);
    } catch {
        return new Set();
    }
}

function writeCollapsed(ids: Set<string>): void {
    try {
        window.localStorage.setItem(COLLAPSED_STORAGE_KEY, JSON.stringify([...ids]));
    } catch {
        // Свёртка — удобство: без хранилища просто не запоминается.
    }
}

/** Строки с учётом свёрнутых групп: потомки — строки ниже с уровнем больше, до первой не глубже. */
function visibleRows(rows: BudgetGridRow[], collapsed: Set<string>): VisibleRow[] {
    const result: VisibleRow[] = [];
    let hideDeeperThan: number | null = null;
    rows.forEach((row, index) => {
        if (hideDeeperThan !== null && row.level > hideDeeperThan) {
            return;
        }
        hideDeeperThan = null;
        const hasChildren = (rows[index + 1]?.level ?? 0) > row.level;
        const isCollapsed = hasChildren && collapsed.has(row.id);
        if (isCollapsed) {
            hideDeeperThan = row.level;
        }
        result.push({ row, hasChildren, collapsed: isCollapsed });
    });

    return result;
}

interface EditState {
    position: CellPosition;
    text: string;
    selectAll: boolean;
}

interface PendingTarget extends SelectedPair {
    rowId: string;
    month: string;
}

const pairValueOf = (pair: SelectedPair | null): string =>
    pair === null ? 'all' : `${pair.projectDirectionId}|${pair.responsibilityCenterId}`;

/**
 * Smart-часть сетки бюджета: выбор ячейки, клавиатура, вставка из Excel, комментарий, свёртка
 * групп, разрез «проект / ЦФО» в адресе; индикатор сохранения и «Помощники ▾» — порталом в шапку
 * страницы (BUDGET-SPEC §5).
 */
export function BudgetGridWidget(props: BudgetGridProps): JSX.Element {
    const [slice, setSlice] = useState<BudgetSlice>(() => initialSlice(props.pairs, window.location.search));
    const pair = useMemo(() => sliceToPair(slice), [slice]);
    const grid = useBudgetGrid(props, slice);
    const [collapsed, setCollapsed] = useState<Set<string>>(readCollapsed);
    const [active, setActive] = useState<CellPosition | null>(null);
    const [editing, setEditingState] = useState<EditState | null>(null);
    /** Синхронная копия: blur приходит раньше, чем React применит setState. */
    const editingRef = useRef(editing);
    const setEditing = (value: EditState | null): void => {
        editingRef.current = value;
        setEditingState(value);
    };
    const [invalid, setInvalid] = useState<Map<string, string>>(() => new Map());
    const [pasteMessage, setPasteMessage] = useState<string | null>(null);
    /** Черновик комментария привязан к статье, месяцу и паре, а не к номеру видимой строки. */
    const [comment, setComment] = useState<(CommentDraft & PendingTarget) | null>(null);
    const scrollerRef = useRef<HTMLDivElement>(null);
    const inputRef = useRef<HTMLInputElement>(null);
    const [fillOpen, setFillOpen] = useState(false);
    const [fillHelper, setFillHelper] = useState<BudgetFillHelper>('last_year_fact');
    const [fillMessage, setFillMessage] = useState<string | null>(null);
    const [importOpen, setImportOpen] = useState(false);
    const [rulesOpen, setRulesOpen] = useState(false);

    const months = grid.data?.months ?? [];
    const rows = useMemo(() => visibleRows(grid.data?.rows ?? [], collapsed), [grid.data, collapsed]);

    const canEdit = props.editable && props.canWrite && pair !== null && grid.lockedMessage === null;
    // Не черновик и роль «Чтение» — баннеры страницы; здесь только режим суммы.
    let readOnlyHint: string | null = null;
    if (props.editable && props.canWrite && pair === null) {
        readOnlyHint = `Выберите проект и ЦФО, чтобы вводить план. Сейчас показана сумма ${sliceSumLabel(props.pairs, slice)} — только просмотр.`;
    }

    const rowAt = (position: CellPosition): BudgetGridRow | undefined => rows[position.row]?.row;
    const cellEditable = (position: CellPosition): boolean => canEdit && (rowAt(position)?.editable ?? false);

    // Enter/F2/двойной клик выделяют значение целиком; начатый с цифры ввод продолжает её.
    useEffect(() => {
        const input = inputRef.current;
        if (input === null || editingRef.current === null) {
            return;
        }
        input.focus();
        if (editingRef.current.selectAll) {
            input.select();
        } else {
            input.setSelectionRange(input.value.length, input.value.length);
        }
    }, [editing?.position.row, editing?.position.col]);

    // Активная ячейка остаётся видимой при перемещении клавиатурой.
    useEffect(() => {
        if (active === null) {
            return;
        }
        const cell = scrollerRef.current?.querySelector<HTMLElement>(`tbody tr:nth-child(${active.row + 1}) td:nth-child(${active.col + 2})`);
        cell?.scrollIntoView({ block: 'nearest', inline: 'nearest' });
    }, [active]);

    const focusGrid = (): void => {
        scrollerRef.current?.focus({ preventScroll: true });
    };

    const move = useCallback((position: CellPosition, rowDelta: number, colDelta: number): CellPosition => {
        const row = Math.min(Math.max(position.row + rowDelta, 0), Math.max(rows.length - 1, 0));
        const col = Math.min(Math.max(position.col + colDelta, 0), Math.max(months.length - 1, 0));

        return { row, col };
    }, [rows.length, months.length]);

    const pendingFor = (row: BudgetGridRow, month: string, amount: string, commentText: string | null): PendingCell | null => {
        if (pair === null) {
            return null;
        }

        return { rowId: row.id, month, amount, comment: commentText, projectDirectionId: pair.projectDirectionId, responsibilityCenterId: pair.responsibilityCenterId };
    };

    const clearInvalid = (key: string): void => {
        setInvalid((current) => {
            if (!current.has(key)) {
                return current;
            }
            const next = new Map(current);
            next.delete(key);

            return next;
        });
    };

    /** Записать ввод в ячейку; false — значение не число (ячейка помечена ошибкой). */
    const commitText = (position: CellPosition, text: string): boolean => {
        const row = rowAt(position);
        const month = months[position.col];
        if (row === undefined || month === undefined || !cellEditable(position)) {
            return true;
        }
        const key = `${row.id}|${month}`;
        const parsed = parseAmountInput(text);
        if (parsed === null) {
            setInvalid((current) => new Map(current).set(key, `«${text.trim()}» — не сумма (${row.name}, ${MONTH_NAMES[position.col]}). Примеры: 600000, 600 000, 600000,50.`));

            return false;
        }
        clearInvalid(key);
        const stored = inputToStored(parsed, row.flow);
        const current = row.values[month] ?? '0.00';
        if (parseAmountInput(current) === parseAmountInput(stored) || (isZero(current) && isZero(stored))) {
            return true;
        }
        const cell = pendingFor(row, month, stored, row.comments[month] ?? null);
        if (cell !== null) {
            grid.setCells([cell]);
        }

        return true;
    };

    const commitTextRef = useRef(commitText);
    commitTextRef.current = commitText;

    const startEdit = (position: CellPosition, text?: string): void => {
        const row = rowAt(position);
        const month = months[position.col];
        if (row === undefined || month === undefined || !cellEditable(position)) {
            return;
        }
        setActive(position);
        setEditing({ position, text: text ?? storedToInput(row.values[month] ?? '0', row.flow), selectAll: text === undefined });
    };

    const finishEdit = (rowDelta: number, colDelta: number): void => {
        const current = editingRef.current;
        if (current === null) {
            return;
        }
        setEditing(null);
        const ok = commitText(current.position, current.text);
        setActive(ok ? move(current.position, rowDelta, colDelta) : current.position);
        focusGrid();
    };

    /** Клик мимо поля: сохранить ввод, активную ячейку и фокус не трогать. */
    const onEditBlur = (): void => {
        const current = editingRef.current;
        if (current === null) {
            return;
        }
        setEditing(null);
        commitText(current.position, current.text);
    };

    const openComment = (position: CellPosition): void => {
        const row = rowAt(position);
        const month = months[position.col];
        if (row === undefined || month === undefined || !cellEditable(position)) {
            return;
        }
        if (pair === null) {
            return;
        }
        setActive(position);
        setComment({ ...pair, rowId: row.id, month, title: `${row.name}, ${MONTH_NAMES[position.col]}`, text: row.comments[month] ?? '' });
    };

    const saveComment = (): void => {
        if (comment === null) {
            return;
        }
        const row = grid.data?.rows.find((candidate) => candidate.id === comment.rowId);
        const samePair = pair !== null && pair.projectDirectionId === comment.projectDirectionId && pair.responsibilityCenterId === comment.responsibilityCenterId;
        if (row !== undefined && samePair) {
            const text = comment.text.trim();
            grid.setCells([{
                rowId: row.id,
                month: comment.month,
                amount: row.values[comment.month] ?? '0.00',
                comment: text === '' ? null : text,
                projectDirectionId: comment.projectDirectionId,
                responsibilityCenterId: comment.responsibilityCenterId,
            }]);
        }
        setComment(null);
        focusGrid();
    };

    // Уход со страницы: дописать открытый ввод в очередь, доотправить её и предупредить.
    useEffect(() => {
        const onBeforeUnload = (event: BeforeUnloadEvent): void => {
            const current = editingRef.current;
            if (current !== null) {
                setEditing(null);
                commitTextRef.current(current.position, current.text);
            }
            if (!grid.hasPending()) {
                return;
            }
            grid.flushNow(true);
            event.preventDefault();
            event.returnValue = '';
        };
        window.addEventListener('beforeunload', onBeforeUnload);

        return () => window.removeEventListener('beforeunload', onBeforeUnload);
    }, [grid.hasPending, grid.flushNow]);

    const onGridKeyDown = (event: KeyboardEvent<HTMLDivElement>): void => {
        if (editing !== null || event.target !== event.currentTarget) {
            return;
        }
        const position = active ?? { row: 0, col: 0 };
        const arrows: Record<string, [number, number]> = { ArrowUp: [-1, 0], ArrowDown: [1, 0], ArrowLeft: [0, -1], ArrowRight: [0, 1] };
        const arrow = arrows[event.key];
        if (arrow !== undefined) {
            event.preventDefault();
            setActive(active === null ? position : move(position, arrow[0], arrow[1]));

            return;
        }
        if (event.key === 'Tab') {
            const next = move(position, 0, event.shiftKey ? -1 : 1);
            if (next.col !== position.col) {
                event.preventDefault();
                setActive(next);
            }

            return;
        }
        if (event.key === 'Enter' && event.shiftKey) {
            event.preventDefault();
            openComment(position);

            return;
        }
        if (event.key === 'Enter' || event.key === 'F2') {
            event.preventDefault();
            startEdit(position);

            return;
        }
        if (event.key === 'Delete' || event.key === 'Backspace') {
            event.preventDefault();
            commitText(position, '0');

            return;
        }
        if (event.key.length === 1 && /[\d\-−,.]/.test(event.key) && !event.ctrlKey && !event.metaKey && !event.altKey) {
            event.preventDefault();
            startEdit(position, event.key);
        }
    };

    const onEditKeyDown = (event: KeyboardEvent<HTMLInputElement>): void => {
        const current = editingRef.current;
        if (event.key === 'Enter' && event.shiftKey && current !== null) {
            event.preventDefault();
            const position = current.position;
            finishEdit(0, 0);
            openComment(position);
        } else if (event.key === 'Enter') {
            event.preventDefault();
            finishEdit(1, 0);
        } else if (event.key === 'Tab') {
            event.preventDefault();
            finishEdit(0, event.shiftKey ? -1 : 1);
        } else if (event.key === 'Escape') {
            event.preventDefault();
            setEditing(null);
            focusGrid();
        }
    };

    const onPaste = (event: ClipboardEvent<HTMLDivElement>): void => {
        if (editing !== null || active === null || !canEdit) {
            return;
        }
        const text = event.clipboardData.getData('text/plain');
        if (text === '') {
            return;
        }
        event.preventDefault();
        const lines = text.replace(/\r/g, '').split('\n');
        if (lines[lines.length - 1] === '') {
            lines.pop();
        }
        const cells: PendingCell[] = [];
        let skipped = 0;
        lines.forEach((line, lineIndex) => {
            line.split('\t').forEach((value, valueIndex) => {
                const position = { row: active.row + lineIndex, col: active.col + valueIndex };
                const row = rowAt(position);
                const month = months[position.col];
                const parsed = parseAmountInput(value);
                if (row === undefined || month === undefined || !cellEditable(position) || parsed === null) {
                    skipped += 1;

                    return;
                }
                const cell = pendingFor(row, month, inputToStored(parsed, row.flow), row.comments[month] ?? null);
                if (cell !== null) {
                    cells.push(cell);
                }
            });
        });
        grid.setCells(cells);
        setPasteMessage(`Вставлено ${cells.length} ${plural(cells.length, 'ячейка', 'ячейки', 'ячеек')}, пропущено ${skipped}.`);
    };

    const onCellMouseDown = (position: CellPosition): void => {
        const current = editingRef.current;
        if (current !== null && current.position.row === position.row && current.position.col === position.col) {
            return;
        }
        setActive(position);
    };

    const onCellContextMenu = (position: CellPosition, event: MouseEvent): void => {
        if (!cellEditable(position)) {
            return;
        }
        event.preventDefault();
        openComment(position);
    };

    const onToggle = (rowId: string): void => {
        setCollapsed((current) => {
            const next = new Set(current);
            if (next.has(rowId)) {
                next.delete(rowId);
            } else {
                next.add(rowId);
            }
            writeCollapsed(next);

            return next;
        });
        setActive(null);
        onEditBlur();
    };

    const onSliceChange = (next: BudgetSlice): void => {
        onEditBlur();
        setSlice(next);
        setActive(null);
        setComment(null);
        setInvalid(new Map());
        const url = new URL(window.location.href);
        url.searchParams.delete('all');
        for (const [key, value] of Object.entries(sliceParams(next))) {
            url.searchParams.set(key, value);
        }
        window.history.replaceState(null, '', url.toString());
    };

    // «Выгрузить в Excel» живёт в Twig-меню «⋯»: адрес — выбранная пара, иначе вся компания.
    useEffect(() => {
        const link = document.querySelector<HTMLAnchorElement>('[data-budget-export-link]');
        if (link === null) {
            return;
        }
        link.href = pair === null
            ? props.exportUrl
            : `${props.exportUrl}?${new URLSearchParams({ project: pair.projectDirectionId, center: pair.responsibilityCenterId }).toString()}`;
    }, [pair, props.exportUrl]);

    const unsavedForPair = new Set(
        grid.unsaved.filter((cell) => pair !== null && cell.projectDirectionId === pair.projectDirectionId && cell.responsibilityCenterId === pair.responsibilityCenterId)
            .map((cell) => `${cell.rowId}|${cell.month}`),
    );
    const names = new Map((grid.data?.rows ?? []).map((row) => [row.id, row.name]));
    const unsavedLabels = grid.unsaved.map((cell) => {
        const monthIndex = Number(cell.month.slice(5, 7)) - 1;

        return `${names.get(cell.rowId) ?? 'статья'} · ${MONTH_NAMES[monthIndex] ?? cell.month}`;
    });
    const noLeaves = grid.data !== null && !grid.data.rows.some((row) => row.editable);

    // Помощники — только в черновике, с правом записи и выбранной парой (ui-ux.md, экран 4).
    let fillDisabledReason: string | null = null;
    if (!props.editable) {
        fillDisabledReason = 'Вариант не черновик — помощники, загрузка и правила недоступны.';
    } else if (!props.canWrite) {
        fillDisabledReason = 'Нужно право записи в финансах.';
    } else if (pair === null) {
        fillDisabledReason = 'Выберите проект и ЦФО.';
    } else if (grid.lockedMessage !== null || grid.data === null) {
        fillDisabledReason = 'Сетка недоступна для правки.';
    }
    const openPanel = (panel: 'fill' | 'import' | 'rules'): void => {
        setFillOpen(panel === 'fill');
        setImportOpen(panel === 'import');
        setRulesOpen(panel === 'rules');
        setFillMessage(null);
    };
    const toolbar = props.pairs.length <= 1 && fillMessage === null ? null : (
        <span className="d-inline-flex flex-wrap align-items-center gap-3">
            {props.pairs.length > 1 && <BudgetSlicePills pairs={props.pairs} slice={slice} onChange={onSliceChange} />}
            {fillMessage !== null && <span className="text-secondary" role="status" data-budget-fill-message>{fillMessage}</span>}
        </span>
    );
    const panelKey = pair === null ? 'all' : sliceKey(slice);
    const importPanel = importOpen && fillDisabledReason === null && pair !== null && grid.data !== null ? (
        <BudgetImportPanel
            key={panelKey}
            importUrl={props.importUrl}
            csrfToken={props.csrfToken}
            pair={pair}
            pairLabel={props.pairs.find((item) => pairValueOf(item) === pairValueOf(pair))?.label ?? ''}
            rows={grid.data.rows}
            busy={grid.saveState === 'saving' || grid.hasPending()}
            onApplied={(changed) => {
                setImportOpen(false);
                setFillMessage(`Загружено ${changed} ${plural(changed, 'ячейка', 'ячейки', 'ячеек')}.`);
                grid.reloadAfterServerWrite();
            }}
            onClose={() => setImportOpen(false)}
        />
    ) : null;
    const rulesPanel = rulesOpen && fillDisabledReason === null && pair !== null && grid.data !== null ? (
        <BudgetRulePanel
            key={panelKey}
            rulesUrl={props.rulesUrl}
            rulesDeleteUrl={props.rulesDeleteUrl}
            csrfToken={props.csrfToken}
            pair={pair}
            rows={grid.data.rows}
            busy={grid.saveState === 'saving' || grid.hasPending()}
            onChanged={(message) => {
                setFillMessage(message);
                grid.reloadAfterServerWrite();
            }}
            onClose={() => setRulesOpen(false)}
        />
    ) : null;
    const fillPanel = rulesPanel ?? importPanel ?? (fillOpen && fillDisabledReason === null && pair !== null && grid.data !== null ? (
        <BudgetFillPanel
            key={`${panelKey}|${fillHelper}`}
            initialHelper={fillHelper}
            fillUrl={props.fillUrl}
            csrfToken={props.csrfToken}
            pair={pair}
            months={grid.data.months}
            rows={grid.data.rows}
            busy={grid.saveState === 'saving' || grid.hasPending()}
            onApplied={(changed) => {
                setFillOpen(false);
                setFillMessage(`Заполнено ${changed} ${plural(changed, 'ячейка', 'ячейки', 'ячеек')}.`);
                grid.reloadAfterServerWrite();
            }}
            onClose={() => setFillOpen(false)}
        />
    ) : null);

    return (
        <>
            {noLeaves && (
                <div className="alert alert-info m-3">
                    Сначала настройте статьи ОПиУ: в дереве нет статей для ввода.{' '}
                    <a href={props.plCategoriesUrl}>Справочник статей</a>
                </div>
            )}
            <HeadSlot name="save">
                <SaveIndicator state={grid.saveState} error={grid.saveError} locked={grid.lockedMessage !== null} onRetry={grid.retry} />
            </HeadSlot>
            <HeadSlot name="helpers">
                <HelpersMenu
                    disabledReason={fillDisabledReason}
                    onHelper={(helper) => {
                        setFillHelper(helper);
                        openPanel('fill');
                    }}
                    onRule={() => openPanel('rules')}
                    onImport={() => openPanel('import')}
                />
            </HeadSlot>
            <BudgetGridView
                months={months}
                rows={rows}
                canEdit={canEdit}
                readOnlyHint={readOnlyHint}
                active={active}
                editing={editing}
                invalidKeys={new Set(invalid.keys())}
                unsavedKeys={unsavedForPair}
                inputError={[...invalid.values()].at(-1) ?? null}
                pasteMessage={pasteMessage}
                warnings={grid.data?.warnings ?? []}
                lockedMessage={grid.lockedMessage}
                unsavedLabels={unsavedLabels}
                comment={comment}
                toolbar={toolbar}
                fillPanel={fillPanel}
                loading={grid.loading}
                loadError={grid.loadError}
                scrollerRef={scrollerRef}
                inputRef={inputRef}
                onToggle={onToggle}
                onCellMouseDown={onCellMouseDown}
                onCellDoubleClick={(position) => startEdit(position)}
                onCellContextMenu={onCellContextMenu}
                onGridKeyDown={onGridKeyDown}
                onPaste={onPaste}
                onEditChange={(text) => {
                    const current = editingRef.current;
                    if (current !== null) {
                        setEditing({ ...current, text });
                    }
                }}
                onEditKeyDown={onEditKeyDown}
                onEditBlur={onEditBlur}
                onCommentChange={(text) => setComment((current) => (current === null ? null : { ...current, text }))}
                onCommentSave={saveComment}
                onCommentCancel={() => {
                    setComment(null);
                    focusGrid();
                }}
            />
        </>
    );
}

function plural(count: number, one: string, few: string, many: string): string {
    const mod10 = count % 10;
    const mod100 = count % 100;
    if (mod10 === 1 && mod100 !== 11) {
        return one;
    }
    if (mod10 >= 2 && mod10 <= 4 && (mod100 < 12 || mod100 > 14)) {
        return few;
    }

    return many;
}
