import React from 'react';
import MoneyCell from './MoneyCell';

interface DeltaCellProps {
    deltaMinor: number | null | undefined;
    thresholdMinor: number | null | undefined;
    currency?: string | null;
}

const DeltaCell: React.FC<DeltaCellProps> = ({ deltaMinor, thresholdMinor, currency = 'RUB' }) => {
    if (typeof deltaMinor !== 'number' || !Number.isFinite(deltaMinor)) {
        return <span className="text-secondary">—</span>;
    }

    const threshold = typeof thresholdMinor === 'number' && Number.isFinite(thresholdMinor)
        ? thresholdMinor
        : 0;
    const className = Math.abs(deltaMinor) <= threshold ? 'text-success fw-semibold' : 'text-danger fw-semibold';

    return <MoneyCell amountMinor={deltaMinor} currency={currency} className={className} />;
};

export default DeltaCell;
