import { useEffect, useState } from 'react';
import {
  Button,
  Card,
  FormField,
  Select,
  Alert,
  Pagination,
} from '@vashfindir/ds';
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
    <Card className="grid gap-3" aria-label="Выбор компании">
      <p>
        Текущая компания: <strong>{company?.name ?? 'Не выбрана'}</strong>
      </p>
      {error ? (
        <>
          <Alert tone="danger">{error}</Alert>
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
          <FormField id="company" label="Компания">
            <Select
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
            </Select>
          </FormField>
          <Pagination
            page={result.meta.page}
            pageSize={result.meta.per_page}
            total={result.meta.total}
            onPageChange={setPage}
          />
        </>
      )}
    </Card>
  );
}
