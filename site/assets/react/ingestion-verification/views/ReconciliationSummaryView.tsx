import React from 'react';
import { formatDateTime } from '../../shared/format/date';
import { formatNumber } from '../../shared/format/money';
import DeltaCell from '../components/DeltaCell';
import MoneyCell from '../components/MoneyCell';
import StatusBadge from '../components/StatusBadge';
import type { ReconciliationByTypeDto, ReconciliationSummaryDto } from '../types';
import { formatMonthLabel } from '../utils/date';

interface ReconciliationSummaryViewProps {
    summary: ReconciliationSummaryDto;
    by_type: ReconciliationByTypeDto[];
    year: number;
    month: number;
}

function reconciliationStatus(summary: ReconciliationSummaryDto): React.ReactNode {
    const delta = summary.canon_vs_ozon_delta_minor;
    const threshold = summary.threshold_minor ?? 0;

    if (delta === null || delta === undefined) {
        return <StatusBadge status="pending" label="Нет контрольной суммы" />;
    }

    if (Math.abs(delta) <= threshold) {
        return <StatusBadge status="success" label="Сходится" />;
    }

    return <StatusBadge status="error" label="Есть расхождение" />;
}

const ReconciliationSummaryView: React.FC<ReconciliationSummaryViewProps> = ({
    summary,
    by_type,
    year,
    month,
}) => {
    const currency = summary.currency ?? 'RUB';

    return (
        <>
            {summary.ozon_control_total_minor === null && (
                <div className="alert alert-warning">
                    Контрольная сумма Ozon недоступна для этого периода.
                </div>
            )}

            <div className="row row-cards mb-3">
                <div className="col-md-3">
                    <div className="card">
                        <div className="card-body">
                            <div className="subheader">Статус</div>
                            <div className="mt-2">{reconciliationStatus(summary)}</div>
                        </div>
                    </div>
                </div>
                <div className="col-md-3">
                    <div className="card">
                        <div className="card-body">
                            <div className="subheader">Канон</div>
                            <div className="h3 mb-0">
                                <MoneyCell amountMinor={summary.canon_total_minor} currency={currency} />
                            </div>
                        </div>
                    </div>
                </div>
                <div className="col-md-3">
                    <div className="card">
                        <div className="card-body">
                            <div className="subheader">Контроль Ozon</div>
                            <div className="h3 mb-0">
                                <MoneyCell amountMinor={summary.ozon_control_total_minor} currency={currency} />
                            </div>
                        </div>
                    </div>
                </div>
                <div className="col-md-3">
                    <div className="card">
                        <div className="card-body">
                            <div className="subheader">Разница</div>
                            <div className="h3 mb-0">
                                <DeltaCell
                                    deltaMinor={summary.canon_vs_ozon_delta_minor}
                                    thresholdMinor={summary.threshold_minor}
                                    currency={currency}
                                />
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div className="card">
                <div className="card-header">
                    <div>
                        <h3 className="card-title">Операции за {formatMonthLabel(year, month)}</h3>
                        <div className="card-subtitle">
                            Пересчитано: {summary.recomputed_at ? formatDateTime(summary.recomputed_at) : '—'}
                        </div>
                    </div>
                </div>
                <div className="table-responsive">
                    <table className="table table-vcenter card-table">
                        <thead>
                            <tr>
                                <th>Тип операции</th>
                                <th className="text-end">Сумма</th>
                                <th className="text-end">Транзакций</th>
                            </tr>
                        </thead>
                        <tbody>
                            {by_type.map((item) => (
                                <tr key={item.type ?? item.type_label ?? 'unknown'}>
                                    <td>{item.type_label ?? item.type ?? '—'}</td>
                                    <td className="text-end">
                                        <MoneyCell amountMinor={item.canon_amount_minor} currency={currency} />
                                    </td>
                                    <td className="text-end">{formatNumber(item.tx_count ?? 0)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            </div>
        </>
    );
};

export default ReconciliationSummaryView;
