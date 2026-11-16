import { useState, type FormEvent } from 'react';
import { Navigate, useSearchParams } from 'react-router';
import { Button, Card, FormField, Input, Alert } from '@vashfindir/ds';
import { useSession } from '../app/SessionProvider';
import { SessionStatus } from '../shared/session/SessionStatus';
import { safeReturnPath } from '../shared/session/session';
export function LoginPage() {
  const { store, state } = useSession();
  const [params] = useSearchParams();
  const [email, setEmail] = useState('');
  if (state.data)
    return <Navigate to={safeReturnPath(params.get('returnTo'))} replace />;
  if (state.status !== 'anonymous') return <SessionStatus />;
  const submit = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    const form = event.currentTarget;
    const data = new FormData(form);
    const password = String(data.get('password') ?? '');
    form.reset();
    void store.login({ email, password });
  };
  // TODO(ds): v3 auth screen sheet pending; compose existing card/controls.
  return (
    <Card className="mx-auto w-full max-w-(--ds-modal-width-compact)">
      <h1 className="wz-title">Вход — администрирование</h1>
      <form onSubmit={submit} aria-busy={state.busy}>
        <FormField id="email" label="Email" required>
          <Input
            id="email"
            name="email"
            type="email"
            autoComplete="username"
            required
            maxLength={180}
            value={email}
            onChange={(event) => setEmail(event.target.value)}
            disabled={state.busy}
          />
        </FormField>
        <FormField id="password" label="Пароль" required>
          <Input
            id="password"
            name="password"
            type="password"
            autoComplete="current-password"
            required
            maxLength={4096}
            disabled={state.busy}
          />
        </FormField>
        {state.error && (
          <Alert tone="danger" className="mt-3">
            {state.error}
          </Alert>
        )}
        <Button className="mt-4" type="submit" loading={state.busy}>
          Войти
        </Button>
      </form>
    </Card>
  );
}
