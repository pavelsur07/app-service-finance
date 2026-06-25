import React from 'react';

interface ErrorStateProps {
    message: string | null;
    onRetry: () => void;
}

function displayMessage(message: string | null): string {
    if (
        message === null ||
        message.includes('Сеть недоступна') ||
        message.includes('Сервис временно недоступен')
    ) {
        return 'Не удалось загрузить данные, попробуйте позже';
    }

    return message;
}

const ErrorState: React.FC<ErrorStateProps> = ({ message, onRetry }) => (
    <div className="alert alert-danger d-flex align-items-start gap-3" role="alert">
        <i className="ti ti-alert-circle fs-2" aria-hidden="true" />
        <div className="flex-fill">
            <div className="fw-semibold mb-1">Ошибка загрузки</div>
            <div>{displayMessage(message)}</div>
            <button type="button" className="btn btn-sm btn-outline-danger mt-3" onClick={onRetry}>
                <i className="ti ti-refresh me-1" aria-hidden="true" />
                Повторить
            </button>
        </div>
    </div>
);

export default ErrorState;
