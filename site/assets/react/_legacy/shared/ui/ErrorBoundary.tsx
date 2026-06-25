import React from "react";

interface Props {
    widgetName?: string;
    children: React.ReactNode;
}

interface State {
    hasError: boolean;
    error?: Error;
}

/**
 * Error Boundary для изоляции падений виджетов
 */
export class ErrorBoundary extends React.Component<Props, State> {
    constructor(props: Props) {
        super(props);
        this.state = { hasError: false };
    }

    static getDerivedStateFromError(error: Error): State {
        return { hasError: true, error };
    }

    componentDidCatch(error: Error, errorInfo: any) {
        console.error(
            `[ErrorBoundary${this.props.widgetName ? ` ${this.props.widgetName}` : ""}]`,
            error,
            errorInfo
        );
    }

    render() {
        if (this.state.hasError) {
            return (
                <div className="alert alert-danger">
                    <div className="d-flex align-items-center justify-content-between">
                        <div>
                            <div className="fw-semibold">
                                Ошибка виджета
                                {this.props.widgetName && ` "${this.props.widgetName}"`}
                            </div>
                            <div className="text-muted">
                                Попробуйте обновить страницу. Если повторяется — сообщите в
                                поддержку.
                            </div>
                        </div>
                        <button
                            className="btn btn-outline-light"
                            onClick={() => window.location.reload()}
                        >
                            Обновить
                        </button>
                    </div>
                </div>
            );
        }

        return this.props.children;
    }
}
