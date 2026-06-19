import React from 'react';
import { formatMoney } from '../../shared/format/money';

interface MoneyCellProps {
    amountMinor: number | null | undefined;
    currency?: string | null;
    className?: string;
}

const MoneyCell: React.FC<MoneyCellProps> = ({ amountMinor, currency = 'RUB', className = '' }) => {
    if (typeof amountMinor !== 'number' || !Number.isFinite(amountMinor)) {
        return <span className={`text-secondary ${className}`.trim()}>—</span>;
    }

    return (
        <span className={className}>
            {formatMoney({ amount: amountMinor, currency: currency ?? 'RUB' })}
        </span>
    );
};

export default MoneyCell;
