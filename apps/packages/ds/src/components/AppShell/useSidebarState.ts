import { useCallback, useEffect, useRef, useState } from 'react';

interface SidebarState {
  collapsed: boolean;
  onCollapsedChange: (collapsed: boolean) => void;
}
function readPreference(key: string): boolean {
  try {
    return (
      typeof window !== 'undefined' &&
      window.localStorage.getItem(key) === 'collapsed'
    );
  } catch {
    return false;
  }
}
/** Only a realm-scoped UI preference is persisted; responsive rail does not overwrite it. */
export function useSidebarState(storageKey: string): SidebarState {
  const preferred = useRef(readPreference(storageKey));
  const [collapsed, setCollapsed] = useState(() =>
    typeof window !== 'undefined' &&
    window.matchMedia('(max-width: 1279px)').matches
      ? true
      : preferred.current,
  );
  useEffect(() => {
    const query = window.matchMedia('(max-width: 1279px)');
    const change = () => setCollapsed(query.matches || preferred.current);
    query.addEventListener('change', change);
    return () => query.removeEventListener('change', change);
  }, []);
  const onCollapsedChange = useCallback(
    (next: boolean) => {
      preferred.current = next;
      setCollapsed(next);
      try {
        window.localStorage.setItem(
          storageKey,
          next ? 'collapsed' : 'expanded',
        );
      } catch {
        /* UI remains usable with storage blocked. */
      }
    },
    [storageKey],
  );
  return { collapsed, onCollapsedChange };
}
