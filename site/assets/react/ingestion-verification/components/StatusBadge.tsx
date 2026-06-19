import React from 'react';

type StatusBadgeStatus = 'success' | 'warning' | 'error' | 'pending' | 'neutral';

interface StatusBadgeProps {
    status: StatusBadgeStatus;
    label: string;
}

const CLASS_BY_STATUS: Record<StatusBadgeStatus, string> = {
    success: 'bg-success-lt text-success',
    warning: 'bg-warning-lt text-warning',
    error: 'bg-danger-lt text-danger',
    pending: 'bg-blue-lt text-blue',
    neutral: 'bg-secondary-lt text-secondary',
};

const StatusBadge: React.FC<StatusBadgeProps> = ({ status, label }) => (
    <span className={`badge ${CLASS_BY_STATUS[status]}`}>{label}</span>
);

export default StatusBadge;
