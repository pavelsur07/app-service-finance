import { NavigationLink } from '../shared/NavigationLink';
import { BrowserRouter, Route, Routes } from 'react-router';
import { ErrorBoundary, Card } from '@vashfindir/ds';
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
          <header>
            <Card className="grid gap-3">
              <p className="text-xl font-bold text-primary">
                Ваш Финдир · account
              </p>
              <nav className="wz-actions" aria-label="Основная навигация">
                <NavigationLink href="/">Главная</NavigationLink>
                <NavigationLink href="/about">О приложении</NavigationLink>
              </nav>
            </Card>
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
