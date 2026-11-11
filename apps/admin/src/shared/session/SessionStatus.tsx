import { Button, EmptyState } from '@vashfindir/ui';
import { useSession } from '../../app/SessionProvider';
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
      <p role="alert">{state.error}</p>
      <div className="wz-actions">
        <Button onClick={() => void store.refresh()}>Повторить</Button>
        <Button variant="secondary" onClick={() => void store.logout()}>
          Выйти
        </Button>
      </div>
    </EmptyState>
  );
}
