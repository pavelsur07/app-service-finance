import React from 'react';
import { formatMoney } from '../../utils/utils';
import type { CostGroupBreakdown } from '../unitExtended.types';

interface WidgetDetailPanelProps {
    groups: CostGroupBreakdown[];
}

/**
 * Раскрытая детализация затрат:
 * - заголовок группы (fw-bold) с суммами
 * - строки категорий (ps-4, text-secondary) с суммами
 */
const WidgetDetailPanel: React.FC<WidgetDetailPanelProps> = ({ groups }) => {
    if (groups.length === 0) {
        return null;
    }

    return (
        <div className="card">
            <div className="table-responsive">
                <table className="table table-vcenter card-table">
                    <thead>
                        <tr>
                            <th>КАТЕГОРИЯ</th>
                            <th className="text-end">НАЧИСЛЕНО</th>
                            <th className="text-end">СТОРНО</th>
                            <th className="text-end">НЕТТО</th>
                        </tr>
                    </thead>
                    <tbody>
                        {groups.map((group) => (
                            <React.Fragment key={group.serviceGroup}>
                                <tr>
                                    <td className="fw-bold">{group.serviceGroup}</td>
                                    <td className="text-end fw-bold">
                                        {formatMoney(group.costsAmount)}
                                    </td>
                                    <td className="text-end fw-bold">
                                        {formatMoney(group.stornoAmount)}
                                    </td>
                                    <td className="text-end fw-bold">
                                        {formatMoney(group.netAmount)}
                                    </td>
                                </tr>
                                {group.categories.map((cat) => (
                                    <tr key={`${group.serviceGroup}::${cat.code}`}>
                                        <td className="ps-4 text-secondary">{cat.name}</td>
                                        <td className="text-end text-secondary">
                                            {formatMoney(cat.costsAmount)}
                                        </td>
                                        <td className="text-end text-secondary">
                                            {formatMoney(cat.stornoAmount)}
                                        </td>
                                        <td className="text-end text-secondary">
                                            {formatMoney(cat.netAmount)}
                                        </td>
                                    </tr>
                                ))}
                            </React.Fragment>
                        ))}
                    </tbody>
                </table>
            </div>
        </div>
    );
};

export default WidgetDetailPanel;
