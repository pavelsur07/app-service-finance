import { useCallback, useState } from 'react';
import { Navigate, useSearchParams } from 'react-router';
import { AuthLayout, LoginForm } from '@vashfindir/ds';
import { useSession } from '../app/SessionProvider';
import { SessionStatus } from '../shared/session/SessionStatus';
import { CompanyPicker } from '../shared/session/CompanyPicker';
import { safeReturnPath } from '../shared/session/session';
export function LoginPage() {
  const { store, state } = useSession();
  const [params] = useSearchParams();
  const [afterLogin, setAfterLogin] = useState(false);
  const finishChoice = useCallback(() => setAfterLogin(false), []);
  if (state.data && afterLogin)
    return (
      <AuthLayout>
        <CompanyPicker onContinue={finishChoice} />
      </AuthLayout>
    );
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
            setAfterLogin(true);
            await store.login(credentials);
            if (!store.getSnapshot().data) setAfterLogin(false);
            return store.getSnapshot().error;
          }}
        />
      )}
    </AuthLayout>
  );
}
