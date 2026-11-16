import { Navigate, Outlet } from 'react-router';
import { Button, Card, Alert } from '@vashfindir/ds';
import { useSession } from './SessionProvider';
import { SessionStatus } from '../shared/session/SessionStatus';
export function RequireSession() {
  const { store, state } = useSession();
  if (state.status === 'anonymous')
    return <Navigate to="/login?returnTo=%2F" replace />;
  if (!state.data) return <SessionStatus />;
  return (
    <>
      <Card aria-label="Текущая сессия">
        <div className="wz-actions">
          <span>{state.data.user.email}</span>
          <Button variant="secondary" onClick={() => void store.logout()}>
            Выйти
          </Button>
        </div>
        {state.error && (
          <Alert tone="danger" className="mt-3">
            {state.error}
          </Alert>
        )}
      </Card>
      <Outlet />
    </>
  );
}
