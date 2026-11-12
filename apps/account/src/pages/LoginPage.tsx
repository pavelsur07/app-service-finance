import { useState, type FormEvent } from 'react';
import { Navigate, useSearchParams } from 'react-router';
import { Button } from '@vashfindir/ui';
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
  return (
    <section className="card">
      <h1 className="wz-title">Вход — клиентский кабинет</h1>
      <form onSubmit={submit} aria-busy={state.busy}>
        <div className="form-field">
          <label className="form-label" htmlFor="email">
            Email
          </label>
          <input
            className="input"
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
        </div>
        <div className="form-field">
          <label className="form-label" htmlFor="password">
            Пароль
          </label>
          <input
            className="input"
            id="password"
            name="password"
            type="password"
            autoComplete="current-password"
            required
            maxLength={4096}
            disabled={state.busy}
          />
        </div>
        {state.error && (
          <p className="form-error" role="alert">
            {state.error}
          </p>
        )}
        <Button type="submit" loading={state.busy}>
          Войти
        </Button>
      </form>
    </section>
  );
}
