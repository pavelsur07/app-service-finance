import React from "react";
import type { ReconciliationHistoryItem } from "../reconciliation.types";

interface ReconciliationHistoryProps {
    items: ReconciliationHistoryItem[];
    total: number;
    page: number;
    onPageChange: (page: number) => void;
    onViewSession: (sessionId: string) => void;
    isLoading: boolean;
}

const STATUS_BADGE: Record<string, { cls: string; label: string }> = {
    completed: { cls: "bg-green-lt text-green", label: "Завершена" },
    failed: { cls: "bg-red-lt text-red", label: "Ошибка" },
    pending: { cls: "bg-muted-lt text-muted", label: "В процессе" },
};

function formatDate(iso: string): string {
    try {
        return new Intl.DateTimeFormat("ru-RU", {
            day: "2-digit",
            month: "2-digit",
            year: "numeric",
            hour: "2-digit",
            minute: "2-digit",
        }).format(new Date(iso));
    } catch {
        return iso;
    }
}

const ReconciliationHistory: React.FC<ReconciliationHistoryProps> = ({
    items,
    total,
    page,
    onPageChange,
    onViewSession,
    isLoading,
}) => {
    const limit = 20;
    const totalPages = Math.max(1, Math.ceil(total / limit));

    if (isLoading) {
        return (
            <div className="card">
                <div className="card-body">
                    <div className="d-flex align-items-center gap-2">
                        <div className="spinner-border spinner-border-sm" role="status" />
                        <div className="text-muted">Загрузка...</div>
                    </div>
                </div>
            </div>
        );
    }

    if (items.length === 0) {
        return (
            <div className="card">
                <div className="card-body">
                    <div className="empty">
                        <p className="empty-title">Нет истории сверок</p>
                        <p className="empty-subtitle text-muted">
                            Загрузите первый отчёт на вкладке &laquo;Новая сверка&raquo;
                        </p>
                    </div>
                </div>
            </div>
        );
    }

    return (
        <div className="card">
            <div className="card-header">
                <h3 className="card-title">История сверок</h3>
                <div className="card-options">
                    <span className="text-muted small">Всего: {total}</span>
                </div>
            </div>
            <div className="table-responsive">
                <table className="table table-vcenter card-table">
                    <thead>
                        <tr>
                            <th>Дата</th>
                            <th>Период</th>
                            <th>Файл</th>
                            <th>Статус</th>
                            <th>Действие</th>
                        </tr>
                    </thead>
                    <tbody>
                        {items.map((item) => {
                            const badge = STATUS_BADGE[item.status] ?? STATUS_BADGE.pending;
                            return (
                                <tr key={item.id}>
                                    <td className="text-nowrap">{formatDate(item.createdAt)}</td>
                                    <td className="text-nowrap">
                                        {item.periodFrom} &mdash; {item.periodTo}
                                    </td>
                                    <td>{item.originalFilename}</td>
                                    <td>
                                        <span className={`badge ${badge.cls}`}>{badge.label}</span>
                                    </td>
                                    <td>
                                        {item.status === "completed" && (
                                            <button
                                                className="btn btn-sm btn-ghost-primary"
                                                onClick={() => onViewSession(item.id)}
                                            >
                                                <i className="ti ti-eye me-1" />
                                                Посмотреть
                                            </button>
                                        )}
                                    </td>
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
            </div>

            {/* Pagination */}
            {totalPages > 1 && (
                <div className="card-footer d-flex align-items-center">
                    <p className="m-0 text-muted">
                        Страница {page} из {totalPages}
                    </p>
                    <ul className="pagination m-0 ms-auto">
                        <li className={`page-item ${page <= 1 ? "disabled" : ""}`}>
                            <button
                                className="page-link"
                                onClick={() => onPageChange(page - 1)}
                                disabled={page <= 1}
                            >
                                <i className="ti ti-chevron-left" />
                            </button>
                        </li>
                        {Array.from({ length: totalPages }, (_, i) => i + 1)
                            .filter(
                                (p) => p === 1 || p === totalPages || Math.abs(p - page) <= 1,
                            )
                            .reduce<(number | "ellipsis")[]>((acc, p, idx, arr) => {
                                if (idx > 0) {
                                    const prev = arr[idx - 1];
                                    if (prev !== undefined && p - prev > 1) acc.push("ellipsis");
                                }
                                acc.push(p);
                                return acc;
                            }, [])
                            .map((item, idx) =>
                                item === "ellipsis" ? (
                                    <li key={`e-${idx}`} className="page-item disabled">
                                        <span className="page-link">&hellip;</span>
                                    </li>
                                ) : (
                                    <li
                                        key={item}
                                        className={`page-item ${item === page ? "active" : ""}`}
                                    >
                                        <button
                                            className="page-link"
                                            onClick={() => onPageChange(item)}
                                        >
                                            {item}
                                        </button>
                                    </li>
                                ),
                            )}
                        <li className={`page-item ${page >= totalPages ? "disabled" : ""}`}>
                            <button
                                className="page-link"
                                onClick={() => onPageChange(page + 1)}
                                disabled={page >= totalPages}
                            >
                                <i className="ti ti-chevron-right" />
                            </button>
                        </li>
                    </ul>
                </div>
            )}
        </div>
    );
};

export default ReconciliationHistory;
