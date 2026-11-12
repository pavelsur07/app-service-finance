import { CompanyPicker } from '../shared/session/CompanyPicker';
import { EmptyState } from '@vashfindir/ui';

export function HomePage() {
  return (
    <>
      <div className="wz-head">
        <h1 className="wz-title">Клиентский кабинет</h1>
      </div>
      <CompanyPicker />
      <EmptyState title="Раздел находится в разработке">
        Рабочие разделы появятся на следующих этапах.
      </EmptyState>
    </>
  );
}
