import { Component, type ErrorInfo, type ReactNode } from 'react';
import { Button } from './Button';

interface ErrorBoundaryProps {
  children: ReactNode;
}
interface ErrorBoundaryState {
  failed: boolean;
}

/** Finance kit components/alert.css and button.css; DS-Feedback/Controls. */
export class ErrorBoundary extends Component<
  ErrorBoundaryProps,
  ErrorBoundaryState
> {
  state: ErrorBoundaryState = { failed: false };

  static getDerivedStateFromError(): ErrorBoundaryState {
    return { failed: true };
  }

  componentDidCatch(error: Error, info: ErrorInfo): void {
    console.error('Ошибка приложения', error, info.componentStack);
  }

  render() {
    if (this.state.failed) {
      return (
        <section className="alert alert--danger" role="alert">
          <div className="a-body">
            <h1 className="a-title">Не удалось открыть страницу</h1>
            <p className="a-text">Попробуйте обновить страницу.</p>
            <Button
              variant="secondary"
              onClick={() => window.location.reload()}
            >
              Обновить
            </Button>
          </div>
        </section>
      );
    }
    return this.props.children;
  }
}
