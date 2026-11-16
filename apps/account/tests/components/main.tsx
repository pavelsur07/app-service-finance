import { LayoutFixture } from './LayoutFixture';
import { StrictMode, useRef, useState } from 'react';
import { createRoot } from 'react-dom/client';
import { IconPlus } from '@vashfindir/ds';
import {
  Alert,
  Badge,
  Button,
  Card,
  Checkbox,
  EmptyState,
  ErrorBoundary,
  FormField,
  Input,
  InputGroup,
  Link,
  Radio,
  Select,
  StatusPill,
  Textarea,
  Toast,
  ToastStack,
  Toggle,
} from '@vashfindir/ds';
import './styles.css';

function BoundaryExample() {
  const [failed, setFailed] = useState(false);
  if (failed) throw new Error('Synthetic component fixture failure');
  return <Button onClick={() => setFailed(true)}>Сломать пример</Button>;
}
function Fixture() {
  const [text, setText] = useState('Заполнено');
  const [submitted, setSubmitted] = useState(0);
  const [busy, setBusy] = useState(false);
  const [showTones, setShowTones] = useState(false);
  const [toast, setToast] = useState(false);
  const [showTimer, setShowTimer] = useState(false);
  const [closed, setClosed] = useState(0);
  const inputRef = useRef<HTMLInputElement>(null);
  return (
    <main className="mx-auto grid max-w-5xl gap-4 p-6">
      <h1 className="text-4xl font-bold">Базовые компоненты ДС v3.4</h1>
      <Card title="Действия" actions={<Badge variant="api">API</Badge>}>
        <form
          className="flex flex-wrap gap-2"
          onSubmit={(event) => {
            event.preventDefault();
            setSubmitted((v) => v + 1);
            setBusy(true);
          }}
        >
          <Button onClick={() => setSubmitted((v) => v + 1)}>Обычная</Button>
          <Button variant="secondary" size="sm">
            Малая
          </Button>
          <Button variant="ghost">Без фона</Button>
          <Button variant="danger" size="lg">
            Удалить
          </Button>
          <Button
            icon={<IconPlus size={18} />}
            iconOnly
            aria-label="Добавить"
          />
          <Button disabled>Недоступно</Button>
          <Button type="submit" loading={busy}>
            Отправить
          </Button>
        </form>
        <output aria-label="Отправок">{submitted}</output>
      </Card>
      <Card title="Поля">
        <form onSubmit={(event) => event.preventDefault()}>
          <div className="grid grid-cols-2 gap-4">
            <FormField id="name" label="Название" required helper="Подсказка">
              <Input
                ref={inputRef}
                name="name"
                defaultValue="Название"
                aria-describedby="extra"
              />
            </FormField>
            <p id="extra">Дополнительное описание</p>
            <FormField
              id="invalid"
              label="Ошибка"
              error="Введите корректное значение"
            >
              <Input />
            </FormField>
            <FormField label="Комментарий">
              <Textarea rows={3} placeholder="Текст" />
            </FormField>
            <FormField label="Компания">
              <Select defaultValue="one">
                <option value="one">Первая</option>
                <option value="two">Вторая</option>
              </Select>
            </FormField>
            <FormField label="Поиск">
              <InputGroup
                clearable
                value={text}
                onChange={(event) => setText(event.target.value)}
                onClear={() => setText('')}
                prefix="₽"
                suffix="руб."
              />
            </FormField>
            <FormField label="Пароль">
              <InputGroup
                type="password"
                defaultValue="test-password"
                autoComplete="off"
              />
            </FormField>
            <FormField label="Только чтение">
              <Input readOnly defaultValue="Фиксировано" />
            </FormField>
            <FormField label="Отключено">
              <Input disabled defaultValue="Недоступно" />
            </FormField>
          </div>
          <Button
            variant="secondary"
            className="mt-3"
            onClick={() => inputRef.current?.focus()}
          >
            Фокус через ref
          </Button>
          <Button type="reset" variant="secondary">
            Сбросить поля
          </Button>
        </form>
      </Card>
      <Card title="Выбор">
        <div className="flex flex-wrap gap-5">
          <Checkbox label="Согласен" />
          <Checkbox label="Отключён" disabled />
          <Radio label="Месяц" name="period" defaultChecked value="month" />
          <Radio label="Год" name="period" value="year" />
          <Toggle label="Уведомления" />
          <Toggle label="Фиксированный" disabled defaultChecked />
          <Link href="#feedback">К обратной связи</Link>
          <Link external href="https://example.test">
            Внешняя ссылка
          </Link>
        </div>
      </Card>
      <Card title="Данные">
        <div className="flex flex-wrap gap-2">
          {(['api', 'manual', 'bot', 'xls', '1c'] as const).map((variant) => (
            <Badge key={variant} variant={variant}>
              {variant}
            </Badge>
          ))}
          {(['success', 'danger', 'warning', 'neutral'] as const).map(
            (tone) => (
              <StatusPill key={tone} tone={tone}>
                {tone}
              </StatusPill>
            ),
          )}
        </div>
      </Card>
      <FormField label="Отключённая группа">
        <InputGroup
          disabled
          value="100"
          readOnly
          action={
            <Button aria-label="Действие поля" iconOnly icon={<IconPlus />} />
          }
        />
      </FormField>
      <div id="feedback" className="grid gap-3">
        <Alert tone="danger" title="Ошибка">
          Проверьте введённые данные.
        </Alert>
        <Alert tone="warning" title="Внимание">
          Проверьте период.
        </Alert>
        <Alert tone="info" title="Информация">
          Данные обновлены.
        </Alert>
        <Alert tone="success" title="Готово">
          Изменения сохранены.
        </Alert>
        <EmptyState
          title="Нет операций"
          icon={<IconPlus size={20} />}
          action={<Button>Создать операцию</Button>}
        >
          Выберите другой период.
        </EmptyState>
      </div>
      <div className="flex gap-2">
        <Button onClick={() => setToast(true)}>Показать уведомление</Button>
        <Button variant="secondary" onClick={() => setShowTimer((v) => !v)}>
          Переключить таймер
        </Button>
      </div>
      <output aria-label="Закрытий">{closed}</output>
      <ErrorBoundary>
        <BoundaryExample />
      </ErrorBoundary>
      <Button onClick={() => setShowTones(true)}>Показать тоны</Button>
      <ToastStack>
        {showTones &&
          (['success', 'danger', 'warning', 'info'] as const).map((tone) => (
            <Toast
              key={tone}
              data-testid={`toast-${tone}`}
              tone={tone}
              duration={0}
              title={`Уведомление ${tone}`}
              onClose={() => {}}
            />
          ))}
        {toast && (
          <Toast
            data-testid="live-toast"
            title="Сохранено"
            onClose={() => {
              setClosed((v) => v + 1);
              setToast(false);
            }}
          >
            Данные сохранены.
          </Toast>
        )}
        {showTimer && (
          <Toast
            title="Таймер размонтирования"
            duration={500}
            onClose={() => setClosed((v) => v + 1)}
          />
        )}
      </ToastStack>
    </main>
  );
}
const root = document.getElementById('root');
if (!root) throw new Error('Missing fixture root');
createRoot(root).render(
  <StrictMode>
    {new URLSearchParams(window.location.search).has('layout') ? (
      <LayoutFixture />
    ) : (
      <Fixture />
    )}
  </StrictMode>,
);
