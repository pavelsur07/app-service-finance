import {
  createContext,
  useContext,
  useEffect,
  useState,
  useSyncExternalStore,
} from 'react';
import { Outlet } from 'react-router';
import { createSessionStore } from '../shared/session/session';
const SessionContext = createContext<ReturnType<
  typeof createSessionStore
> | null>(null);
export function SessionProvider() {
  const [store] = useState(() => createSessionStore());
  useEffect(() => {
    void store.refresh();
    const focus = () => {
      if (store.getSnapshot().status === 'authenticated') void store.refresh();
    };
    window.addEventListener('focus', focus);
    return () => {
      window.removeEventListener('focus', focus);
      store.cancel();
    };
  }, [store]);
  return (
    <SessionContext.Provider value={store}>
      <Outlet />
    </SessionContext.Provider>
  );
}
export function useSession() {
  const store = useContext(SessionContext);
  if (!store) throw new Error('SessionProvider is required');
  return {
    store,
    state: useSyncExternalStore(store.subscribe, store.getSnapshot),
  };
}
