import React from 'react';
import type { CostGroupBreakdown } from '../unitExtended.types';
import WidgetCard from './WidgetCard';
import WidgetDetailPanel from './WidgetDetailPanel';
import { WIDGETS, WIDGET_KEY_TO_GROUPS } from './widgetsConfig';
import type { WidgetsApiResponse } from './widgets.types';

interface WidgetsGridProps {
    summary: WidgetsApiResponse | null;
    isLoading: boolean;
    expandedKey: string | null;
    expandedGroups: CostGroupBreakdown[];
    onToggle: (key: string) => void;
}

/**
 * Заглушка карточки во время загрузки (placeholder-glow).
 */
const WidgetCardSkeleton: React.FC = () => (
    <div className="card h-100 placeholder-glow">
        <div className="card-body">
            <div className="placeholder col-6 mb-3" />
            <div className="placeholder placeholder-lg col-8 mb-2" />
            <div className="placeholder col-5" />
        </div>
    </div>
);

/**
 * Сетка из 8 виджетов + детализация под выбранным виджетом.
 */
const WidgetsGrid: React.FC<WidgetsGridProps> = ({
    summary,
    isLoading,
    expandedKey,
    expandedGroups,
    onToggle,
}) => {
    if (isLoading || !summary) {
        return (
            <div className="row row-cards mb-3">
                {WIDGETS.map((w) => (
                    <div key={w.key} className="col-sm-6 col-lg-3">
                        <WidgetCardSkeleton />
                    </div>
                ))}
            </div>
        );
    }

    const { current, previous } = summary;

    return (
        <>
            <div className="row row-cards mb-3">
                {WIDGETS.map((config) => {
                    const isExpandable = Boolean(WIDGET_KEY_TO_GROUPS[config.key]);
                    const isExpanded = expandedKey === config.key;
                    return (
                        <div key={config.key} className="col-sm-6 col-lg-3">
                            <WidgetCard
                                config={config}
                                current={current}
                                previous={previous}
                                isExpanded={isExpanded}
                                isExpandable={isExpandable}
                                onToggle={onToggle}
                            />
                        </div>
                    );
                })}
            </div>

            {expandedKey && expandedGroups.length > 0 && (
                <div className="mb-3">
                    <WidgetDetailPanel groups={expandedGroups} />
                </div>
            )}
        </>
    );
};

export default WidgetsGrid;
