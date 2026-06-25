import React from "react";

type Props = {
    title?: string;
    subtitle?: string;

    isLoading: boolean;
    isEmpty: boolean;
    error: string | null;

    onRetry?: () => void;

    headerRight?: React.ReactNode;
    children: React.ReactNode;
};

/**
 * Обёртка для виджетов с 4 обязательными состояниями
 *
 * Обязательные состояния:
 * 1. loading - показываем spinner
 * 2. error - показываем ошибку с кнопкой retry
 * 3. empty - показываем "нет данных"
 * 4. success - показываем children
 *
 * @example
 * <WidgetShell
 *   title="Выручка"
 *   subtitle="За период"
 *   isLoading={loading}
 *   isEmpty={!data}
 *   error={error}
 *   onRetry={retry}
 * >
 *   <YourContent data={data} />
 * </WidgetShell>
 */
export function WidgetShell({
                                title,
                                subtitle,
                                isLoading,
                                isEmpty,
                                error,
                                onRetry,
                                headerRight,
                                children,
                            }: Props) {
    return (
        <div className="card">
            {(title || headerRight) && (
                <div className="card-header">
                    <div className="d-flex align-items-center justify-content-between w-100">
                        <div>
                            {title && <div className="fw-semibold">{title}</div>}
                            {subtitle && <div className="text-muted small">{subtitle}</div>}
                        </div>
                        {headerRight}
                    </div>
                </div>
            )}

            <div className="card-body">
                {/* Состояние 1: Loading */}
                {isLoading && (
                    <div className="d-flex align-items-center gap-2">
                        <div className="spinner-border spinner-border-sm" role="status" />
                        <div className="text-muted">Загрузка…</div>
                    </div>
                )}

                {/* Состояние 2: Error */}
                {!isLoading && error && (
                    <div className="alert alert-danger mb-0">
                        <div className="d-flex align-items-center justify-content-between">
                            <div>{error}</div>
                            {onRetry && (
                                <button className="btn btn-outline-light btn-sm" onClick={onRetry}>
                                    Повторить
                                </button>
                            )}
                        </div>
                    </div>
                )}

                {/* Состояние 3: Empty */}
                {!isLoading && !error && isEmpty && (
                    <div className="empty">
                        <div className="empty-header">—</div>
                        <p className="empty-title">Нет данных</p>
                        <p className="empty-subtitle text-muted">
                            Попробуйте изменить период или фильтры.
                        </p>
                    </div>
                )}

                {/* Состояние 4: Success */}
                {!isLoading && !error && !isEmpty && children}
            </div>
        </div>
    );
}
