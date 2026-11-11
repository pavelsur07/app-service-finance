import { Link } from 'react-router';

export function NotFoundPage() {
  return (
    <section className="empty">
      <h1 className="empty-title">Не найдено</h1>
      <p className="empty-text">Такой страницы нет.</p>
      <Link to="/">На главную</Link>
    </section>
  );
}
