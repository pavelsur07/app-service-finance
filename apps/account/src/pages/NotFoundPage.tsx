import { Link } from 'react-router';

// TODO(ds): dedicated 404 sheet pending; reuse the DS empty state.
export function NotFoundPage() {
  return (
    <section className="empty">
      <h1 className="empty-title">Не найдено</h1>
      <p className="empty-text">Такой страницы нет.</p>
      <Link className="ds-link" to="/">
        На главную
      </Link>
    </section>
  );
}
