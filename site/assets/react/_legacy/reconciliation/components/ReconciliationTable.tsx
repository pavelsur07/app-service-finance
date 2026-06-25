import React, { useState } from "react";
import { httpJson } from "../../shared/http/client";
import type {
    ReconciliationSession,
    CategoryBreakdownResponse,
    ServiceGroupBreakdown,
} from "../reconciliation.types";

interface ReconciliationTableProps {
    session: ReconciliationSession;
}

/**
 * Форматирование числа: разделитель тысяч, 2 знака, символ ₽
 */
function formatRub(value: number): string {
    return new Intl.NumberFormat("ru-RU", {
        style: "currency",
        currency: "RUB",
        maximumFractionDigits: 2,
    }).format(value);
}

/**
 * TEMPORARY: Debug-панель для детализации по категориям.
 * Удалить после завершения отладки сверки.
 */
const CategoryBreakdownPanel: React.FC<{ sessionId: string }> = ({ sessionId }) => {
    const [data, setData] = useState<CategoryBreakdownResponse | null>(null);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [expandedGroups, setExpandedGroups] = useState<Set<string>>(new Set());

    const handleLoad = async () => {
        setLoading(true);
        setError(null);
        try {
            const resp = await httpJson<CategoryBreakdownResponse>(
                "/api/marketplace/reconciliation/debug/category-breakdown",
                { method: "POST", body: { sessionId } },
            );
            setData(resp);
        } catch (e: any) {
            setError(e?.message ?? "Ошибка загрузки");
        } finally {
            setLoading(false);
        }
    };

    const toggleGroup = (group: string) => {
        setExpandedGroups((prev) => {
            const next = new Set(prev);
            if (next.has(group)) {
                next.delete(group);
            } else {
                next.add(group);
            }
            return next;
        });
    };

    if (!data) {
        return (
            <div className="mt-3">
                <button
                    className="btn btn-outline-secondary btn-sm"
                    onClick={handleLoad}
                    disabled={loading}
                >
                    {loading ? (
                        <>
                            <span className="spinner-border spinner-border-sm me-1" />
                            Загрузка...
                        </>
                    ) : (
                        <>
                            <i className="ti ti-bug me-1" />
                            Диагностика по категориям
                        </>
                    )}
                </button>
                {error && <div className="text-danger small mt-1">{error}</div>}
            </div>
        );
    }

    return (
        <div className="mt-4">
            <h4 className="mb-3">
                <i className="ti ti-bug me-1" />
                Детализация по категориям (debug)
            </h4>

            {data.unmapped.length > 0 && (
                <div className="alert alert-warning mb-3">
                    <div className="fw-semibold mb-1">
                        Немаппированные категории ({data.unmapped.length})
                    </div>
                    {data.unmapped.map((u) => (
                        <div key={u.category_code} className="small">
                            <code>{u.category_code}</code> — {u.category_name}: {formatRub(u.net_amount)}
                        </div>
                    ))}
                </div>
            )}

            <div className="table-responsive">
                <table className="table table-sm table-vcenter">
                    <thead>
                        <tr>
                            <th>Группа / Категория</th>
                            <th className="text-end">Costs</th>
                            <th className="text-end">Storno</th>
                            <th className="text-end">Нетто</th>
                        </tr>
                    </thead>
                    <tbody>
                        {data.by_service_group.map((g: ServiceGroupBreakdown) => (
                            <React.Fragment key={g.service_group}>
                                <tr
                                    className="table-active"
                                    style={{ cursor: "pointer" }}
                                    onClick={() => toggleGroup(g.service_group)}
                                >
                                    <td className="fw-semibold">
                                        <i
                                            className={`ti ti-chevron-${expandedGroups.has(g.service_group) ? "down" : "right"} me-1`}
                                        />
                                        {g.service_group}
                                        <span className="text-muted ms-1 small">
                                            ({g.categories.length})
                                        </span>
                                    </td>
                                    <td className="text-end text-danger">{formatRub(g.costs_amount)}</td>
                                    <td className="text-end text-success">{formatRub(g.storno_amount)}</td>
                                    <td className="text-end fw-semibold">{formatRub(g.net_amount)}</td>
                                </tr>
                                {expandedGroups.has(g.service_group) &&
                                    g.categories.map((c) => (
                                        <tr key={c.category_code}>
                                            <td className="ps-4">
                                                <code className="small">{c.category_code}</code>
                                                <span className="text-muted ms-1 small">{c.category_name}</span>
                                            </td>
                                            <td className="text-end text-danger small">{formatRub(c.costs_amount)}</td>
                                            <td className="text-end text-success small">{formatRub(c.storno_amount)}</td>
                                            <td className="text-end small">{formatRub(c.net_amount)}</td>
                                        </tr>
                                    ))}
                            </React.Fragment>
                        ))}
                    </tbody>
                </table>
            </div>

            <button
                className="btn btn-ghost-secondary btn-sm mt-2"
                onClick={() => setData(null)}
            >
                Скрыть детализацию
            </button>
        </div>
    );
};

const ReconciliationTable: React.FC<ReconciliationTableProps> = ({ session }) => {
    const [showOnlyMismatch, setShowOnlyMismatch] = useState(false);

    const result = session.result;
    if (!result) return null;

    const groups = showOnlyMismatch
        ? result.group_comparison.filter((g) => g.status === "mismatch")
        : result.group_comparison;

    const isMatched = result.status === "matched";

    return (
        <div className="card">
            {/* Header */}
            <div className="card-header">
                <div className="d-flex align-items-center justify-content-between w-100">
                    <div>
                        <h3 className="card-title mb-1">Результат сверки</h3>
                        <div className="text-muted small">
                            {session.periodFrom} &mdash; {session.periodTo}
                            <span className="mx-2">&middot;</span>
                            {session.originalFilename}
                            <span className="mx-2">&middot;</span>
                            {result.xlsx_lines_count} строк в xlsx
                        </div>
                    </div>
                    <div>
                        {isMatched ? (
                            <span className="badge bg-green-lt text-green">
                                <i className="ti ti-check me-1" />
                                Совпадает
                            </span>
                        ) : (
                            <span className="badge bg-red-lt text-red">
                                <i className="ti ti-alert-triangle me-1" />
                                Расхождение
                            </span>
                        )}
                    </div>
                </div>
            </div>

            <div className="card-body">
                {/* Summary row */}
                <div className="table-responsive mb-4">
                    <table className="table table-vcenter card-table">
                        <thead>
                            <tr>
                                <th>API нетто</th>
                                <th>xlsx comparable</th>
                                <th>xlsx total</th>
                                <th>Дельта</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>{formatRub(result.api_net_amount)}</td>
                                <td>{formatRub(result.xlsx_comparable)}</td>
                                <td>{formatRub(result.xlsx_total)}</td>
                                <td className={result.delta !== 0 ? "text-red fw-bold" : "text-green"}>
                                    {formatRub(result.delta)}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                {/* Filter toggle */}
                <div className="d-flex align-items-center mb-3">
                    <span className="fw-semibold me-3">Группы услуг</span>
                    <label className="form-check form-switch mb-0">
                        <input
                            className="form-check-input"
                            type="checkbox"
                            checked={showOnlyMismatch}
                            onChange={(e) => setShowOnlyMismatch(e.target.checked)}
                        />
                        <span className="form-check-label">Только расхождения</span>
                    </label>
                </div>

                {/* Groups table */}
                <div className="table-responsive">
                    <table className="table table-vcenter card-table">
                        <thead>
                            <tr>
                                <th>Группа услуг</th>
                                <th className="text-end">Сумма API</th>
                                <th className="text-end">Сумма XLS</th>
                                <th className="text-end">Дельта</th>
                                <th>Статус</th>
                            </tr>
                        </thead>
                        <tbody>
                            {groups.length === 0 && (
                                <tr>
                                    <td colSpan={5} className="text-center text-muted">
                                        {showOnlyMismatch
                                            ? "Расхождений не найдено"
                                            : "Нет данных для отображения"}
                                    </td>
                                </tr>
                            )}
                            {groups.map((g) => (
                                <tr key={g.service_group}>
                                    <td>{g.service_group}</td>
                                    <td className="text-end">{formatRub(g.api_net)}</td>
                                    <td className="text-end">{formatRub(g.xlsx_net)}</td>
                                    <td
                                        className={`text-end ${
                                            g.delta !== 0 ? "text-red fw-bold" : ""
                                        }`}
                                    >
                                        {formatRub(g.delta)}
                                    </td>
                                    <td>
                                        {g.status === "matched" ? (
                                            <span className="badge bg-green-lt text-green">
                                                <i className="ti ti-check me-1" />
                                                Совпадает
                                            </span>
                                        ) : (
                                            <span className="badge bg-red-lt text-red">
                                                <i className="ti ti-alert-triangle me-1" />
                                                Расхождение
                                            </span>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                {/* TEMPORARY: debug category breakdown */}
                <CategoryBreakdownPanel sessionId={session.id} />
            </div>
        </div>
    );
};

export default ReconciliationTable;
