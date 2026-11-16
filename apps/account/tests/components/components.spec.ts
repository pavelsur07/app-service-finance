import { expect, test } from '@playwright/test';

test.beforeEach(async ({ page }) => {
  await page.goto('/');
});

test('native button keyboard, default type and loading prevent repeat submission', async ({
  page,
}) => {
  const ordinary = page.getByRole('button', { name: 'Обычная', exact: true });
  await ordinary.focus();
  await page.keyboard.press('Enter');
  await expect(page.getByLabel('Отправок')).toHaveText('1');
  await page.keyboard.press('Space');
  await expect(page.getByLabel('Отправок')).toHaveText('2');
  const submit = page.getByRole('button', { name: 'Отправить', exact: true });
  await submit.click();
  await expect(submit).toBeDisabled();
  await expect(submit).toHaveAttribute('aria-busy', 'true');
  await expect(page.getByLabel('Отправок')).toHaveText('3');
  await expect(page.getByRole('button', { name: 'Недоступно' })).toBeDisabled();
});

test('labels, ref, descriptions, required and invalid semantics reach native fields', async ({
  page,
}) => {
  const name = page.getByLabel('Название', { exact: true });
  await expect(name).toHaveAccessibleDescription(
    'Дополнительное описание Подсказка',
  );
  await expect(name).toHaveAttribute('required');
  await page.getByRole('button', { name: 'Фокус через ref' }).click();
  await expect(name).toBeFocused();
  await expect(name).toHaveCSS('outline-width', '2px');
  const invalid = page.getByRole('textbox', { name: 'Ошибка', exact: true });
  await expect(invalid).toHaveAttribute('aria-invalid', 'true');
  await expect(invalid).toHaveAccessibleDescription(
    'Введите корректное значение',
  );
  await expect(page.getByLabel('Только чтение')).toHaveAttribute('readonly');
  await expect(page.getByLabel('Отключено')).toBeDisabled();
  await expect(page.getByLabel('Комментарий')).toHaveCSS('min-height', '80px');
  await page.getByLabel('Компания', { exact: true }).selectOption('two');
  await expect(page.getByLabel('Компания', { exact: true })).toHaveValue('two');
});

test('controlled clear and password actions preserve native field identity', async ({
  page,
}) => {
  const search = page.getByRole('textbox', { name: 'Поиск', exact: true });
  await page.getByRole('button', { name: 'Очистить поле' }).click();
  await expect(search).toHaveValue('');
  await expect(search).toBeFocused();
  await expect(page.getByRole('button', { name: 'Очистить поле' })).toHaveCount(
    0,
  );
  await search.fill('Новое значение');
  await page.getByRole('button', { name: 'Очистить поле' }).focus();
  await page.keyboard.press('Enter');
  await expect(search).toHaveValue('');
  const password = page.getByLabel('Пароль', { exact: true });
  await password.fill('changed-password');
  await page.getByRole('button', { name: 'Показать пароль' }).click();
  await expect(password).toHaveAttribute('type', 'text');
  await expect(password).toHaveValue('changed-password');
  await expect(password).toBeFocused();
  await expect(
    page.getByRole('button', { name: 'Скрыть пароль' }),
  ).toHaveAttribute('aria-pressed', 'true');
  await page.getByRole('button', { name: 'Скрыть пароль' }).click();
  await expect(password).toHaveAttribute('type', 'password');
  await expect(password).toHaveValue('changed-password');
  await page.getByRole('button', { name: 'Сбросить поля' }).click();
  await expect(password).toHaveValue('test-password');
});

test('checkbox/radio/switch retain native keyboard and disabled behavior', async ({
  page,
}) => {
  const checkbox = page.getByRole('checkbox', { name: 'Согласен' });
  await checkbox.focus();
  await page.keyboard.press('Space');
  await expect(checkbox).toBeChecked();
  await expect(page.getByRole('checkbox', { name: 'Отключён' })).toBeDisabled();
  const month = page.getByRole('radio', { name: 'Месяц' });
  await month.focus();
  await page.keyboard.press('ArrowRight');
  await expect(page.getByRole('radio', { name: 'Год' })).toBeChecked();
  const toggle = page.getByRole('switch', { name: 'Уведомления' });
  await toggle.focus();
  await page.keyboard.press('Space');
  await expect(toggle).toBeChecked();
  await expect(toggle.locator('+ span > span')).toHaveCSS(
    'transform',
    'matrix(1, 0, 0, 1, 18, 0)',
  );
  await expect(
    page.getByRole('switch', { name: 'Фиксированный' }),
  ).toBeDisabled();
});

test('external links, decorative icons and feedback retain accessible meaning', async ({
  page,
}) => {
  const external = page.getByRole('link', {
    name: /Внешняя ссылка.*в новой вкладке/,
  });
  await expect(external).toHaveAttribute('target', '_blank');
  await expect(external).toHaveAttribute('rel', /noopener/);
  await expect(page.getByRole('alert')).toHaveText(
    'ОшибкаПроверьте введённые данные.',
  );
  await expect(
    page.getByRole('button', { name: 'Добавить', exact: true }),
  ).toBeVisible();
  await expect(
    page.getByRole('heading', { name: 'Нет операций' }),
  ).toBeVisible();
});

test('Toast auto-dismisses at 4s, pauses on focus and closes once', async ({
  page,
}) => {
  await page.clock.install();
  await page.clock.pauseAt(new Date(Date.now() + 1000));
  await page.getByRole('button', { name: 'Показать уведомление' }).click();
  const toast = page.getByRole('status').filter({ hasText: 'Сохранено' });
  await expect(toast).toBeVisible();
  await page.clock.fastForward(3999);
  await expect(toast).toBeVisible();
  const close = page.getByRole('button', { name: 'Закрыть уведомление' });
  await close.focus();
  await page.clock.fastForward(6000);
  await expect(toast).toBeVisible();
  await page.getByRole('button', { name: 'Обычная', exact: true }).focus();
  await page.mouse.move(0, 0);
  await page.clock.fastForward(50);
  await expect(toast).toHaveCount(0);
  await expect(page.getByLabel('Закрытий')).toHaveText('1');
  await page.getByRole('button', { name: 'Показать уведомление' }).click();
  await page.getByRole('button', { name: 'Закрыть уведомление' }).click();
  await expect(page.getByLabel('Закрытий')).toHaveText('2');
  await page.clock.fastForward(5000);
  await expect(page.getByLabel('Закрытий')).toHaveText('2');
});

test('Toast hover pauses and unmount cancels pending callbacks', async ({
  page,
}) => {
  await page.clock.install();
  await page.clock.pauseAt(new Date(Date.now() + 1000));
  await page.getByRole('button', { name: 'Показать уведомление' }).click();
  const toast = page.getByRole('status').filter({ hasText: 'Сохранено' });
  await toast.hover();
  await page.clock.fastForward(5000);
  await expect(toast).toBeVisible();
  await page.getByRole('button', { name: 'Закрыть уведомление' }).click();
  await page.getByRole('button', { name: 'Переключить таймер' }).click();
  await page.getByRole('button', { name: 'Переключить таймер' }).click();
  await page.clock.fastForward(1000);
  await expect(page.getByLabel('Закрытий')).toHaveText('1');
});

test('visual acceptance: sizes, text/border contrast, icon stroke and 1440 screenshot', async ({
  page,
}, testInfo) => {
  await page.evaluate(() => document.fonts.ready);
  await expect(page.getByRole('button', { name: 'Малая' })).toHaveCSS(
    'height',
    '30px',
  );
  await expect(page.getByRole('button', { name: 'Обычная' })).toHaveCSS(
    'height',
    '36px',
  );
  await expect(page.getByRole('button', { name: 'Удалить' })).toHaveCSS(
    'height',
    '44px',
  );
  await expect(
    page.getByRole('textbox', { name: 'Название', exact: true }),
  ).toHaveCSS('height', '38px');
  await expect(
    page.getByRole('button', { name: 'Добавить', exact: true }).locator('svg'),
  ).toHaveAttribute('stroke-width', '2');
  const contrast = await page.evaluate(() => {
    function luminance(value: string) {
      const values =
        value
          .match(/[\d.]+/g)
          ?.slice(0, 3)
          .map(Number) ?? [];
      return values.reduce((sum, channel, i) => {
        const n = channel / 255;
        return (
          sum +
          (n <= 0.04045 ? n / 12.92 : ((n + 0.055) / 1.055) ** 2.4) *
            ([0.2126, 0.7152, 0.0722][i] ?? 0)
        );
      }, 0);
    }
    function ratio(a: string, b: string) {
      const x = luminance(a),
        y = luminance(b);
      return (Math.max(x, y) + 0.05) / (Math.min(x, y) + 0.05);
    }
    const input = document.querySelector('input');
    const button = document.querySelector('button');
    if (!input || !button) throw new Error('Missing fixture controls');
    const i = getComputedStyle(input),
      b = getComputedStyle(button);
    const invalid = document.querySelector('input[aria-invalid="true"]');
    if (!invalid) throw new Error('Missing invalid control');
    const error = getComputedStyle(invalid);
    const feedback = [
      ...document.querySelectorAll('[role="alert"], [role="status"]'),
    ].map((element) => {
      const style = getComputedStyle(element);
      return ratio(style.color, style.backgroundColor);
    });
    return {
      border: ratio(i.borderTopColor, i.backgroundColor),
      errorBorder: ratio(error.borderTopColor, error.backgroundColor),
      text: ratio(b.color, b.backgroundColor),
      feedback,
    };
  });
  expect(contrast.border).toBeGreaterThanOrEqual(3);
  expect(contrast.text).toBeGreaterThanOrEqual(4.5);
  expect(contrast.errorBorder).toBeGreaterThanOrEqual(3);
  for (const ratio of contrast.feedback)
    expect(ratio).toBeGreaterThanOrEqual(4.5);
  await page.screenshot({
    path: testInfo.outputPath('components-1440.png'),
    fullPage: true,
  });
});

test('InputGroup adornments do not cover the input and disabled blocks custom actions', async ({
  page,
}) => {
  const input = page.getByLabel('Поиск', { exact: true });
  const suffix = page.getByText('руб.', { exact: true });
  const fieldBox = await input.boundingBox();
  const suffixBox = await suffix.boundingBox();
  if (!fieldBox || !suffixBox) throw new Error('Missing input adornments');
  expect(fieldBox.x + fieldBox.width).toBeLessThanOrEqual(suffixBox.x);
  await expect(
    page.getByRole('button', { name: 'Действие поля' }),
  ).toBeDisabled();
  await expect(page.getByRole('button', { name: 'Действие поля' })).toHaveCSS(
    'height',
    '26px',
  );
});

test('Toast tones have semantic borders and circular icon backgrounds', async ({
  page,
}) => {
  await page.getByRole('button', { name: 'Показать тоны' }).click();
  for (const tone of ['success', 'danger', 'warning', 'info']) {
    const toast = page.getByTestId(`toast-${tone}`);
    const actual = await toast.evaluate((element, tone) => {
      const sample = document.createElement('span');
      sample.style.borderColor = `var(--ds-${tone}-border)`;
      sample.style.backgroundColor = `var(--ds-${tone}-bg)`;
      document.body.append(sample);
      const expected = getComputedStyle(sample);
      const icon = element.querySelector('[aria-hidden="true"]');
      if (!icon) throw new Error('No toast icon');
      const result = {
        border: getComputedStyle(element).borderTopColor,
        expectedBorder: expected.borderTopColor,
        bg: getComputedStyle(icon).backgroundColor,
        expectedBg: expected.backgroundColor,
        radius: getComputedStyle(icon).borderRadius,
      };
      sample.remove();
      return result;
    }, tone);
    expect(actual.border).toBe(actual.expectedBorder);
    expect(actual.bg).toBe(actual.expectedBg);
    expect(parseFloat(actual.radius)).toBeGreaterThanOrEqual(10);
  }
});

test('render errors use DS fallback and reload recovers the page', async ({
  page,
}) => {
  await page.getByRole('button', { name: 'Сломать пример' }).click();
  await expect(
    page.getByRole('alert').filter({ hasText: 'Не удалось открыть страницу' }),
  ).toBeVisible();
  await page.getByRole('button', { name: 'Обновить страницу' }).click();
  await expect(
    page.getByRole('button', { name: 'Сломать пример' }),
  ).toBeVisible();
});

test('Toast stays in the viewport above content without moving the page', async ({
  page,
}) => {
  await page.getByRole('button', { name: 'Показать уведомление' }).click();
  const toast = page.getByTestId('live-toast');
  const box = await toast.boundingBox();
  if (!box) throw new Error('Missing visible Toast');
  expect(box.y).toBe(24);
  expect(box.x + box.width).toBe(1440 - 24);
  await expect(page.getByRole('region', { name: 'Уведомления' })).toHaveCSS(
    'position',
    'fixed',
  );
  await expect(page.getByRole('region', { name: 'Уведомления' })).toHaveCSS(
    'z-index',
    '200',
  );
});
