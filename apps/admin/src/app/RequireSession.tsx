import { Navigate, Outlet } from 'react-router';
import { Alert } from '@vashfindir/ds';
import { useSession } from './SessionProvider';
import { SessionStatus } from '../shared/session/SessionStatus';
export function RequireSession() {
  const { state } = useSession();
  if (state.status === 'anonymous')
    return (
      <Navigate
        to={`/login?returnTo=%2F${state.expired ? '&reason=expired' : ''}`}
        replace
      />
    );
  if (!state.data) return <SessionStatus />;
  return (
    <>
      {state.error && (
        <Alert tone="danger" className="mb-4">
          {state.error}
        </Alert>
      )}
      <Outlet />
    </>
  );
}
