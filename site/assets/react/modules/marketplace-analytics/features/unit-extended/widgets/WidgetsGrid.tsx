import React, { useEffect, useId, useRef } from 'react';
import { formatMoney } from '@/react/shared/format/number';
import type { CostGroupBreakdown } from '../unitExtended.types';
import OrderAdsDetailPanel from './OrderAdsDetailPanel';
import WidgetCard from './WidgetCard';
import WidgetDetailPanel from './WidgetDetailPanel';
import { WIDGETS, WIDGET_KEY_TO_GROUPS } from './widgetsConfig';
import type { WidgetsApiResponse } from './widgets.types';

interface WidgetsGridProps {
    summary: WidgetsApiResponse | null;
    marketplace: string;
    isLoading: boolean;
    error: string | null;
    expandedKey: string | null;
    expandedGroups: CostGroupBreakdown[];
    onToggle: (key: string) => void;
}

/**
 * Заглушка карточки во время загрузки (placeholder-glow).
 */
const WidgetCardSkeleton: React.FC = () => (
    <div className="card h-100 placeholder-glow">
        <div className="card-body" aria-hidden="true">
            <div className="placeholder col-6 mb-3" />
            <div className="placeholder placeholder-lg col-8 mb-2" />
            <div className="placeholder col-5 mb-3" />
            <div className="border-top pt-2">
                <div className="placeholder col-7" />
            </div>
        </div>
    </div>
);

/**
 * Сетка из 8 виджетов + детализация под выбранным виджетом.
 *
 * Состояния:
 *  - error                       → alert
 *  - isLoading                   → 8 skeleton-карточек
 *  - !summary && !isLoading      → пусто (initial state до первого fetch)
 *  - summary                     → карточки + опционально WidgetDetailPanel
 */
const WidgetsGrid: React.FC<WidgetsGridProps> = ({
    summary,
    marketplace,
    isLoading,
    error,
    expandedKey,
    expandedGroups,
    onToggle,
}) => {
    const detailsId = useId();
    const detailsHeadingRef = useRef<HTMLHeadingElement>(null);
    const detailsButtonRef = useRef<HTMLButtonElement>(null);

    useEffect(() => {
        detailsHeadingRef.current?.focus();
    }, [expandedKey]);

    if (error) {
        return (
            <div className="alert alert-danger mb-3" role="alert">
                {error}
            </div>
        );
    }

    if (isLoading) {
        return (
            <>
                <span className="visually-hidden" role="status">Загрузка показателей</span>
                <div className="row row-cards mb-3" aria-busy="true">
                    {WIDGETS.map((w) => (
                        <div key={w.key} className="col-sm-6 col-xl-3">
                            <WidgetCardSkeleton />
                        </div>
                    ))}
                </div>
            </>
        );
    }

    if (!summary) {
        return null;
    }

    const { current, previous } = summary;
    const orderAds = current.orderAds;
    const hasDetails = (key: string): boolean => Boolean(key === 'ads' ? orderAds : WIDGET_KEY_TO_GROUPS[key]);
    const expandedWidget = WIDGETS.find((config) => config.key === expandedKey && hasDetails(config.key));
    const expandedOrderAds = expandedWidget?.key === 'ads' ? orderAds : null;
    const ozonAdExplanation = (marketplace === 'ozon' || marketplace === '') && (
        <>
            <p className="text-muted small mt-1 mb-0">
                Рекламная статистика и финансовые начисления{marketplace === 'ozon' ? ' Ozon' : ''} обновляются в разное время.
                {' '}Поэтому суммы за последние дни могут расходиться.
            </p>
            <details className="small mt-1">
                <summary className="text-primary cursor-pointer py-1">Как считается</summary>
                <p className="text-muted mb-0">
                    В расход отчёта входят данные рекламной статистики площадок. Для Ozon это клики и оплата за заказ.
                    {' '}В расход Ozon также входит часть начислений по статье «Маркетинговые акции», которую статистика не покрывает.
                </p>
                <p className="text-muted mt-1 mb-0">
                    Справочная сумма по начислениям Ozon может включать предоплату рекламного баланса.
                </p>
                {orderAds && (
                    <p className="text-muted mt-1 mb-0">
                        Кнопка «Детали» показывает только оплату за заказ — по товарам и без привязки к ним.
                    </p>
                )}
            </details>
        </>
    );

    return (
        <>
            <div className="row row-cards mb-3">
                {WIDGETS.map((config) => {
                    const isExpanded = expandedWidget?.key === config.key;
                    return (
                        <div key={config.key} className="col-sm-6 col-xl-3">
                            <WidgetCard
                                config={config}
                                current={current}
                                previous={previous}
                                isExpanded={isExpanded}
                                detailsId={hasDetails(config.key) ? detailsId : undefined}
                                detailsButtonRef={isExpanded ? detailsButtonRef : undefined}
                                allocationNote={config.key === 'ads' && orderAds && orderAds.unlinked !== 0
                                    ? `Оплата за заказ Ozon без привязки к товарам — ${formatMoney(orderAds.unlinked, 2)}`
                                    : undefined}
                                noteDetails={config.key === 'ads' ? ozonAdExplanation : undefined}
                                onToggle={onToggle}
                            />
                        </div>
                    );
                })}
            </div>

            <section id={detailsId} className="mb-3" aria-labelledby={`${detailsId}-title`} hidden={!expandedWidget}>
                {expandedWidget && (
                    <>
                        <div className="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
                            <h3 id={`${detailsId}-title`} ref={detailsHeadingRef} tabIndex={-1} className="mb-0">
                                Детализация · {expandedWidget.label}
                            </h3>
                            <button
                                type="button"
                                className="btn btn-sm btn-ghost-secondary"
                                onClick={() => {
                                    detailsButtonRef.current?.focus();
                                    onToggle(expandedWidget.key);
                                }}
                            >
                                Свернуть
                            </button>
                        </div>
                        {expandedGroups.length > 0 && <WidgetDetailPanel groups={expandedGroups} />}
                        {expandedOrderAds && (
                            <OrderAdsDetailPanel report={expandedOrderAds} from={summary.period.from} to={summary.period.to} />
                        )}
                        {expandedGroups.length === 0 && !expandedOrderAds && (
                            <div className="empty card py-4">
                                <p className="empty-title">За выбранный период нет данных для детализации</p>
                            </div>
                        )}
                    </>
                )}
            </section>
        </>
    );
};

export default WidgetsGrid;
