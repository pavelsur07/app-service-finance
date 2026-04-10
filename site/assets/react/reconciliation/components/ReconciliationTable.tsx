import React, { useState } from "react";
import type { ReconciliationSession } from "../reconciliation.types";

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
            </div>
        </div>
    );
};

export default ReconciliationTable;
