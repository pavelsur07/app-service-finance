import React, { useState } from 'react';
import type { MarketplaceOption, RecalculateJobResponse } from '../types/analytics.types';
import { MARKETPLACE_LABELS } from '../types/analytics.types';
import { getDefaultDateRange } from '../utils/utils';

interface RecalcModalProps {
    isOpen: boolean;
    isLoading: boolean;
    error: string | null;
    lastJob: RecalculateJobResponse | null;
    onRecalculate: (marketplace: string, dateFrom: string, dateTo: string) => void;
    onClose: () => void;
}

const MARKETPLACES: MarketplaceOption[] = ['wildberries', 'ozon', 'yandex_market', 'sber_megamarket'];

const RecalcModal: React.FC<RecalcModalProps> = ({
    isOpen,
    isLoading,
    error,
    lastJob,
    onRecalculate,
    onClose,
}) => {
    const defaults = getDefaultDateRange();
    const [marketplace, setMarketplace] = useState<string>(MARKETPLACES[0]);
    const [dateFrom, setDateFrom] = useState(defaults.dateFrom);
    const [dateTo, setDateTo] = useState(defaults.dateTo);

    if (!isOpen) return null;

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        onRecalculate(marketplace, dateFrom, dateTo);
    };

    return (
        <>
            <div className="modal modal-blur fade show d-block" tabIndex={-1} role="dialog">
                <div className="modal-dialog modal-dialog-centered" role="document">
                    <div className="modal-content">
                        <div className="modal-header">
                            <h5 className="modal-title">Пересчёт снимков</h5>
                            <button type="button" className="btn-close" onClick={onClose} />
                        </div>
                        <form onSubmit={handleSubmit}>
                            <div className="modal-body">
                                {error && (
                                    <div className="alert alert-danger">{error}</div>
                                )}
                                {lastJob && (
                                    <div className="alert alert-success">
                                        {lastJob.message} (ID: {lastJob.job_id})
                                    </div>
                                )}
                                <div className="mb-3">
                                    <label className="form-label required">Маркетплейс</label>
                                    <select
                                        className="form-select"
                                        value={marketplace}
                                        onChange={(e) => setMarketplace(e.target.value)}
                                    >
                                        {MARKETPLACES.map((mp) => (
                                            <option key={mp} value={mp}>
                                                {MARKETPLACE_LABELS[mp]}
                                            </option>
                                        ))}
                                    </select>
                                </div>
                                <div className="row">
                                    <div className="col-6">
                                        <div className="mb-3">
                                            <label className="form-label required">Дата с</label>
                                            <input
                                                type="date"
                                                className="form-control"
                                                value={dateFrom}
                                                onChange={(e) => setDateFrom(e.target.value)}
                                                required
                                            />
                                        </div>
                                    </div>
                                    <div className="col-6">
                                        <div className="mb-3">
                                            <label className="form-label required">Дата по</label>
                                            <input
                                                type="date"
                                                className="form-control"
                                                value={dateTo}
                                                onChange={(e) => setDateTo(e.target.value)}
                                                required
                                            />
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <div className="modal-footer">
                                <button
                                    type="button"
                                    className="btn btn-link link-secondary me-auto"
                                    onClick={onClose}
                                >
                                    Закрыть
                                </button>
                                <button
                                    type="submit"
                                    className="btn btn-primary"
                                    disabled={isLoading}
                                >
                                    {isLoading
                                        ? <><span className="spinner-border spinner-border-sm me-2" />Запуск...</>
                                        : 'Запустить пересчёт'
                                    }
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
            <div className="modal-backdrop fade show" onClick={onClose} />
        </>
    );
};

export default RecalcModal;
