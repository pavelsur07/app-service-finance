import { createRef } from 'react';
import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it } from 'vitest';
import {
  Alert,
  Badge,
  Button,
  Card,
  Checkbox,
  EmptyState,
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
} from '../index';

describe('accessible DS component contracts', () => {
  it('defaults Button to a non-submitting native button', () => {
    const html = renderToStaticMarkup(
      <Button name="action" value="save">
        Сохранить
      </Button>,
    );
    expect(html).toContain('type="button"');
    expect(html).toContain('name="action"');
    expect(html).toContain('Сохранить');
  });
  it('loading disables Button and announces busy without hiding its label', () => {
    const html = renderToStaticMarkup(
      <Button type="submit" loading>
        Войти
      </Button>,
    );
    expect(html).toContain('disabled=""');
    expect(html).toContain('aria-busy="true"');
    expect(html).toContain('type="submit"');
    expect(html).toContain('Войти');
  });
  for (const variant of ['primary', 'secondary', 'ghost', 'danger'] as const) {
    it(`renders ${variant} Button`, () =>
      expect(
        renderToStaticMarkup(<Button variant={variant}>Действие</Button>),
      ).toContain('Действие'));
  }
  it('requires an accessible label for icon-only buttons at runtime too', () => {
    expect(() => renderToStaticMarkup(<Button iconOnly icon="+" />)).toThrow(
      /aria-label/,
    );
    expect(
      renderToStaticMarkup(<Button iconOnly icon="+" aria-label="Добавить" />),
    ).toContain('aria-label="Добавить"');
  });
  it('external navigation retains caller rel and adds safe target and description', () => {
    const html = renderToStaticMarkup(
      <Link href="https://example.test" external rel="nofollow">
        Справка
      </Link>,
    );
    expect(html).toContain('target="_blank"');
    expect(html).toContain('nofollow');
    expect(html).toContain('noopener');
    expect(html).toContain('noreferrer');
    expect(html).toContain('в новой вкладке');
  });
  it('associates label, helper and error while retaining caller descriptions', () => {
    const html = renderToStaticMarkup(
      <FormField
        id="email"
        label="Email"
        required
        helper="Рабочий адрес"
        error="Неверный email"
      >
        <Input aria-describedby="policy" />
      </FormField>,
    );
    expect(html).toContain('for="email"');
    expect(html).toContain('id="email"');
    expect(html).toContain(
      'aria-describedby="policy email-helper email-error"',
    );
    expect(html).toContain('aria-invalid="true"');
    expect(html).toContain('required=""');
    expect(html).toContain('id="email-error"');
  });
  it('uses child id when no field id is supplied', () => {
    const html = renderToStaticMarkup(
      <FormField label="Имя">
        <Input id="name" />
      </FormField>,
    );
    expect(html).toContain('for="name"');
    expect(html).not.toContain('aria-invalid="true"');
  });
  it('rejects a conflicting child id rather than breaking label association', () => {
    expect(() =>
      renderToStaticMarkup(
        <FormField id="a" label="Имя">
          <Input id="b" />
        </FormField>,
      ),
    ).toThrow(/id/);
  });
  it('Input forwards native properties and supports refs', () => {
    const html = renderToStaticMarkup(
      <Input
        ref={createRef<HTMLInputElement>()}
        name="email"
        type="email"
        autoComplete="username"
        readOnly
        defaultValue="a@example.test"
        error
      />,
    );
    expect(html.toLowerCase()).toContain('readonly=""');
    expect(html.toLowerCase()).toContain('autocomplete="username"');
    expect(html).toContain('aria-invalid="true"');
  });
  it('Textarea forwards rows and validation', () => {
    const html = renderToStaticMarkup(
      <Textarea
        name="notes"
        rows={4}
        maxLength={100}
        error
        defaultValue="Комментарий"
      />,
    );
    expect(html).toContain('rows="4"');
    expect(html.toLowerCase()).toContain('maxlength="100"');
    expect(html).toContain('aria-invalid="true"');
  });
  it('Select keeps native options and disabled state', () => {
    const html = renderToStaticMarkup(
      <Select name="company" disabled defaultValue="a">
        <option value="a">Компания</option>
      </Select>,
    );
    expect(html).toContain('<select');
    expect(html).toContain('disabled=""');
    expect(html).toContain('selected=""');
  });
  it('InputGroup exposes one accessible native field with prefix and suffix', () => {
    const html = renderToStaticMarkup(
      <InputGroup
        id="sum"
        prefix="₽"
        suffix="руб."
        aria-label="Сумма"
        value="100"
        onChange={() => {}}
      />,
    );
    expect(html).toContain('id="sum"');
    expect(html).toContain('aria-label="Сумма"');
    expect(html.match(/<input/g)).toHaveLength(1);
    expect(html).toContain('руб.');
  });
  it('InputGroup clear action is absent for empty, disabled or read-only inputs', () => {
    for (const props of [
      { value: '' },
      { value: 'x', disabled: true },
      { value: 'x', readOnly: true },
    ]) {
      expect(
        renderToStaticMarkup(
          <InputGroup
            {...props}
            clearable
            onChange={() => {}}
            onClear={() => {}}
          />,
        ),
      ).not.toContain('Очистить поле');
    }
  });
  it('InputGroup password action is accessible and cannot submit', () => {
    const html = renderToStaticMarkup(
      <InputGroup type="password" defaultValue="secret" />,
    );
    expect(html).toContain('Показать пароль');
    expect(html).toContain('type="button"');
    expect(html).toContain('aria-pressed="false"');
  });
  it('Checkbox wraps a labelled native input', () => {
    const html = renderToStaticMarkup(
      <Checkbox label="Согласен" name="terms" defaultChecked disabled />,
    );
    expect(html).toContain('<label');
    expect(html).toContain('type="checkbox"');
    expect(html).toContain('checked=""');
    expect(html).toContain('disabled=""');
  });
  it('Radio retains the group name and native semantics', () => {
    const html = renderToStaticMarkup(
      <Radio label="Месяц" name="period" value="month" />,
    );
    expect(html).toContain('type="radio"');
    expect(html).toContain('name="period"');
  });
  it('Toggle uses a labelled native checkbox with switch semantics', () => {
    const html = renderToStaticMarkup(
      <Toggle label="Уведомления" name="notifications" defaultChecked />,
    );
    expect(html).toContain('type="checkbox"');
    expect(html).toContain('role="switch"');
    expect(html).toContain('Уведомления');
  });
  for (const variant of ['api', 'manual', 'bot', 'xls', '1c'] as const) {
    it(`renders source badge ${variant}`, () =>
      expect(
        renderToStaticMarkup(<Badge variant={variant}>{variant}</Badge>),
      ).toContain(variant));
  }
  it('StatusPill communicates meaning as text, not a live region or color alone', () => {
    const html = renderToStaticMarkup(
      <StatusPill tone="success">Готово</StatusPill>,
    );
    expect(html).toContain('Готово');
    expect(html).toContain('aria-hidden="true"');
    expect(html).not.toContain('role="status"');
  });
  it('Card has a heading, actions and content', () => {
    const html = renderToStaticMarkup(
      <Card title="Данные" actions={<Button>Изменить</Button>}>
        Содержимое
      </Card>,
    );
    expect(html).toContain('<h2');
    expect(html).toContain('Данные');
    expect(html).toContain('Содержимое');
    expect(html).toContain('Изменить');
  });
  it('EmptyState supports a page heading and an accessible CTA', () => {
    const html = renderToStaticMarkup(
      <EmptyState
        title="Нет данных"
        headingLevel={1}
        action={<Button>Добавить</Button>}
      >
        Описание
      </EmptyState>,
    );
    expect(html).toContain('<h1');
    expect(html).toContain('Описание');
    expect(html).toContain('Добавить');
  });
  for (const tone of ['success', 'danger', 'warning', 'info'] as const) {
    it(`Alert ${tone} uses appropriate live semantics`, () => {
      const html = renderToStaticMarkup(
        <Alert tone={tone} title="Результат">
          Описание
        </Alert>,
      );
      expect(html).toContain(
        `role="${tone === 'danger' ? 'alert' : 'status'}"`,
      );
      expect(html).toContain('Описание');
    });
  }
  it('Toast is dismissible without moving focus and is a polite status', () => {
    const html = renderToStaticMarkup(
      <Toast title="Сохранено" onClose={() => {}}>
        Готово
      </Toast>,
    );
    expect(html).toContain('role="status"');
    expect(html).toContain('Закрыть уведомление');
    expect(html).not.toContain('autofocus');
  });
});

it('ToastStack has an accessible region only when notifications exist', () => {
  expect(renderToStaticMarkup(<ToastStack>{false}</ToastStack>)).toBe('');
  const html = renderToStaticMarkup(
    <ToastStack>
      <Toast title="Сохранено" onClose={() => {}} />
    </ToastStack>,
  );
  expect(html).toContain('<section aria-label="Уведомления"');
  expect(html).toContain('Сохранено');
});

describe('Login controls v3.4', () => {
  it('shows an accessible-hidden spinner when Button is loading', () => {
    const html = renderToStaticMarkup(<Button loading>Входим…</Button>);
    expect(html).toContain('ds-spinner');
    expect(html).toContain('aria-busy="true"');
  });
  it('supports the auth InputGroup with 18px password action icon', () => {
    const html = renderToStaticMarkup(
      <InputGroup variant="auth" type="password" />,
    );
    expect(html).toContain('ds-input-group-auth');
    expect(html).toContain('width="18"');
  });
});
