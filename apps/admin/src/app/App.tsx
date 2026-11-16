import { BrowserRouter, Link, Route, Routes } from 'react-router';
import { ErrorBoundary } from '@vashfindir/ui';
import { HomePage } from '../pages/HomePage';
import { AboutPage } from '../pages/AboutPage';
import { NotFoundPage } from '../pages/NotFoundPage';
import { SessionProvider } from './SessionProvider';
import { RequireSession } from './RequireSession';
import { LoginPage } from '../pages/LoginPage';
import styles from './App.module.css';

export function App() {
  return (
    <ErrorBoundary>
      <BrowserRouter>
        <div className={styles.root}>
          <header className="card grid gap-3">
            <p className="text-xl font-bold text-primary">Ваш Финдир · admin</p>
            <nav className="wz-actions" aria-label="Основная навигация">
              <Link className="ds-link" to="/">
                Главная
              </Link>
              <Link className="ds-link" to="/about">
                О приложении
              </Link>
            </nav>
          </header>
          <main id="main">
            <Routes>
              <Route element={<SessionProvider />}>
                <Route path="/login" element={<LoginPage />} />
                <Route element={<RequireSession />}>
                  <Route path="/" element={<HomePage />} />
                </Route>
              </Route>
              <Route path="/about" element={<AboutPage />} />
              <Route path="*" element={<NotFoundPage />} />
            </Routes>
          </main>
        </div>
      </BrowserRouter>
    </ErrorBoundary>
  );
}
