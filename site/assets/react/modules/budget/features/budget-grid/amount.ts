import type { BudgetFlow, BudgetValueFormat } from './budget-grid.types';

const NBSP = ' ';
const MINUS = '−';

/**
 * Ввод пользователя → десятичная строка `-600000.50` или null, если это не число.
 * Принимаются пробелы (в т. ч. неразрывные) между разрядами, запятая или точка,
 * минус ASCII или U+2212. Не больше 16 цифр до запятой и 2 после (BudgetAmount).
 */
export function parseAmountInput(input: string): string | null {
    const compact = input.replace(/[\s  ]/g, '').replace(MINUS, '-').replace(',', '.');
    if (compact === '') {
        return '0.00';
    }
    const match = /^(-?)(\d{1,16})(?:\.(\d{1,2}))?$/.exec(compact);
    if (!match) {
        return null;
    }
    const [, sign, whole, fraction = ''] = match;
    const normalized = `${whole.replace(/^0+(?=\d)/, '')}.${fraction.padEnd(2, '0')}`;

    return isZero(normalized) ? '0.00' : `${sign}${normalized}`;
}

/**
 * Знак при вводе (решение Владельца 04.11): в расходной статье вводится
 * положительное число, хранится с минусом; доход и статья без направления — как ввёл.
 */
export function inputToStored(value: string, flow: BudgetFlow): string {
    return flow === 'expense' ? negate(value) : value;
}

/** Сохранённая сумма → текст в поле редактирования (обратное к inputToStored). */
export function storedToInput(value: string, flow: BudgetFlow): string {
    const shown = flow === 'expense' ? negate(value) : value;
    if (isZero(shown)) {
        return '';
    }

    return shown.replace(/\.00$/, '').replace('.', ',');
}

export function isZero(value: string): boolean {
    return /^-?0*(\.0*)?$/.test(value);
}

export function negate(value: string): string {
    if (isZero(value)) {
        return '0.00';
    }

    return value.startsWith('-') ? value.slice(1) : `-${value}`;
}

/** Сумма десятичных строк с двумя знаками без потери точности (через копейки BigInt). */
export function addAmounts(values: string[]): string {
    let total = 0n;
    for (const value of values) {
        const [whole, fraction = ''] = value.replace('-', '').split('.');
        const minor = BigInt(whole || '0') * 100n + BigInt((fraction + '00').slice(0, 2));
        total += value.startsWith('-') ? -minor : minor;
    }
    const negative = total < 0n;
    const abs = negative ? -total : total;
    const result = `${abs / 100n}.${(abs % 100n).toString().padStart(2, '0')}`;

    return negative ? `-${result}` : result;
}

export interface FormattedValue {
    whole: string;
    minor: string | null;
    zero: boolean;
}

const DIGITS: Record<BudgetValueFormat, number> = { MONEY: 0, QTY: 2, RATIO: 4, PERCENT: 1 };

/**
 * Значение по листу DS-Money: разряды — U+00A0, запятая, минус — U+2212.
 * Деньги в сетке — без копеек (ui-ux.md §0), показатели — по формату статьи
 * (как `finance/report/preview.html.twig`).
 */
export function formatValue(value: string, format: BudgetValueFormat): FormattedValue {
    const digits = DIGITS[format];
    const number = Number(value);
    const rounded = Number.isFinite(number) ? number.toFixed(digits) : '0';
    const zero = Number(rounded) === 0;
    const negative = rounded.startsWith('-') && !zero;
    const [whole, fraction] = rounded.replace('-', '').split('.');
    const grouped = whole.replace(/\B(?=(\d{3})+(?!\d))/g, NBSP);

    return {
        whole: `${negative ? MINUS : ''}${grouped}`,
        minor: fraction === undefined ? null : `,${fraction}${format === 'PERCENT' ? `${NBSP}%` : ''}`,
        zero,
    };
}
