import React from 'react';
import type { SnapshotItem } from '../types/analytics.types';
import { formatMoney, formatDate } from '../utils/utils';

interface SnapshotsTableProps {
    snapshots: SnapshotItem[];
    isLoading: boolean;
    error: string | null;
    page: number;
    pages: number;
    total: number;
    onPageChange: (page: number) => void;
}

const SnapshotsTable: React.FC<SnapshotsTableProps> = ({
    snapshots,
    isLoading,
    error,
    page,
    pages,
    total,
    onPageChange,
}) => {
    if (error) {
        return (
            <div className="alert alert-danger">{error}</div>
        );
    }

    if (isLoading) {
        return (
            <div className="d-flex justify-content-center py-4">
                <div className="spinner-border text-primary" role="status">
                    <span className="visually-hidden">Загрузка...</span>
                </div>
            </div>
        );
    }

    if (snapshots.length === 0) {
        return (
            <div className="empty">
                <p className="empty-title">Нет данных за выбранный период</p>
                <p className="empty-subtitle text-muted">
                    Запустите пересчёт или выберите другой период
                </p>
            </div>
        );
    }

    return (
        <div className="card">
            <div className="card-header">
                <h3 className="card-title">Снимки по дням</h3>
                <div className="card-options">
                    <span className="text-muted">Всего: {total.toLocaleString('ru-RU')}</span>
                </div>
            </div>
            <div className="table-responsive">
                <table className="table table-vcenter card-table">
                    <thead>
                        <tr>
                            <th>Дата</th>
                            <th>Товар</th>
                            <th>SKU</th>
                            <th className="text-end">Выручка</th>
                            <th className="text-end">Возвраты</th>
                            <th className="text-end">Продажи</th>
                            <th className="text-end">Заказы</th>
                            <th className="text-end">Ср. цена</th>
                        </tr>
                    </thead>
                    <tbody>
                        {snapshots.map((s) => (
                            <tr key={s.id}>
                                <td>{formatDate(s.snapshot_date)}</td>
                                <td>{s.listing_name || '—'}</td>
                                <td className="text-muted">{s.listing_sku}</td>
                                <td className="text-end">{formatMoney(s.revenue)}</td>
                                <td className="text-end">{formatMoney(s.refunds)}</td>
                                <td className="text-end">{s.sales_quantity}</td>
                                <td className="text-end">{s.orders_quantity}</td>
                                <td className="text-end">{formatMoney(s.avg_sale_price, 2)}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
            {pages > 1 && (
                <div className="card-footer d-flex align-items-center">
                    <p className="m-0 text-muted">
                        Страница {page} из {pages}
                    </p>
                    <ul className="pagination m-0 ms-auto">
                        <li className={`page-item ${page <= 1 ? 'disabled' : ''}`}>
                            <button
                                className="page-link"
                                onClick={() => onPageChange(page - 1)}
                                disabled={page <= 1}
                            >
                                Назад
                            </button>
                        </li>
                        <li className={`page-item ${page >= pages ? 'disabled' : ''}`}>
                            <button
                                className="page-link"
                                onClick={() => onPageChange(page + 1)}
                                disabled={page >= pages}
                            >
                                Вперёд
                            </button>
                        </li>
                    </ul>
                </div>
            )}
        </div>
    );
};

export default SnapshotsTable;
