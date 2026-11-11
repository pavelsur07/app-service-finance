import { EmptyState } from '@vashfindir/ui';

export function HomePage() {
  return (
    <>
      <div className="wz-head">
        <h1 className="wz-title">Клиентский кабинет</h1>
      </div>
      <EmptyState title="Раздел находится в разработке">
        Авторизация и подключение данных пока недоступны.
      </EmptyState>
    </>
  );
}
