import React from 'react';
import { formatMoney } from '../../utils/utils';
import type { CostCategory, CostGroupBreakdown } from '../unitExtended.types';
import { COMPENSATION_CODES } from './widgetsConfig';

interface WidgetDetailPanelProps {
    groups: CostGroupBreakdown[];
}

interface SubgroupTotals {
    costsAmount: number;
    stornoAmount: number;
    netAmount: number;
}

/**
 * Группа, внутри которой категории дополнительно разбиваются
 * на подгруппы (обычные услуги + компенсации).
 */
const OTHER_SERVICES_GROUP = 'Другие услуги и штрафы';
const COMPENSATIONS_SUBGROUP_LABEL = 'Компенсации и декомпенсации';

function sumCategories(categories: CostCategory[]): SubgroupTotals {
    return categories.reduce<SubgroupTotals>(
        (acc, c) => ({
            costsAmount: acc.costsAmount + c.costsAmount,
            stornoAmount: acc.stornoAmount + c.stornoAmount,
            netAmount: acc.netAmount + c.netAmount,
        }),
        { costsAmount: 0, stornoAmount: 0, netAmount: 0 },
    );
}

/**
 * Блок «подзаголовок + строки категорий».
 * Используется как для обычной группы, так и для каждой из подгрупп
 * внутри «Другие услуги и штрафы».
 */
const SubgroupRows: React.FC<{
    label: string;
    categories: CostCategory[];
    totals: SubgroupTotals;
    rowKeyPrefix: string;
}> = ({ label, categories, totals, rowKeyPrefix }) => (
    <>
        <tr>
            <td className="fw-bold">{label}</td>
            <td className="text-end fw-bold">{formatMoney(totals.costsAmount)}</td>
            <td className="text-end fw-bold">{formatMoney(totals.stornoAmount)}</td>
            <td className="text-end fw-bold">{formatMoney(totals.netAmount)}</td>
        </tr>
        {categories.map((cat) => (
            <tr key={`${rowKeyPrefix}::${cat.code}`}>
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
    </>
);

/**
 * Раскрытая детализация затрат:
 * - заголовок группы (fw-bold) с суммами
 * - строки категорий (ps-4, text-secondary) с суммами
 *
 * Для группы «Другие услуги и штрафы» категории дополнительно
 * разделяются на две визуальные подгруппы:
 *  - «Другие услуги и штрафы» — всё, кроме кодов из COMPENSATION_CODES
 *  - «Компенсации и декомпенсации» — только коды из COMPENSATION_CODES
 * Суммы подгрупп в сумме дают netAmount всей группы.
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
                        {groups.map((group) => {
                            if (group.serviceGroup === OTHER_SERVICES_GROUP) {
                                const compensations = group.categories.filter((c) =>
                                    COMPENSATION_CODES.includes(c.code),
                                );
                                const others = group.categories.filter(
                                    (c) => !COMPENSATION_CODES.includes(c.code),
                                );

                                return (
                                    <React.Fragment key={group.serviceGroup}>
                                        {others.length > 0 && (
                                            <SubgroupRows
                                                label={OTHER_SERVICES_GROUP}
                                                categories={others}
                                                totals={sumCategories(others)}
                                                rowKeyPrefix={`${group.serviceGroup}::other`}
                                            />
                                        )}
                                        {compensations.length > 0 && (
                                            <SubgroupRows
                                                label={COMPENSATIONS_SUBGROUP_LABEL}
                                                categories={compensations}
                                                totals={sumCategories(compensations)}
                                                rowKeyPrefix={`${group.serviceGroup}::comp`}
                                            />
                                        )}
                                    </React.Fragment>
                                );
                            }

                            return (
                                <SubgroupRows
                                    key={group.serviceGroup}
                                    label={group.serviceGroup}
                                    categories={group.categories}
                                    totals={{
                                        costsAmount: group.costsAmount,
                                        stornoAmount: group.stornoAmount,
                                        netAmount: group.netAmount,
                                    }}
                                    rowKeyPrefix={group.serviceGroup}
                                />
                            );
                        })}
                    </tbody>
                </table>
            </div>
        </div>
    );
};

export default WidgetDetailPanel;
