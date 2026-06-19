import React from 'react';

interface EmptyStateProps {
    title?: string;
    message?: string;
}

const EmptyState: React.FC<EmptyStateProps> = ({
    title = 'Данных нет',
    message = 'Данных нет за выбранный период',
}) => (
    <div className="empty py-5">
        <div className="empty-icon">
            <i className="ti ti-database-off" aria-hidden="true" />
        </div>
        <p className="empty-title">{title}</p>
        <p className="empty-subtitle text-secondary">{message}</p>
    </div>
);

export default EmptyState;
