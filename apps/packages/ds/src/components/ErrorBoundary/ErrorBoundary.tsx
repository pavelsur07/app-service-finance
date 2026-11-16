import { Component, type ErrorInfo, type ReactNode } from 'react';
import { Alert } from '../Alert/Alert';
import { Button } from '../Button/Button';
export interface ErrorBoundaryProps {
  children: ReactNode;
}
interface ErrorBoundaryState {
  failed: boolean;
}
/** TODO(ds): dedicated error-page sheet pending; minimal feedback composition. */
export class ErrorBoundary extends Component<
  ErrorBoundaryProps,
  ErrorBoundaryState
> {
  override state: ErrorBoundaryState = { failed: false };
  static getDerivedStateFromError(): ErrorBoundaryState {
    return { failed: true };
  }
  override componentDidCatch(error: Error, info: ErrorInfo) {
    console.error('UI rendering failed', error, info.componentStack);
  }
  override render() {
    if (this.state.failed)
      return (
        <Alert tone="danger" title="Не удалось открыть страницу">
          <p>Обновите страницу и попробуйте снова.</p>
          <Button
            variant="secondary"
            className="mt-3"
            onClick={() => window.location.reload()}
          >
            Обновить страницу
          </Button>
        </Alert>
      );
    return this.props.children;
  }
}
