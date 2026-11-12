import { useEffect, useState } from 'react';
import { Button } from '@vashfindir/ui';
import { ApiError } from '@vashfindir/api';
import { useSession } from '../../app/SessionProvider';
import type { Companies } from './session';
export function CompanyPicker() {
  const { store, state } = useSession();
  const [page, setPage] = useState(1);
  const [result, setResult] = useState<Companies | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [retry, setRetry] = useState(0);
  useEffect(() => {
    const controller = new AbortController();
    let active = true;
    setResult(null);
    setError(null);
    void store.getCompanies(page, controller.signal).then(
      (data) => {
        if (active) setResult(data);
      },
      (error: unknown) => {
        if (!active) return;
        if (error instanceof ApiError && error.status === 401) {
          void store.refresh();
          return;
        }
        if (error instanceof ApiError && error.status === 422 && page > 1) {
          // Membership can change while the user is on a later page.
          setPage(1);
          return;
        }
        setError(
          error instanceof Error
            ? error.message
            : 'Не удалось загрузить компании.',
        );
      },
    );
    return () => {
      active = false;
      controller.abort();
    };
  }, [store, page, retry, state.data]);
  const company = state.data?.active_company;
  return (
    <section className="card" aria-label="Выбор компании">
      <p>
        Текущая компания: <strong>{company?.name ?? 'Не выбрана'}</strong>
      </p>
      {error ? (
        <>
          <p role="alert">{error}</p>
          <Button onClick={() => setRetry((value) => value + 1)}>
            Повторить загрузку компаний
          </Button>
        </>
      ) : !result ? (
        <p role="status">Загружаем компании…</p>
      ) : result.meta.total === 0 ? (
        <p>
          Нет доступных компаний. Обратитесь к администратору вашей организации.
        </p>
      ) : (
        <>
          <label className="form-label" htmlFor="company">
            Компания
          </label>
          <select
            className="select"
            id="company"
            value=""
            onChange={(event) => {
              if (event.target.value)
                void store.selectCompany(event.target.value);
            }}
            disabled={state.busy}
          >
            <option value="">Выберите компанию</option>
            {result.data.map((item) => (
              <option key={item.id} value={item.id}>
                {item.name}
              </option>
            ))}
          </select>
          <div className="wz-actions">
            <Button
              variant="secondary"
              disabled={page <= 1}
              onClick={() => setPage((value) => value - 1)}
            >
              Предыдущая
            </Button>
            <span>
              Страница {result.meta.page} из {result.meta.pages}
            </span>
            <Button
              variant="secondary"
              disabled={page >= result.meta.pages}
              onClick={() => setPage((value) => value + 1)}
            >
              Следующая
            </Button>
          </div>
        </>
      )}
    </section>
  );
}
