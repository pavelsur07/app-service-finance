import { Card, PageHeader } from '@vashfindir/ds';
export function AboutPage() {
  return (
    <>
      <PageHeader title="О приложении" />
      <Card>
        <p>Административный интерфейс для управления сервисом.</p>
        <p>
          Вход доступен существующим пользователям. Рабочие разделы находятся в
          разработке.
        </p>
      </Card>
    </>
  );
}
