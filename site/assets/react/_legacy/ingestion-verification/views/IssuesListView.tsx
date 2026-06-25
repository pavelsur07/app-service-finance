import React from 'react';
import { formatDateTime } from '../../shared/format/date';
import { formatNumber } from '../../shared/format/money';
import Pagination from '../../shared/components/Pagination';
import StatusBadge from '../components/StatusBadge';
import type { IssueListItemDto, IssuesMetaDto } from '../types';
import { formatMonthLabel } from '../utils/date';

interface IssuesListViewProps {
    items: IssueListItemDto[];
    meta: IssuesMetaDto;
    year: number;
    month: number;
    onPageChange: (page: number) => void;
}

function issueLabel(kind: string | undefined): string {
    if (kind === undefined || kind === '') {
        return 'Проблема';
    }

    return kind.replaceAll('_', ' ');
}

function issueKey(item: IssueListItemDto): string {
    return item.id ?? JSON.stringify([
        'issue',
        item.kind ?? null,
        item.created_at ?? null,
        item.human_description ?? null,
    ]);
}

const IssuesListView: React.FC<IssuesListViewProps> = ({
    items,
    meta,
    year,
    month,
    onPageChange,
}) => {
    const currentPage = Math.max(1, meta.page ?? 1);
    const totalPages = Math.max(0, meta.total_pages ?? 0);

    return (
        <div className="card">
            <div className="card-header">
                <div>
                    <h3 className="card-title">Открытые проблемы за {formatMonthLabel(year, month)}</h3>
                    <div className="card-subtitle">
                        Всего: {formatNumber(meta.total ?? items.length)}
                    </div>
                </div>
            </div>
            <div className="table-responsive">
                <table className="table table-vcenter card-table">
                    <thead>
                        <tr>
                            <th>Дата</th>
                            <th>Тип</th>
                            <th>Описание</th>
                        </tr>
                    </thead>
                    <tbody>
                        {items.map((item) => (
                            <tr key={issueKey(item)}>
                                <td className="text-nowrap">
                                    {item.created_at ? formatDateTime(item.created_at) : '—'}
                                </td>
                                <td>
                                    <StatusBadge status="warning" label={issueLabel(item.kind)} />
                                </td>
                                <td>{item.human_description ?? 'Описание недоступно'}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
            {totalPages > 1 && (
                <div className="card-footer d-flex justify-content-end">
                    <Pagination
                        currentPage={currentPage}
                        totalPages={totalPages}
                        onPageChange={onPageChange}
                    />
                </div>
            )}
        </div>
    );
};

export default IssuesListView;
