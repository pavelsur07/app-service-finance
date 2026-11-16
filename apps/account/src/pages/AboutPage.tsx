import { Card, PageHeader } from '@vashfindir/ds';
export function AboutPage() {
  return (
    <>
      <PageHeader title="О приложении" />
      <Card>
        <p>Клиентский кабинет для работы с финансами компании.</p>
        <p>
          Вход доступен существующим пользователям. Рабочие разделы находятся в
          разработке.
        </p>
      </Card>
    </>
  );
}
