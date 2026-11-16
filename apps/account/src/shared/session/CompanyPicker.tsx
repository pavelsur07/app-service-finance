import { useEffect, useState } from 'react';
import {
  Alert,
  Avatar,
  Button,
  Card,
  FormField,
  Input,
  Pagination,
  Radio,
} from '@vashfindir/ds';
import { ApiError } from '@vashfindir/api';
import { useSession } from '../../app/SessionProvider';
import type { Companies } from './session';

interface CompanyPickerProps {
  onContinue?: () => void;
}
export function CompanyPicker({ onContinue }: CompanyPickerProps) {
  const { store, state } = useSession();
  const [page, setPage] = useState(1);
  const [result, setResult] = useState<Companies | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [retry, setRetry] = useState(0);
  const [choice, setChoice] = useState('');
  const [query, setQuery] = useState('');
  useEffect(() => {
    const controller = new AbortController();
    let active = true;
    setResult(null);
    setError(null);
    setQuery('');
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
  useEffect(() => {
    if (result && result.meta.total <= 1) onContinue?.();
  }, [result, onContinue]);
  const current = state.data?.active_company?.id;
  const items =
    result?.data.filter((item) =>
      item.name
        .toLocaleLowerCase('ru-RU')
        .includes(query.trim().toLocaleLowerCase('ru-RU')),
    ) ?? [];
  const selected = choice
    ? items.some((item) => item.id === choice)
      ? choice
      : ''
    : items.some((item) => item.id === current)
      ? current
      : '';
  const Heading = onContinue ? 'h1' : 'h2';
  if (result?.meta.total === 1) return null;
  if (result?.meta.total === 0)
    return (
      <Card>
        <p>
          Нет доступных компаний. Обратитесь к администратору вашей организации.
        </p>
      </Card>
    );
  const continueWithCompany = async () => {
    if (!selected || state.busy) return;
    await store.selectCompany(selected);
    const next = store.getSnapshot();
    if (!next.error && next.data?.active_company?.id === selected)
      onContinue?.();
  };
  return (
    <Card
      className="ds-auth-card ds-auth-card-wide"
      aria-label="Выбор компании"
    >
      <Heading className="ds-auth-title">Выберите компанию</Heading>
      <p className="ds-auth-subtitle">
        Вы — сотрудник нескольких компаний. Переключиться можно в любой момент в
        боковом меню.
      </p>
      {onContinue && state.error && (
        <Alert tone="danger" className="ds-auth-alert">
          {state.error}
        </Alert>
      )}
      {error ? (
        <>
          <Alert tone="danger">{error}</Alert>
          <Button onClick={() => setRetry((value) => value + 1)}>
            Повторить загрузку компаний
          </Button>
        </>
      ) : !result ? (
        <p role="status">Загружаем компании…</p>
      ) : (
        <>
          {result.meta.total >= 8 && (
            <div className="ds-auth-company-search">
              <FormField id="company-search" label="Поиск компании">
                <Input
                  type="search"
                  value={query}
                  onChange={(event) => setQuery(event.target.value)}
                  disabled={state.busy}
                />
              </FormField>
              {result.meta.pages > 1 && (
                <p>Поиск по компаниям на этой странице.</p>
              )}
            </div>
          )}
          <fieldset className="ds-auth-companies" disabled={state.busy}>
            <legend className="sr-only">Компания</legend>
            {/* TODO(ds): companies API provides id/name only; INN and role are absent. */}
            {/* TODO(ds): active_company is the API default, not a durable last-used preference across logins. */}
            {items.map((item) => (
              <Radio
                key={item.id}
                name="company"
                value={item.id}
                checked={selected === item.id}
                onChange={() => setChoice(item.id)}
                className={`ds-auth-company ${selected === item.id ? 'ds-auth-company-selected' : ''}`}
                label={
                  <>
                    <Avatar
                      name={item.name}
                      shape="square"
                      aria-hidden="true"
                    />
                    <span>{item.name}</span>
                  </>
                }
              />
            ))}
            {items.length === 0 && <p role="status">Компании не найдены.</p>}
          </fieldset>
          <Pagination
            page={result.meta.page}
            pageSize={result.meta.per_page}
            total={result.meta.total}
            onPageChange={(value) => {
              setChoice('');
              setPage(value);
            }}
          />
          <Button
            className="ds-auth-continue"
            size="lg"
            disabled={!selected}
            loading={state.busy}
            onClick={() => void continueWithCompany()}
          >
            Продолжить
          </Button>
          <p className="ds-auth-logout">
            Не та учётная запись?{' '}
            <Button
              variant="ghost"
              disabled={state.busy}
              onClick={() => void store.logout()}
            >
              Выйти
            </Button>
          </p>
        </>
      )}
    </Card>
  );
}
