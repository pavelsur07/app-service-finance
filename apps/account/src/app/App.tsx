import { BrowserRouter, Link, Route, Routes } from 'react-router';
import { ErrorBoundary } from '@vashfindir/ui';
import { HomePage } from '../pages/HomePage';
import { AboutPage } from '../pages/AboutPage';
import { NotFoundPage } from '../pages/NotFoundPage';
import styles from './App.module.css';

export function App() {
  return (
    <ErrorBoundary>
      <BrowserRouter>
        <div className={styles.root}>
          <header className="card">
            <p>Ваш Финдир · account</p>
            <nav className="wz-actions" aria-label="Основная навигация">
              <Link to="/">Главная</Link>
              <Link to="/about">О приложении</Link>
            </nav>
          </header>
          <main id="main">
            <Routes>
              <Route path="/" element={<HomePage />} />
              <Route path="/about" element={<AboutPage />} />
              <Route path="*" element={<NotFoundPage />} />
            </Routes>
          </main>
        </div>
      </BrowserRouter>
    </ErrorBoundary>
  );
}
