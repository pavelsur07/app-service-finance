import { EmptyState, PageHeader } from '@vashfindir/ds';

export function HomePage() {
  return (
    <>
      <PageHeader title="Административный интерфейс" />
      <div className="grid gap-4">
        <EmptyState title="Раздел находится в разработке">
          Рабочие разделы появятся на следующих этапах.
        </EmptyState>
      </div>
    </>
  );
}
