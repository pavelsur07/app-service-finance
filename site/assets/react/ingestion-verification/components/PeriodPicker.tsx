import React from 'react';
import type { DateRangePeriod, MonthPeriod, MonthRangePeriod } from '../types';
import {
    monthInputValue,
    monthRangeEndValue,
    monthRangeStartValue,
    parseMonthInput,
} from '../utils/date';

type PeriodPickerProps =
    | {
        mode: 'month';
        value: MonthPeriod;
        onChange: (value: MonthPeriod) => void;
        label?: string;
    }
    | {
        mode: 'date-range';
        value: DateRangePeriod;
        onChange: (value: DateRangePeriod) => void;
        labelFrom?: string;
        labelTo?: string;
    }
    | {
        mode: 'month-range';
        value: MonthRangePeriod;
        onChange: (value: MonthRangePeriod) => void;
        labelFrom?: string;
        labelTo?: string;
    };

const PeriodPicker: React.FC<PeriodPickerProps> = (props) => {
    if (props.mode === 'month') {
        const handleChange = (event: React.ChangeEvent<HTMLInputElement>): void => {
            props.onChange(parseMonthInput(event.target.value, props.value));
        };

        return (
            <div>
                <label className="form-label">{props.label ?? 'Период'}</label>
                <input
                    type="month"
                    className="form-control"
                    value={monthInputValue(props.value)}
                    onChange={handleChange}
                />
            </div>
        );
    }

    if (props.mode === 'date-range') {
        const handleFromChange = (event: React.ChangeEvent<HTMLInputElement>): void => {
            props.onChange({ ...props.value, from: event.target.value });
        };
        const handleToChange = (event: React.ChangeEvent<HTMLInputElement>): void => {
            props.onChange({ ...props.value, to: event.target.value });
        };

        return (
            <>
                <div>
                    <label className="form-label">{props.labelFrom ?? 'С'}</label>
                    <input
                        type="date"
                        className="form-control"
                        value={props.value.from}
                        onChange={handleFromChange}
                    />
                </div>
                <div>
                    <label className="form-label">{props.labelTo ?? 'По'}</label>
                    <input
                        type="date"
                        className="form-control"
                        value={props.value.to}
                        onChange={handleToChange}
                    />
                </div>
            </>
        );
    }

    const handleStartChange = (event: React.ChangeEvent<HTMLInputElement>): void => {
        const next = parseMonthInput(event.target.value, {
            year: props.value.yearFrom,
            month: props.value.monthFrom,
        });

        props.onChange({
            ...props.value,
            yearFrom: next.year,
            monthFrom: next.month,
        });
    };
    const handleEndChange = (event: React.ChangeEvent<HTMLInputElement>): void => {
        const next = parseMonthInput(event.target.value, {
            year: props.value.yearTo,
            month: props.value.monthTo,
        });

        props.onChange({
            ...props.value,
            yearTo: next.year,
            monthTo: next.month,
        });
    };

    return (
        <>
            <div>
                <label className="form-label">{props.labelFrom ?? 'С месяца'}</label>
                <input
                    type="month"
                    className="form-control"
                    value={monthRangeStartValue(props.value)}
                    onChange={handleStartChange}
                />
            </div>
            <div>
                <label className="form-label">{props.labelTo ?? 'По месяц'}</label>
                <input
                    type="month"
                    className="form-control"
                    value={monthRangeEndValue(props.value)}
                    onChange={handleEndChange}
                />
            </div>
        </>
    );
};

export default PeriodPicker;
