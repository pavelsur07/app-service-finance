import { CompanyPicker } from '../shared/session/CompanyPicker';
import { EmptyState, PageHeader } from '@vashfindir/ds';

export function HomePage() {
  return (
    <>
      <PageHeader title="Клиентский кабинет" />
      <div className="grid gap-4">
        <CompanyPicker />
        <EmptyState title="Раздел находится в разработке">
          Рабочие разделы появятся на следующих этапах.
        </EmptyState>
      </div>
    </>
  );
}
