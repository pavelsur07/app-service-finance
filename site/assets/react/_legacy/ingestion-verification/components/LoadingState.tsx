import React from 'react';

interface LoadingStateProps {
    message?: string;
}

const LoadingState: React.FC<LoadingStateProps> = ({ message = 'Загрузка данных...' }) => (
    <div className="d-flex align-items-center justify-content-center gap-2 py-5 text-secondary">
        <div className="spinner-border spinner-border-sm text-primary" role="status" aria-hidden="true" />
        <span>{message}</span>
    </div>
);

export default LoadingState;
