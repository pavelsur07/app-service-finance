import { ApiError } from '@vashfindir/api';
import { createSessionApi, type Credentials } from '@vashfindir/api/session';
export interface Session {
  user: { id: string; email: string };
  access: { admin: true };
}

export interface SessionState {
  status: 'loading' | 'anonymous' | 'authenticated' | 'forbidden' | 'error';
  data: Session | null;
  error: string | null;
  busy: boolean;
}
export function createSessionStore(base = '/api/admin/v1') {
  const api = createSessionApi<Session>(base);
  let state: SessionState = {
    status: 'loading',
    data: null,
    error: null,
    busy: false,
  };
  const listeners = new Set<() => void>();
  let generation = 0;
  let controller: AbortController | undefined;
  const publish = (next: SessionState) => {
    state = next;
    listeners.forEach((listener) => listener());
  };
  const cancel = () => {
    generation++;
    controller?.abort();
  };
  const run = async (
    operation: (signal: AbortSignal) => Promise<Session | void>,
    kind: 'refresh' | 'login' | 'logout' | 'select',
  ) => {
    if (state.busy && kind !== 'logout') return;
    cancel();
    const current = generation;
    controller = new AbortController();
    const signal = controller.signal;
    publish({
      status:
        kind === 'refresh' && state.data
          ? 'authenticated'
          : kind === 'login'
            ? 'anonymous'
            : 'loading',
      data: kind === 'refresh' ? state.data : null,
      error: null,
      busy: true,
    });
    try {
      const data = await operation(signal);
      if (current !== generation) return;
      publish({
        status: data ? 'authenticated' : 'anonymous',
        data: data ?? null,
        error: null,
        busy: false,
      });
    } catch (error) {
      if (current !== generation || signal.aborted) return;
      const status = error instanceof ApiError ? error.status : 0;
      const message =
        kind === 'login' && status === 401
          ? 'Неверный email или пароль.'
          : status === 429
            ? 'Слишком много попыток. Повторите вход через 15 минут.'
            : error instanceof Error
              ? error.message
              : 'Не удалось выполнить запрос.';
      // A lost POST response can mean the server already committed the change.
      if (kind !== 'refresh' && (status === 0 || status >= 500)) {
        try {
          const data = await api.getSession(signal);
          if (current === generation)
            publish({
              status: 'authenticated',
              data,
              error: message,
              busy: false,
            });
          return;
        } catch (restorationError) {
          if (current !== generation) return;
          if (
            restorationError instanceof ApiError &&
            (restorationError.status === 401 || restorationError.status === 403)
          ) {
            publish({
              status:
                restorationError.status === 401 ? 'anonymous' : 'forbidden',
              data: null,
              error:
                kind === 'logout' && restorationError.status === 401
                  ? null
                  : kind === 'login'
                    ? message
                    : restorationError.message,
              busy: false,
            });
            return;
          }
          // Network/5xx still leaves the mutation unresolved; offer a fresh read.
        }
      }
      if (current !== generation) return;
      publish({
        status:
          status === 401
            ? 'anonymous'
            : status === 403
              ? 'forbidden'
              : kind === 'login'
                ? 'anonymous'
                : 'error',
        data: null,
        error: message,
        busy: false,
      });
    }
  };
  return {
    getSnapshot: () => state,
    subscribe: (listener: () => void) => {
      listeners.add(listener);
      return () => {
        listeners.delete(listener);
      };
    },
    cancel: () => {
      cancel();
      publish({ status: 'loading', data: null, error: null, busy: false });
    },
    refresh: () => run(api.getSession, 'refresh'),
    login: (credentials: Credentials) =>
      run((signal) => api.login(credentials, signal), 'login'),
    logout: () => run(api.logout, 'logout'),
  };
}
// Only currently implemented internal destinations are accepted.
export function safeReturnPath(value: string | null): string {
  return value === '/' || value === '/about' ? value : '/';
}
