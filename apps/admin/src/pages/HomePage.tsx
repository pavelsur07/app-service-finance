import { EmptyState } from '@vashfindir/ui';

export function HomePage() {
  return (
    <>
      <div className="wz-head">
        <h1 className="wz-title">Административный интерфейс</h1>
      </div>
      <EmptyState title="Раздел находится в разработке">
        Рабочие разделы появятся на следующих этапах.
      </EmptyState>
    </>
  );
}
