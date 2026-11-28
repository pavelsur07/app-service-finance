import { Navigate, Outlet } from 'react-router';
import { useSidebarState } from '@vashfindir/ds';
import { ACCOUNT_SIDEBAR_STORAGE_KEY } from './Layout';
import { useSession } from './SessionProvider';
import { SessionStatus } from '../shared/session/SessionStatus';
export function RequireSession() {
  const { state } = useSession();
  const sidebar = useSidebarState(ACCOUNT_SIDEBAR_STORAGE_KEY);
  if (state.status === 'anonymous')
    return (
      <Navigate
        to={`/login?returnTo=%2F${state.expired ? '&reason=expired' : ''}`}
        replace
      />
    );
  if (!state.data)
    return (
      <main className="mx-auto max-w-lg p-4">
        <SessionStatus />
      </main>
    );
  return <Outlet context={sidebar} />;
}
