import React, { useEffect, useState } from "react";
import { TrendIndicator } from "../../shared/ui/TrendIndicator";

type Props = {
    marketplace?: string;
    locale?: string;
};

type KpiData = {
    current: {
        revenue: string;
        currency: string;
    };
    previous: {
        revenue: string;
    };
};

/**
 * Виджет KPI: Выручка с индикатором тренда
 */
export function KpiRevenueWidget(props: Props) {
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);
    const [data, setData] = useState<KpiData | null>(null);

    useEffect(() => {
        const controller = new AbortController();

        // Последние 30 дней
        const to = new Date();
        const from = new Date();
        from.setDate(to.getDate() - 30);

        const url = new URL("/api/marketplace/analytics/kpi", window.location.origin);
        url.searchParams.set("from", from.toISOString().slice(0, 10));
        url.searchParams.set("to", to.toISOString().slice(0, 10));
        url.searchParams.set("marketplace", props.marketplace || "all");

        fetch(url.toString(), {
            signal: controller.signal,
            credentials: "same-origin",
        })
            .then((res) => {
                if (!res.ok) throw new Error("Ошибка загрузки");
                return res.json();
            })
            .then((data) => {
                setData(data);
                setLoading(false);
            })
            .catch((err) => {
                if (err.name !== "AbortError") {
                    setError(err.message);
                    setLoading(false);
                }
            });

        return () => controller.abort();
    }, [props.marketplace]);

    // Расчёт изменения
    const growth = data
        ? {
            absolute: parseFloat(data.current.revenue) - parseFloat(data.previous.revenue),
            percent:
                parseFloat(data.previous.revenue) === 0
                    ? 0
                    : ((parseFloat(data.current.revenue) - parseFloat(data.previous.revenue)) /
                        parseFloat(data.previous.revenue)) *
                    100,
        }
        : null;

    return (
        <div className="col-sm-6 col-lg-4">
            <div className="card">
                <div className="card-body">
                    <div className="subheader">ВЫРУЧКА</div>

                    {loading && (
                        <div className="d-flex align-items-center gap-2 mt-2">
                            <div className="spinner-border spinner-border-sm" />
                            <div className="text-muted small">Загрузка...</div>
                        </div>
                    )}

                    {error && <div className="text-danger small mt-2">{error}</div>}

                    {data && (
                        <>
                            <div className="h1 mb-0">
                                {(parseFloat(data.current.revenue) / 1_000_000).toFixed(1)} млн ₽
                            </div>

                            <div className="mt-2">
                                {growth && (
                                    <>
                                        {/* ✅ ИСПОЛЬЗУЕМ НОВЫЙ КОМПОНЕНТ! */}
                                        <TrendIndicator
                                            value={growth.percent}
                                            absolute={growth.absolute}
                                            mode="higher-is-better"  // Выручка: рост = хорошо
                                            locale={props.locale}
                                        />

                                        <div className="text-muted small mt-1">
                                            Всего:{" "}
                                            {parseFloat(data.current.revenue).toLocaleString(props.locale || "ru-RU", {
                                                maximumFractionDigits: 0,
                                            })}{" "}
                                            ₽
                                        </div>
                                    </>
                                )}
                            </div>
                        </>
                    )}
                </div>
            </div>
        </div>
    );
}
