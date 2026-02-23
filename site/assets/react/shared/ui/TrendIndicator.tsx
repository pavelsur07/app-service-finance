import React from "react";

/**
 * Режимы интерпретации тренда
 */
type TrendMode =
    | "higher-is-better"  // Рост = хорошо (выручка, прибыль, продажи)
    | "lower-is-better";  // Падение = хорошо (затраты, возвраты, брак)

/**
 * Размеры компонента
 */
type TrendSize = "sm" | "md" | "lg";

type Props = {
    /**
     * Процент изменения (например: 5.2, -3.1)
     */
    value: number;

    /**
     * Режим интерпретации
     * - "higher-is-better": рост = зеленый, падение = красный
     * - "lower-is-better": рост = красный, падение = зеленый
     */
    mode: TrendMode;

    /**
     * Абсолютное значение (опционально)
     * Например: +450000 для "+450 000 ₽"
     */
    absolute?: number;

    /**
     * Валюта для абсолютного значения (по умолчанию "₽")
     */
    currency?: string;

    /**
     * Локаль для форматирования (по умолчанию "ru-RU")
     */
    locale?: string;

    /**
     * Размер (по умолчанию "md")
     */
    size?: TrendSize;

    /**
     * Показывать иконку стрелки (по умолчанию true)
     */
    showIcon?: boolean;
};

/**
 * Компонент для отображения тренда (роста/падения)
 *
 * Автоматически выбирает цвет в зависимости от режима:
 * - "higher-is-better": рост = зеленый, падение = красный
 * - "lower-is-better": рост = красный, падение = зеленый
 *
 * @example
 * // Выручка (рост = хорошо)
 * <TrendIndicator value={+5.2} mode="higher-is-better" />
 *
 * @example
 * // Затраты (рост = плохо)
 * <TrendIndicator value={+3.1} mode="lower-is-better" />
 *
 * @example
 * // С абсолютным значением
 * <TrendIndicator
 *   value={+15.5}
 *   absolute={+450000}
 *   mode="higher-is-better"
 * />
 */
export function TrendIndicator({
                                   value,
                                   mode,
                                   absolute,
                                   currency = "₽",
                                   locale = "ru-RU",
                                   size = "md",
                                   showIcon = true,
                               }: Props) {
    // Определяем направление
    const isPositive = value >= 0;

    // Определяем "хорошо" это или "плохо"
    const isGood = mode === "higher-is-better" ? isPositive : !isPositive;

    // Цвет (зеленый для хорошего, красный для плохого)
    const colorClass = isGood ? "text-green" : "text-red";

    // Иконка стрелки
    const icon = isPositive ? "↑" : "↓";

    // Знак для процента
    const sign = isPositive ? "+" : "";

    // Размеры
    const sizeClasses = {
        sm: "small",
        md: "",
        lg: "h5 mb-0",
    };

    // Форматирование абсолютного значения
    const formattedAbsolute = absolute !== undefined
        ? absolute.toLocaleString(locale, {
            maximumFractionDigits: 0,
        })
        : null;

    return (
        <span className={`${colorClass} ${sizeClasses[size]}`}>
      {showIcon && <>{icon} </>}
            {sign}{value.toFixed(1)}%
            {formattedAbsolute && (
                <> ({sign}{formattedAbsolute} {currency})</>
            )}
    </span>
    );
}
