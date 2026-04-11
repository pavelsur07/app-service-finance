import React, { useState } from 'react';
import type { CostGroupBreakdown } from './unitExtended.types';
import { formatMoney } from '../utils/utils';

interface CostsBreakdownProps {
    groups: CostGroupBreakdown[];
    title: string;
}

const CostsBreakdown: React.FC<CostsBreakdownProps> = ({ groups, title }) => {
    const [expandedGroups, setExpandedGroups] = useState<Set<string>>(new Set());

    if (groups.length === 0) {
        return <div className="text-muted small p-2">Нет данных</div>;
    }

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

    return (
        <div className="p-2">
            <div className="fw-bold small mb-2">{title}</div>
            <table className="table table-sm table-vcenter mb-0">
                <thead>
                    <tr>
                        <th>Группа / Категория</th>
                        <th className="text-end">Начислено</th>
                        <th className="text-end">Сторно</th>
                        <th className="text-end">Нетто</th>
                    </tr>
                </thead>
                <tbody>
                    {groups.map((group) => {
                        const isExpanded = expandedGroups.has(group.serviceGroup);
                        return (
                            <React.Fragment key={group.serviceGroup}>
                                <tr
                                    style={{ cursor: 'pointer' }}
                                    onClick={() => toggleGroup(group.serviceGroup)}
                                    className="fw-semibold"
                                >
                                    <td>
                                        <i className={`ti ti-chevron-${isExpanded ? 'down' : 'right'} me-1`} />
                                        {group.serviceGroup}
                                    </td>
                                    <td className="text-end">{formatMoney(group.costsAmount)}</td>
                                    <td className="text-end">{formatMoney(group.stornoAmount)}</td>
                                    <td className="text-end">{formatMoney(group.netAmount)}</td>
                                </tr>
                                {isExpanded && group.categories.map((cat) => (
                                    <tr key={cat.code} className="text-muted">
                                        <td className="ps-4">{cat.name || cat.code}</td>
                                        <td className="text-end">{formatMoney(cat.costsAmount)}</td>
                                        <td className="text-end">{formatMoney(cat.stornoAmount)}</td>
                                        <td className="text-end">{formatMoney(cat.netAmount)}</td>
                                    </tr>
                                ))}
                            </React.Fragment>
                        );
                    })}
                </tbody>
            </table>
        </div>
    );
};

export default CostsBreakdown;
