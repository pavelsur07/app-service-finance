import React, { useEffect, useMemo, useRef, useState } from "react";
import type {
    BalanceDynamicsPoint,
    BalanceDynamicsResponse,
    BalanceFlowKey,
} from "./types";

const INITIAL_WIDTH = 1000;
const HEIGHT = 340;
const PLOT = { top: 24, right: 28, bottom: 44, left: 76 } as const;

export const FLOW_SERIES: ReadonlyArray<{
    key: BalanceFlowKey;
    label: string;
}> = [
    { key: "operating", label: "Операционная" },
    { key: "financing", label: "Финансовая" },
    { key: "investing", label: "Инвестиционная" },
];

type ChartPoint = BalanceDynamicsPoint & {
    balanceNumber: number;
    flowNumbers: Record<BalanceFlowKey, number>;
};

interface Props {
    data: BalanceDynamicsResponse;
    visibleFlows: ReadonlySet<BalanceFlowKey>;
}

function decimalToNumber(value: string): number {
    const parsed = Number(value);
    if (!Number.isFinite(parsed)) {
        throw new Error("Balance dynamics API returned a non-numeric amount.");
    }

    return parsed;
}

function formatMoney(value: string, currency: string): string {
    const match = value.match(/^(-?)(\d+)(?:\.(\d+))?$/);
    if (!match) {
        return `${value} ${currency}`;
    }

    const [, sign, integer, fraction = ""] = match;
    const grouped = integer.replace(/\B(?=(\d{3})+(?!\d))/g, "\u00a0");
    const normalizedFraction = !fraction || /^0+$/.test(fraction) ? "" : fraction.padEnd(2, "0");

    return `${sign === "-" ? "−" : ""}${grouped}${normalizedFraction ? `,${normalizedFraction}` : ""}\u00a0${currency}`;
}

function formatAxisValue(value: number, domainSpan: number, domainMagnitude: number): string {
    if (domainSpan >= 10000 && domainSpan / Math.max(domainMagnitude, 1) >= 0.05) {
        return new Intl.NumberFormat("ru-RU", {
            notation: "compact",
            maximumFractionDigits: 2,
        }).format(value);
    }

    const gridStep = domainSpan / 4;
    const maximumFractionDigits = gridStep >= 2.5
        ? 0
        : Math.min(6, Math.max(2, Math.ceil(-Math.log10(gridStep)) + 1));

    return new Intl.NumberFormat("ru-RU", {
        maximumFractionDigits,
    }).format(value);
}

function formatDate(value: string, long = false): string {
    const [year, month, day] = value.split("-").map(Number);
    const date = new Date(year, month - 1, day);

    return new Intl.DateTimeFormat("ru-RU", long
        ? { day: "numeric", month: "long", year: "numeric" }
        : { day: "2-digit", month: "2-digit" }
    ).format(date);
}

function makePath(points: ChartPoint[], value: (point: ChartPoint) => number, x: (index: number) => number, y: (amount: number) => number): string {
    return points.map((point, index) => `${index === 0 ? "M" : "L"}${x(index).toFixed(2)},${y(value(point)).toFixed(2)}`).join(" ");
}

export function BalanceDynamicsChart({ data, visibleFlows }: Props) {
    const plotRef = useRef<HTMLDivElement>(null);
    const [chartWidth, setChartWidth] = useState(INITIAL_WIDTH);
    const [hoveredIndex, setHoveredIndex] = useState<number | null>(null);
    const [keyboardSelection, setKeyboardSelection] = useState(false);
    const points = useMemo<ChartPoint[]>(() => data.points.map((point) => ({
        ...point,
        balanceNumber: decimalToNumber(point.balance),
        flowNumbers: {
            operating: decimalToNumber(point.flows.operating),
            financing: decimalToNumber(point.flows.financing),
            investing: decimalToNumber(point.flows.investing),
        },
    })), [data.points]);

    useEffect(() => {
        const plot = plotRef.current;
        if (!plot || typeof ResizeObserver === "undefined") {
            return;
        }

        const observer = new ResizeObserver(([entry]) => {
            if (entry.contentRect.height === 0) {
                return;
            }
            // HEIGHT matches the desktop CSS plot height; mobile compensates with a larger axis font.
            setChartWidth(Math.max(340, Math.round(HEIGHT * entry.contentRect.width / entry.contentRect.height)));
        });
        observer.observe(plot);

        return () => observer.disconnect();
    }, []);

    const values = points.flatMap((point) => [
        point.balanceNumber,
        ...FLOW_SERIES.filter(({ key }) => visibleFlows.has(key)).map(({ key }) => point.flowNumbers[key]),
    ]);
    if (data.minimum_balance) {
        values.push(decimalToNumber(data.minimum_balance.amount));
    }

    let rawMin = Math.min(...values);
    let rawMax = Math.max(...values);
    if (rawMin === rawMax) {
        const spread = Math.max(Math.abs(rawMin) * 0.1, 1);
        rawMin -= spread;
        rawMax += spread;
    }
    const domainPadding = (rawMax - rawMin) * 0.08;
    const domainMin = rawMin - domainPadding;
    const domainMax = rawMax + domainPadding;
    const domainSpan = domainMax - domainMin;
    const domainMagnitude = Math.max(Math.abs(domainMin), Math.abs(domainMax));
    const plotWidth = chartWidth - PLOT.left - PLOT.right;
    const plotHeight = HEIGHT - PLOT.top - PLOT.bottom;
    const x = (index: number): number => PLOT.left + (points.length === 1 ? plotWidth / 2 : index * plotWidth / (points.length - 1));
    const y = (amount: number): number => PLOT.top + (domainMax - amount) * plotHeight / (domainMax - domainMin);
    const balancePath = makePath(points, (point) => point.balanceNumber, x, y);
    const chartBottom = HEIGHT - PLOT.bottom;
    const areaPath = `${balancePath} L${x(points.length - 1).toFixed(2)},${chartBottom} L${x(0).toFixed(2)},${chartBottom} Z`;
    const gridValues = Array.from({ length: 5 }, (_, index) => domainMax - index * (domainMax - domainMin) / 4);
    const xLabelIndexes = Array.from(new Set([0, 1, 2, 3, 4].map((step) => Math.round(step * (points.length - 1) / 4))));
    const hoveredPoint = null === hoveredIndex ? null : points[hoveredIndex];
    const keyboardAnnouncement = keyboardSelection && hoveredPoint
        ? [
            `${formatDate(hoveredPoint.date, true)}. Остаток ${formatMoney(hoveredPoint.balance, data.currency)}`,
            ...FLOW_SERIES.filter(({ key }) => visibleFlows.has(key)).map(({ key, label }) => (
                `${label}: ${formatMoney(hoveredPoint.flows[key], data.currency)}`
            )),
        ].join(". ") + "."
        : "";

    const selectFromPointer = (event: React.PointerEvent<SVGSVGElement>) => {
        setKeyboardSelection(false);
        const matrix = event.currentTarget.getScreenCTM();
        if (!matrix) {
            return;
        }
        const pointer = event.currentTarget.createSVGPoint();
        pointer.x = event.clientX;
        pointer.y = event.clientY;
        const viewBoxX = pointer.matrixTransform(matrix.inverse()).x;
        const ratio = Math.max(0, Math.min(1, (viewBoxX - PLOT.left) / plotWidth));
        setHoveredIndex(Math.round(ratio * (points.length - 1)));
    };

    const moveWithKeyboard = (event: React.KeyboardEvent<SVGSVGElement>) => {
        if (event.key !== "ArrowLeft" && event.key !== "ArrowRight") {
            return;
        }
        event.preventDefault();
        setKeyboardSelection(true);
        const direction = event.key === "ArrowLeft" ? -1 : 1;
        setHoveredIndex((current) => Math.max(0, Math.min(points.length - 1, (current ?? points.length - 1) + direction)));
    };

    return (
        <div className="vf-financial-chart__plot" ref={plotRef}>
            <svg
                className="vf-financial-chart__svg"
                viewBox={`0 0 ${chartWidth} ${HEIGHT}`}
                role="img"
                tabIndex={0}
                aria-label={`Динамика остатка за ${data.period.days} дней. Для просмотра значений используйте стрелки влево и вправо.`}
                onFocus={() => {
                    setKeyboardSelection(true);
                    setHoveredIndex((current) => current ?? points.length - 1);
                }}
                onBlur={() => {
                    setKeyboardSelection(false);
                    setHoveredIndex(null);
                }}
                onKeyDown={moveWithKeyboard}
                onPointerMove={selectFromPointer}
                onPointerLeave={() => setHoveredIndex(null)}
            >
                <title>Динамика остатка на счетах</title>
                <defs>
                    <linearGradient id="balance-dynamics-area" x1="0" y1="0" x2="0" y2="1">
                        <stop className="vf-financial-chart__gradient-start" offset="0%" />
                        <stop className="vf-financial-chart__gradient-end" offset="100%" />
                    </linearGradient>
                </defs>

                {gridValues.map((value) => (
                    <g key={value}>
                        <line className="vf-financial-chart__grid" x1={PLOT.left} x2={chartWidth - PLOT.right} y1={y(value)} y2={y(value)} />
                        <text className="vf-financial-chart__axis-label" x={PLOT.left - 12} y={y(value) + 4} textAnchor="end">
                            {formatAxisValue(value, domainSpan, domainMagnitude)}
                        </text>
                    </g>
                ))}

                {xLabelIndexes.map((index) => (
                    <text key={points[index].date} className="vf-financial-chart__axis-label" x={x(index)} y={HEIGHT - 16} textAnchor="middle">
                        {formatDate(points[index].date)}
                    </text>
                ))}

                <path d={areaPath} fill="url(#balance-dynamics-area)" />

                {data.minimum_balance && (
                    <line
                        className="vf-financial-chart__minimum"
                        x1={PLOT.left}
                        x2={chartWidth - PLOT.right}
                        y1={y(decimalToNumber(data.minimum_balance.amount))}
                        y2={y(decimalToNumber(data.minimum_balance.amount))}
                    />
                )}

                {FLOW_SERIES.filter(({ key }) => visibleFlows.has(key)).map(({ key }) => (
                    <path
                        key={key}
                        className={`vf-financial-chart__line vf-financial-chart__line--flow vf-financial-chart__line--${key}`}
                        d={makePath(points, (point) => point.flowNumbers[key], x, y)}
                    />
                ))}

                <path className="vf-financial-chart__line vf-financial-chart__line--balance" d={balancePath} />

                {points.map((point, index) => point.below_minimum && (
                    <circle
                        key={point.date}
                        className="vf-financial-chart__breach-point"
                        cx={x(index)}
                        cy={y(point.balanceNumber)}
                        r="4"
                    />
                ))}

                {hoveredPoint && hoveredIndex !== null && (
                    <g aria-hidden="true">
                        <line className="vf-financial-chart__cursor" x1={x(hoveredIndex)} x2={x(hoveredIndex)} y1={PLOT.top} y2={chartBottom} />
                        <circle className="vf-financial-chart__hover-point" cx={x(hoveredIndex)} cy={y(hoveredPoint.balanceNumber)} r="5" />
                    </g>
                )}
            </svg>

            {hoveredPoint && hoveredIndex !== null && (
                <div className="vf-financial-chart__tooltip">
                    <div className="vf-financial-chart__tooltip-date">{formatDate(hoveredPoint.date, true)}</div>
                    <div className="vf-financial-chart__tooltip-row">
                        <span>Остаток</span>
                        <strong>{formatMoney(hoveredPoint.balance, data.currency)}</strong>
                    </div>
                    {FLOW_SERIES.filter(({ key }) => visibleFlows.has(key)).map(({ key, label }) => (
                        <div className="vf-financial-chart__tooltip-row" key={key}>
                            <span>{label}</span>
                            <strong>{formatMoney(hoveredPoint.flows[key], data.currency)}</strong>
                        </div>
                    ))}
                </div>
            )}
            <div className="visually-hidden" aria-live="polite">
                {keyboardAnnouncement}
            </div>
        </div>
    );
}
