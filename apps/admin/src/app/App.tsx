import { BrowserRouter, Route, Routes } from 'react-router';
import { ErrorBoundary } from '@vashfindir/ds';
import { HomePage } from '../pages/HomePage';
import { AboutPage } from '../pages/AboutPage';
import { NotFoundPage } from '../pages/NotFoundPage';
import { SessionProvider } from './SessionProvider';
import { RequireSession } from './RequireSession';
import { LoginPage } from '../pages/LoginPage';
import { PublicLayout, SessionLayout } from './Layout';

export function App() {
  return (
    <ErrorBoundary>
      <BrowserRouter>
        <Routes>
          <Route element={<SessionProvider />}>
            <Route element={<SessionLayout />}>
              <Route path="/login" element={<LoginPage />} />
              <Route element={<RequireSession />}>
                <Route path="/" element={<HomePage />} />
              </Route>
            </Route>
          </Route>
          <Route element={<PublicLayout />}>
            <Route path="/about" element={<AboutPage />} />
            <Route path="*" element={<NotFoundPage />} />
          </Route>
        </Routes>
      </BrowserRouter>
    </ErrorBoundary>
  );
}
