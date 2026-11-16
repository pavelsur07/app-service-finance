import { Navigate, useSearchParams } from 'react-router';
import { AuthLayout, LoginForm } from '@vashfindir/ds';
import { useSession } from '../app/SessionProvider';
import { SessionStatus } from '../shared/session/SessionStatus';
import { safeReturnPath } from '../shared/session/session';
export function LoginPage() {
  const { store, state } = useSession();
  const [params] = useSearchParams();
  if (state.data)
    return <Navigate to={safeReturnPath(params.get('returnTo'))} replace />;
  return (
    <AuthLayout>
      {state.status !== 'anonymous' ? (
        <SessionStatus />
      ) : (
        <LoginForm
          initialEmail={state.expiredEmail ?? ''}
          busy={state.busy}
          expired={params.get('reason') === 'expired'}
          onLogin={async (credentials) => {
            await store.login(credentials);
            return store.getSnapshot().error;
          }}
        />
      )}
    </AuthLayout>
  );
}
