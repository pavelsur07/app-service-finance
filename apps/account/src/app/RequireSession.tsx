import { Navigate, Outlet } from 'react-router';
import { Button } from '@vashfindir/ui';
import { useSession } from './SessionProvider';
import { SessionStatus } from '../shared/session/SessionStatus';
export function RequireSession() {
  const { store, state } = useSession();
  if (state.status === 'anonymous')
    return <Navigate to="/login?returnTo=%2F" replace />;
  if (!state.data) return <SessionStatus />;
  return (
    <>
      <section className="card" aria-label="Текущая сессия">
        <div className="wz-actions">
          <span>{state.data.user.email}</span>
          <Button variant="secondary" onClick={() => void store.logout()}>
            Выйти
          </Button>
        </div>
        {state.error && (
          <p role="alert" className="form-error">
            {state.error}
          </p>
        )}
      </section>
      <Outlet />
    </>
  );
}
