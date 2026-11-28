import React from 'react';
import { formatMoney, formatQty } from '@/react/shared/format/number';
import { cn } from '@/react/shared/lib/cn';
import CostsBreakdown from './CostsBreakdown';
import type { AbcClass, ListingTag, UnallocatedCosts, UnitExtendedItem, UnitExtendedTotals } from './unitExtended.types';
import styles from './UnitExtendedTable.module.css';

function marginColor(v: number | null): string {
    if (v === null) return '';
    if (v < 5) return 'text-red';
    if (v <= 15) return 'text-yellow';
    return 'text-green';
}

function roiColor(v: number | null): string {
    return v !== null && v < 0 ? 'text-red' : '';
}

function formatPercent(v: number | null): React.ReactNode {
    if (v === null) return '—';
    return `${v.toFixed(1)}%`;
}

const ABC_BADGE_COLOR: Record<AbcClass, string> = {
    A: 'success',
    B: 'warning',
    C: 'secondary',
    D: 'red',
};

const ABC_TITLE: Record<AbcClass, string> = {
    A: 'Класс A — первые 80%',
    B: 'Класс B — следующие 15%',
    C: 'Класс C — остальные 5% или без вклада',
    D: 'Класс D — убыток',
};

interface AbcCellProps {
    value: AbcClass;
}

function AbcCell({ value }: AbcCellProps): React.ReactElement {
    return (
        <td className="text-center">
            <span className={`badge bg-${ABC_BADGE_COLOR[value]}-lt`} title={ABC_TITLE[value]}>
                {value}
            </span>
        </td>
    );
}

const MAX_VISIBLE_TAGS = 2;
/**
 * Ячейка тегов — 180px. Два коротких имени в неё помещаются, два длинных ужимаются
 * до нечитаемых огрызков («Возвратн…», «З…»), поэтому при длинной паре показываем
 * один целый чип и «+N»: узнать один тег полностью полезнее, чем не узнать два.
 */
const MAX_VISIBLE_TAGS_NAME_LENGTH = 16;

function visibleTagCount(tags: ListingTag[]): number {
    if (tags.length < 2) {
        return tags.length;
    }

    const pairLength = tags[0].name.length + tags[1].name.length;

    return pairLength <= MAX_VISIBLE_TAGS_NAME_LENGTH ? MAX_VISIBLE_TAGS : 1;
}

interface TagCellProps {
    tags: ListingTag[];
    selectedTagIds: string[];
    onToggleTag: (tagId: string) => void;
}

const TagCell: React.FC<TagCellProps> = ({ tags, selectedTagIds, onToggleTag }) => {
    if (tags.length === 0) {
        return <td className={`${styles.tags} text-muted`}>—</td>;
    }

    const visible = tags.slice(0, visibleTagCount(tags));
    const hiddenCount = tags.length - visible.length;

    return (
        <td className={styles.tags} title={tags.map((tag) => tag.name).join(', ')}>
            <div className={styles.tagsList}>
                {visible.map((tag) => {
                    const isActive = selectedTagIds.includes(tag.id);
                    return (
                        <button
                            key={tag.id}
                            type="button"
                            className={cn('tag-chip', styles.tagChip, isActive && styles.active)}
                            aria-pressed={isActive}
                            title={isActive
                                ? `Убрать «${tag.name}» из фильтра`
                                : `Отфильтровать по «${tag.name}»`}
                            onClick={() => onToggleTag(tag.id)}
                        >
                            {tag.name}
                        </button>
                    );
                })}
                {hiddenCount > 0 && (
                    <>
                        <span className="text-muted small" aria-hidden="true">+{hiddenCount}</span>
                        {/* title у td скринридеру недоступен и клавиатурой не берётся —
                            имена скрытых тегов отдаём текстом. */}
                        <span className="visually-hidden">
                            Ещё теги: {tags.slice(visible.length).map((tag) => tag.name).join(', ')}
                        </span>
                    </>
                )}
            </div>
        </td>
    );
};

export type ExpandedCostType = 'other' | 'all';

interface UnitExtendedItemRowProps {
    row: UnitExtendedItem;
    colCount: number;
    expandedType: ExpandedCostType | undefined;
    selectedTagIds: string[];
    onToggleTag: (tagId: string) => void;
    onExpandToggle: (listingId: string, type: ExpandedCostType) => void;
}

export function UnitExtendedItemRow({
    row, colCount, expandedType, selectedTagIds, onToggleTag, onExpandToggle,
}: UnitExtendedItemRowProps): React.ReactElement {
    const isOtherExpanded = expandedType === 'other';
    const isAllExpanded = expandedType === 'all';

    return (
        <>
            <tr>
                <td className={`${styles.frozen} ${styles.frozenSku}`}>
                    <div className="text-muted small text-truncate">{row.sku || '—'}</div>
                </td>
                <td className={`${styles.frozen} ${styles.frozenTitle}`}>
                    <div className="text-truncate">{row.title || '—'}</div>
                </td>
                <td>
                    <div className="text-truncate">{row.sellerArticle || '—'}</div>
                </td>
                <TagCell
                    tags={row.tags}
                    selectedTagIds={selectedTagIds}
                    onToggleTag={onToggleTag}
                />
                <AbcCell value={row.abcRevenue} />
                <AbcCell value={row.abcProfit} />
                <td className="text-end">{formatMoney(row.revenue)}</td>
                <td className="text-end">{row.quantity.toLocaleString('ru-RU')}</td>
                <td className="text-end">{formatMoney(row.averageCheckRub)}</td>
                <td className="text-end text-red">{formatMoney(row.returnsTotal)}</td>
                <td className="text-end">{row.returnsQuantity.toLocaleString('ru-RU')}</td>
                <td className="text-end">{formatMoney(row.costPriceTotal)}</td>
                <td className="text-end">{formatMoney(row.costPriceUnit)}</td>
                <td className="text-end">{formatQty(row.stockQty)}</td>
                <td className="text-end">{formatMoney(row.stockCapitalRub)}</td>
                <td className="text-end">{formatMoney(row.commission)}</td>
                <td className="text-end">{formatMoney(row.commissionAverageRub)}</td>
                <td className="text-end">{formatMoney(row.adSpend)}</td>
                <td className="text-end">{formatMoney(row.cacRub)}</td>
                <td className="text-end">{formatPercent(row.drrPercent)}</td>
                <td className="text-end">{formatMoney(row.logistics)}</td>
                <td className="text-end">
                    <button
                        type="button"
                        className={`btn btn-sm ${isOtherExpanded ? 'btn-primary' : 'btn-ghost-primary'} p-1`}
                        onClick={() => onExpandToggle(row.listingId, 'other')}
                        title="Показать детали прочих затрат"
                        aria-expanded={isOtherExpanded}
                    >
                        {formatMoney(row.otherCosts)}
                        <i className={`ti ti-chevron-${isOtherExpanded ? 'up' : 'down'} ms-1`} />
                    </button>
                </td>
                <td className="text-end">{formatMoney(row.totalCosts)}</td>
                <td className="text-end">
                    {row.profitUnit === null ? '—' : (
                        <span className={row.profitUnit >= 0 ? 'text-green' : 'text-red'}>
                            {formatMoney(row.profitUnit)}
                        </span>
                    )}
                </td>
                <td className="text-end">
                    <span className={row.profit >= 0 ? 'text-green' : 'text-red'}>
                        {formatMoney(row.profit)}
                    </span>
                </td>
                <td className={`text-end ${marginColor(row.marginPercent)}`}>
                    {formatPercent(row.marginPercent)}
                </td>
                <td className={`text-end ${roiColor(row.roiPercent)}`}>
                    {formatPercent(row.roiPercent)}
                </td>
                <td className="text-end">
                    <button
                        type="button"
                        className={`btn btn-sm ${isAllExpanded ? 'btn-primary' : 'btn-ghost-secondary'} p-1`}
                        onClick={() => onExpandToggle(row.listingId, 'all')}
                        title="Показать все затраты"
                        aria-expanded={isAllExpanded}
                    >
                        <i className="ti ti-list-details" aria-hidden="true" />
                    </button>
                </td>
            </tr>
            {isOtherExpanded && (
                <tr>
                    <td colSpan={colCount} className="p-0 bg-light">
                        <CostsBreakdown
                            groups={row.otherCostsBreakdown}
                            title="Прочие затраты (без комиссии и логистики)"
                        />
                    </td>
                </tr>
            )}
            {isAllExpanded && (
                <tr>
                    <td colSpan={colCount} className="p-0 bg-light">
                        <CostsBreakdown
                            groups={row.allCostsBreakdown}
                            title="Все затраты по категориям"
                        />
                    </td>
                </tr>
            )}
        </>
    );
}

interface UnitExtendedFooterProps {
    totals: UnitExtendedTotals;
    unallocated: UnallocatedCosts | null;
    colCount: number;
    isUnallocatedExpanded: boolean;
    onUnallocatedToggle: () => void;
}

export function UnitExtendedFooter({
    totals, unallocated, colCount, isUnallocatedExpanded, onUnallocatedToggle,
}: UnitExtendedFooterProps): React.ReactElement {
    return (
        <tfoot>
            {unallocated && (
                <>
                    <tr>
                        <td className={`${styles.frozen} ${styles.frozenSku}`}></td>
                        <td className={`${styles.frozen} ${styles.frozenTitle}`}>
                            Не распределено по товарам
                            <div className="text-muted small">
                                Общие затраты кабинета без привязки к товару
                            </div>
                        </td>
                        <td></td>
                        <td className={styles.tags}></td>
                        <td></td>
                        <td></td>
                        <td className="text-end">{'—'}</td>
                        <td className="text-end">{'—'}</td>
                        <td className="text-end">{'—'}</td>
                        <td className="text-end">{'—'}</td>
                        <td className="text-end">{'—'}</td>
                        <td className="text-end">{'—'}</td>
                        <td className="text-end">{'—'}</td>
                        <td className="text-end">{'—'}</td>
                        <td className="text-end">{'—'}</td>
                        <td className="text-end">{formatMoney(unallocated.commission)}</td>
                        <td className="text-end">{'—'}</td>
                        <td className="text-end">{formatMoney(unallocated.adSpend, 2)}</td>
                        <td className="text-end">{'—'}</td>
                        <td className="text-end">{'—'}</td>
                        <td className="text-end">{formatMoney(unallocated.logistics)}</td>
                        <td className="text-end">{formatMoney(unallocated.otherCosts)}</td>
                        <td className="text-end">{formatMoney(unallocated.totalCosts)}</td>
                        <td className="text-end">{'—'}</td>
                        <td className="text-end">
                            <span className={unallocated.profit >= 0 ? 'text-green' : 'text-red'}>
                                {formatMoney(unallocated.profit)}
                            </span>
                        </td>
                        <td className="text-end">{'—'}</td>
                        <td className="text-end">{'—'}</td>
                        <td className="text-end">
                            <button
                                type="button"
                                className={`btn btn-sm ${isUnallocatedExpanded ? 'btn-primary' : 'btn-ghost-secondary'} p-1`}
                                onClick={onUnallocatedToggle}
                                title="Показать затраты по категориям"
                                aria-expanded={isUnallocatedExpanded}
                            >
                                <i className="ti ti-list-details" />
                            </button>
                        </td>
                    </tr>
                    {isUnallocatedExpanded && (
                        <tr>
                            <td colSpan={colCount} className="p-0 bg-light">
                                <CostsBreakdown
                                    groups={unallocated.allCostsBreakdown}
                                    title="Затраты без привязки к товару"
                                />
                            </td>
                        </tr>
                    )}
                </>
            )}
            <tr className="fw-bold">
                <td className={`${styles.frozen} ${styles.frozenSku}`}></td>
                <td className={`${styles.frozen} ${styles.frozenTitle}`}>Итого</td>
                <td></td>
                <td className={styles.tags}></td>
                <td></td>
                <td></td>
                <td className="text-end">{formatMoney(totals.revenue)}</td>
                <td className="text-end">{totals.quantity.toLocaleString('ru-RU')}</td>
                <td className="text-end">{formatMoney(totals.averageCheckRub)}</td>
                <td className="text-end text-red">{formatMoney(totals.returnsTotal)}</td>
                <td className="text-end">{totals.returnsQuantity.toLocaleString('ru-RU')}</td>
                <td className="text-end">{formatMoney(totals.costPriceTotal)}</td>
                <td className="text-end">{'—'}</td>
                <td className="text-end">{'—'}</td>
                <td className="text-end">{'—'}</td>
                <td className="text-end">{formatMoney(totals.commission)}</td>
                <td className="text-end">{formatMoney(totals.commissionAverageRub)}</td>
                <td className="text-end">{formatMoney(totals.adSpend)}</td>
                <td className="text-end">{formatMoney(totals.cacRub)}</td>
                <td className="text-end">{formatPercent(totals.drrPercent)}</td>
                <td className="text-end">{formatMoney(totals.logistics)}</td>
                <td className="text-end">{formatMoney(totals.otherCosts)}</td>
                <td className="text-end">{formatMoney(totals.totalCosts)}</td>
                <td className="text-end">
                    {totals.profitUnit === null ? '—' : (
                        <span className={totals.profitUnit >= 0 ? 'text-green' : 'text-red'}>
                            {formatMoney(totals.profitUnit)}
                        </span>
                    )}
                </td>
                <td className="text-end">
                    <span className={totals.profit >= 0 ? 'text-green' : 'text-red'}>
                        {formatMoney(totals.profit)}
                    </span>
                </td>
                <td className={`text-end ${marginColor(totals.marginPercent)}`}>
                    {formatPercent(totals.marginPercent)}
                </td>
                <td className={`text-end ${roiColor(totals.roiPercent)}`}>
                    {formatPercent(totals.roiPercent)}
                </td>
                <td></td>
            </tr>
        </tfoot>
    );
}
