import React, { useMemo } from 'react';
import { formatDate, formatDateTime } from '../../shared/format/date';
import { formatNumber } from '../../shared/format/money';
import type { CoverageCell } from '../types';
import { buildDateRange } from '../utils/date';

interface CoverageHeatmapViewProps {
    cells: CoverageCell[];
    from: string;
    to: string;
}

interface AggregatedCell {
    date: string;
    resourceType: string;
    resourceLabel: string;
    resourceGroup: string;
    rawCount: number;
    txCount: number;
    issueCount: number;
    lastFetchedAt: string | null;
}

function numberValue(value: unknown): number {
    return typeof value === 'number' && Number.isFinite(value) ? value : 0;
}

function cellKey(date: string, resourceType: string): string {
    return `${date}|${resourceType}`;
}

function buildCellTitle(cell: AggregatedCell): string {
    const lastFetched = cell.lastFetchedAt !== null
        ? `\nПоследняя загрузка: ${formatDateTime(cell.lastFetchedAt)}`
        : '';

    return [
        `${formatDate(cell.date)} · ${cell.resourceGroup} · ${cell.resourceLabel}`,
        `Тип: ${cell.resourceType}`,
        `Raw: ${formatNumber(cell.rawCount)}`,
        `Транзакции: ${formatNumber(cell.txCount)}`,
        `Проблемы: ${formatNumber(cell.issueCount)}${lastFetched}`,
    ].join('\n');
}

function statusClass(cell: AggregatedCell | null): string {
    if (cell === null || cell.rawCount === 0) {
        return 'bg-secondary-lt text-secondary';
    }

    if (cell.issueCount > 0) {
        return 'bg-warning-lt text-warning';
    }

    return 'bg-success-lt text-success';
}

const CoverageHeatmapView: React.FC<CoverageHeatmapViewProps> = ({ cells, from, to }) => {
    const dates = useMemo(() => buildDateRange(from, to), [from, to]);

    const { resourceTypes, resourceLabels, cellsByKey, coveredDays, totalIssues } = useMemo(() => {
        const aggregated = new Map<string, AggregatedCell>();
        const resources = new Map<string, { label: string; group: string }>();
        const days = new Set<string>();
        let issues = 0;

        cells.forEach((cell) => {
            const date = typeof cell.date === 'string' ? cell.date : '';
            const resourceType = typeof cell.resource_type === 'string' ? cell.resource_type : '';
            const resourceLabel = typeof cell.resource_label === 'string' ? cell.resource_label : resourceType;
            const resourceGroup = typeof cell.resource_group === 'string' ? cell.resource_group : 'Ingestion';

            if (date === '' || resourceType === '') {
                return;
            }

            const key = cellKey(date, resourceType);
            const existing = aggregated.get(key);
            const rawCount = numberValue(cell.raw_count);
            const txCount = numberValue(cell.tx_count);
            const issueCount = numberValue(cell.issue_count);
            const lastFetchedAt = typeof cell.last_fetched_at === 'string' ? cell.last_fetched_at : null;

            resources.set(resourceType, { label: resourceLabel, group: resourceGroup });
            issues += issueCount;

            if (rawCount > 0) {
                days.add(date);
            }

            if (existing === undefined) {
                aggregated.set(key, {
                    date,
                    resourceType,
                    resourceLabel,
                    resourceGroup,
                    rawCount,
                    txCount,
                    issueCount,
                    lastFetchedAt,
                });
                return;
            }

            aggregated.set(key, {
                ...existing,
                resourceLabel,
                resourceGroup,
                rawCount: existing.rawCount + rawCount,
                txCount: existing.txCount + txCount,
                issueCount: existing.issueCount + issueCount,
                lastFetchedAt: lastFetchedAt ?? existing.lastFetchedAt,
            });
        });

        return {
            resourceTypes: Array.from(resources.keys()).sort(),
            resourceLabels: resources,
            cellsByKey: aggregated,
            coveredDays: days.size,
            totalIssues: issues,
        };
    }, [cells]);

    return (
        <>
            <div className="row row-cards mb-3">
                <div className="col-sm-4">
                    <div className="card">
                        <div className="card-body">
                            <div className="subheader">Дней с загрузками</div>
                            <div className="h2 mb-0">{formatNumber(coveredDays)}</div>
                        </div>
                    </div>
                </div>
                <div className="col-sm-4">
                    <div className="card">
                        <div className="card-body">
                            <div className="subheader">Открытых проблем</div>
                            <div className="h2 mb-0">{formatNumber(totalIssues)}</div>
                        </div>
                    </div>
                </div>
                <div className="col-sm-4">
                    <div className="card">
                        <div className="card-body">
                            <div className="subheader">Типов ресурсов</div>
                            <div className="h2 mb-0">{formatNumber(resourceTypes.length)}</div>
                        </div>
                    </div>
                </div>
            </div>

            <div className="card">
                <div className="table-responsive">
                    <table className="table table-sm table-vcenter card-table">
                        <thead>
                            <tr>
                                <th className="text-nowrap">Тип ресурса</th>
                                {dates.map((date) => (
                                    <th key={date} className="text-center text-nowrap">
                                        {date.slice(8, 10)}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {resourceTypes.map((resourceType) => {
                                const description = resourceLabels.get(resourceType);
                                const label = description === undefined
                                    ? resourceType
                                    : `${description.group} · ${description.label}`;

                                return (
                                    <tr key={resourceType}>
                                        <td className="text-nowrap" title={resourceType}>{label}</td>
                                        {dates.map((date) => {
                                            const cell = cellsByKey.get(cellKey(date, resourceType)) ?? null;
                                            const countLabel = cell === null
                                                ? '—'
                                                : String(cell.issueCount > 0 ? cell.issueCount : cell.rawCount);

                                            return (
                                                <td key={date} className="text-center">
                                                    <span
                                                        className={`avatar avatar-sm rounded-1 ${statusClass(cell)}`}
                                                        title={cell === null ? date : buildCellTitle(cell)}
                                                    >
                                                        {countLabel}
                                                    </span>
                                                </td>
                                            );
                                        })}
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                </div>
            </div>
        </>
    );
};

export default CoverageHeatmapView;
