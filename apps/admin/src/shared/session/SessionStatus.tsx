import { Button, EmptyState, Alert } from '@vashfindir/ds';
import { useSession } from '../../app/SessionProvider';
// TODO(ds): loading/error page sheets pending; compose existing DS patterns.
export function SessionStatus() {
  const { store, state } = useSession();
  if (state.status === 'loading') return <p role="status">Проверяем сессию…</p>;
  return (
    <EmptyState
      title={
        state.status === 'forbidden'
          ? 'Доступ запрещён'
          : 'Не удалось загрузить сессию'
      }
    >
      <Alert tone="danger">{state.error}</Alert>
      <div className="wz-actions">
        <Button onClick={() => void store.refresh()}>Повторить</Button>
        <Button variant="secondary" onClick={() => void store.logout()}>
          Выйти
        </Button>
      </div>
    </EmptyState>
  );
}
