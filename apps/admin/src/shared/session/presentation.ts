import { createSessionStore } from './session';

/** UI provenance only: the underlying store still owns all session/API decisions. */
export function createSessionPresentation() {
  const store = createSessionStore();
  let hadAuthenticatedSession = false;
  let expired = false;
  let lastEmail: string | null = null;
  let snapshot = {
    ...store.getSnapshot(),
    expired,
    expiredEmail: lastEmail as string | null,
  };
  const listeners = new Set<() => void>();
  // Subscribe before React: a redirect must see the provenance in the same snapshot.
  store.subscribe(() => {
    const state = store.getSnapshot();
    if (state.data) {
      lastEmail = state.data.user.email;
      hadAuthenticatedSession = true;
      expired = false;
    }
    if (
      state.status === 'anonymous' &&
      !state.busy &&
      hadAuthenticatedSession &&
      state.error
    )
      expired = true;
    snapshot = { ...state, expired, expiredEmail: expired ? lastEmail : null };
    listeners.forEach((listener) => listener());
  });
  const reset = () => {
    lastEmail = null;
    hadAuthenticatedSession = false;
    expired = false;
  };
  return {
    ...store,
    getSnapshot: () => snapshot,
    subscribe: (listener: () => void) => {
      listeners.add(listener);
      return () => {
        listeners.delete(listener);
      };
    },
    login: (credentials: Parameters<typeof store.login>[0]) => {
      reset();
      return store.login(credentials);
    },
    logout: () => {
      reset();
      return store.logout();
    },
    cancel: () => {
      reset();
      store.cancel();
    },
  };
}
