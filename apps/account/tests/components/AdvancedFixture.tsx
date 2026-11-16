import { useRef, useState } from 'react';
import {
  Avatar,
  Toast,
  ToastStack,
  AbcPill,
  MarketplaceMark,
  Chip,
  TagsInput,
  KpiCard,
  Table,
  SortHeader,
  TotalsRow,
  Pagination,
  Tabs,
  Modal,
  Drawer,
  ConfirmDialog,
  DropdownMenu,
  EntityPicker,
  TreePicker,
  Button,
  Input,
  FormField,
  Card,
  formatMoney,
  type TreePickerItem,
  type EntityPickerItem,
} from '@vashfindir/ds';
const companies: readonly EntityPickerItem[] = [
  {
    id: 'one',
    label: 'Первая компания',
    meta: 'Москва',
    leading: <Avatar name="Первая компания" shape="square" />,
  },
  {
    id: 'two',
    label: 'Вторая компания',
    meta: 'Казань',
    leading: <Avatar name="Вторая компания" shape="square" />,
  },
  { id: 'disabled', label: 'Недоступная компания', disabled: true },
];
const tree: readonly TreePickerItem[] = [
  {
    id: 'expenses',
    label: 'Расходы',
    children: [
      { id: 'rent', label: 'Аренда' },
      {
        id: 'staff',
        label: 'Персонал',
        children: [
          {
            id: 'payroll',
            label: 'Зарплата',
            meta: formatMoney(20000, { fractionDigits: 0 }),
          },
          { id: 'closed', label: 'Закрытая', disabled: true },
        ],
      },
    ],
  },
];
function Menu({ inside = false }: { inside?: boolean }) {
  const [open, setOpen] = useState(false),
    [chosen, setChosen] = useState('');
  return (
    <div className="flex items-center gap-2">
      <DropdownMenu
        trigger={inside ? 'Внутреннее меню' : 'Действия'}
        open={open}
        onOpenChange={setOpen}
        items={[
          { id: 'open', label: 'Открыть', onSelect: () => setChosen('open') },
          {
            id: 'disabled',
            label: 'Недоступно',
            disabled: true,
            onSelect: () => setChosen('disabled'),
          },
          { id: 'separator', separator: true },
          {
            id: 'delete',
            label: 'Удалить',
            tone: 'danger',
            onSelect: () => setChosen('delete'),
          },
        ]}
      />
      <Button variant="secondary">После меню</Button>
      <output aria-label={inside ? 'Внутренний выбор' : 'Выбор меню'}>
        {chosen}
      </output>
    </div>
  );
}
export function AdvancedFixture() {
  const [sort, setSort] = useState<'ascending' | 'descending' | null>(null),
    [page, setPage] = useState(1),
    [tab, setTab] = useState('overview'),
    [tags, setTags] = useState<string[]>(['Первая']),
    [chip, setChip] = useState(true),
    [selected, setSelected] = useState(false);
  const [modal, setModal] = useState(false),
    [size, setSize] = useState<'sm' | 'lg' | null>(null),
    [drawer, setDrawer] = useState(false),
    [nested, setNested] = useState(false),
    [confirm, setConfirm] = useState(false),
    [busy, setBusy] = useState(false),
    [confirmed, setConfirmed] = useState(0);
  const [entity, setEntity] = useState(false),
    [company, setCompany] = useState<string | null>('one'),
    [picker, setPicker] = useState(false),
    [category, setCategory] = useState<string | null>(null);
  const [disabledMenu, setDisabledMenu] = useState(false),
    [modalCloses, setModalCloses] = useState(0);
  const [requiredTags, setRequiredTags] = useState<string[]>([]);
  const [modalToast, setModalToast] = useState(false);
  const toastTrigger = useRef<HTMLButtonElement>(null);
  const input = useRef<HTMLInputElement>(null);
  const rows =
    sort === 'ascending'
      ? [
          { name: 'Вторая', amount: 1000 },
          { name: 'Первая', amount: 1200 },
        ]
      : [
          { name: 'Первая', amount: 1200 },
          { name: 'Вторая', amount: 1000 },
        ];
  return (
    <main className="mx-auto grid max-w-6xl gap-5 p-6">
      <h1 className="text-4xl font-bold">Данные и оверлеи ДС v3.4</h1>
      <div className="flex flex-wrap gap-2">
        <Button onClick={() => setModal(true)}>Открыть Modal</Button>
        <Button onClick={() => setSize('sm')}>Открыть S</Button>
        <Button onClick={() => setSize('lg')}>Открыть L</Button>
        <Button onClick={() => setDrawer(true)}>Открыть Drawer</Button>
        <Button onClick={() => setConfirm(true)}>Открыть подтверждение</Button>
        <Button onClick={() => setEntity(true)}>Выбрать компанию</Button>
        <Button onClick={() => setPicker(true)}>Выбрать статью</Button>
        <Button data-testid="background" variant="secondary">
          Фоновое действие
        </Button>
      </div>
      <Button
        onClick={() => {
          setDrawer(true);
          setNested(true);
        }}
      >
        Открыть вложенные сразу
      </Button>
      <Menu />
      <DropdownMenu
        trigger="Недоступное меню"
        open={disabledMenu}
        onOpenChange={setDisabledMenu}
        items={[
          {
            id: 'disabled',
            label: 'Недоступное действие',
            disabled: true,
            onSelect: () => {},
          },
        ]}
      />
      <Button
        data-testid="unmount-drawer"
        onClick={() => {
          setDrawer(false);
          setNested(false);
        }}
      >
        Размонтировать вложенные окна
      </Button>
      <output aria-label="Закрытий Modal">{modalCloses}</output>
      <div className="grid grid-cols-1 gap-3 md:grid-cols-3">
        <KpiCard
          label="Выручка"
          value={formatMoney(1200000, { fractionDigits: 0 })}
          delta={{ value: '+12,4%', tone: 'up' }}
          meta="к прошлому месяцу"
        />
        <KpiCard
          label="Расходы"
          value={
            <span data-testid="expense-value">
              {formatMoney(-156200, { fractionDigits: 0 })}
            </span>
          }
          delta={{ value: '−4,1%', tone: 'down' }}
        />
        <KpiCard
          hero
          label="Остаток"
          value={formatMoney(2200000, { fractionDigits: 0 })}
          meta="На всех счетах"
        />
      </div>
      <p data-testid="money" className="font-semibold tabular-nums">
        {formatMoney(-1234567.89)}
      </p>
      <Card title="Метки и участники">
        <div className="flex flex-wrap items-center gap-3">
          {chip && (
            <Chip
              selected={selected}
              onSelect={() => setSelected((v) => !v)}
              onRemove={() => setChip(false)}
            >
              Фильтр
            </Chip>
          )}
          {(['A', 'B', 'C'] as const).flatMap((revenue) =>
            (['A', 'B', 'C'] as const).map((profit) => (
              <AbcPill
                key={`${revenue}${profit}`}
                revenue={revenue}
                profit={profit}
              />
            )),
          )}
          {(['ozon', 'wb', 'yamarket', 'megamarket'] as const).map(
            (marketplace) => (
              <MarketplaceMark key={marketplace} marketplace={marketplace} />
            ),
          )}
          <Avatar name="Павел Иванов" />
          <Avatar name="Ошибка фото" src="/missing-avatar.svg" />
          <MarketplaceMark marketplace="ozon" inline />
        </div>
      </Card>
      <TagsInput
        label="Метки"
        value={tags}
        onChange={setTags}
        suggestions={['Оплата', 'Доставка', 'Возврат']}
      />
      <TagsInput
        label="Заблокированные метки"
        value={['Фиксированная']}
        onChange={() => {}}
        disabled
      />
      <form
        data-testid="tags-form"
        onSubmit={(event) => event.preventDefault()}
      >
        <TagsInput
          label="Обязательные метки"
          name="tags"
          required
          value={requiredTags}
          onChange={setRequiredTags}
        />
      </form>
      <Tabs
        label="Отчёты"
        value={tab}
        onChange={setTab}
        items={[
          {
            id: 'overview',
            label: 'Обзор',
            count: 2,
            tone: 'warning',
            content: 'Содержимое обзора',
          },
          { id: 'disabled', label: 'Недоступный', disabled: true },
          { id: 'history', label: 'История', content: 'Содержимое истории' },
        ]}
      />
      <Card flush>
        <Table caption="Операции">
          <thead>
            <tr>
              <th scope="col">Операция</th>
              <SortHeader
                align="right"
                direction={sort}
                onSort={() =>
                  setSort((value) =>
                    value === 'ascending' ? 'descending' : 'ascending',
                  )
                }
              >
                Сумма
              </SortHeader>
            </tr>
          </thead>
          <tbody>
            {rows.map((row) => (
              <tr key={row.name}>
                <td>{row.name}</td>
                <td data-align="right">
                  {formatMoney(row.amount, { fractionDigits: 0 })}
                </td>
              </tr>
            ))}
          </tbody>
          <tfoot>
            <TotalsRow>
              <th scope="row">Итого</th>
              <td data-align="right">
                {formatMoney(2200, { fractionDigits: 0 })}
              </td>
            </TotalsRow>
          </tfoot>
        </Table>
        <Pagination
          page={page}
          pageSize={10}
          total={1000}
          onPageChange={setPage}
        />
      </Card>
      <output aria-label="Компания выбрана">{company ?? 'none'}</output>
      <output aria-label="Статья выбрана">{category ?? 'none'}</output>
      <output aria-label="Подтверждений">{confirmed}</output>
      <Button
        data-testid="finish-confirm"
        onClick={() => {
          setBusy(false);
          setConfirm(false);
        }}
      >
        Завершить синтетический запрос
      </Button>
      <div className="h-200 rounded-md bg-surface-subtle p-4">
        Длинная фоновая область для проверки блокировки прокрутки
      </div>
      <Modal
        open={modal}
        onClose={() => {
          setModalCloses((v) => v + 1);
          setModal(false);
        }}
        title="Диалог"
        notifications={
          modalToast ? (
            <ToastStack>
              <Toast
                title="Сохранено в окне"
                duration={0}
                onClose={() => {
                  setModalToast(false);
                  toastTrigger.current?.focus();
                }}
              >
                Уведомление доступно поверх панели
              </Toast>
            </ToastStack>
          ) : undefined
        }
        description="Минимальная композиция из существующих контролов"
        initialFocusRef={input}
        footer={
          <Button onClick={() => setModal(false)}>Последняя кнопка</Button>
        }
      >
        <Button ref={toastTrigger} onClick={() => setModalToast(true)}>
          Показать уведомление окна
        </Button>
        <FormField label="Название">
          <Input ref={input} />
        </FormField>
        <TagsInput
          label="Метки в окне"
          value={tags}
          onChange={setTags}
          suggestions={['Оплата']}
        />
        <div className="mt-4">
          <Menu inside />
        </div>
      </Modal>
      <Modal
        open={size !== null}
        onClose={() => setSize(null)}
        title="Размер"
        size={size ?? 'sm'}
      >
        Окно заданного размера
      </Modal>
      <Drawer
        open={drawer}
        onClose={() => {
          setDrawer(false);
          setNested(false);
        }}
        title="Панель"
        footer={<Button onClick={() => setDrawer(false)}>Закрыть</Button>}
      >
        <Button onClick={() => setNested(true)}>Вложенный Modal</Button>
        <div className="mt-4 grid gap-3">
          {Array.from({ length: 40 }, (_, index) => (
            <Card key={index}>Строка {index + 1}</Card>
          ))}
        </div>
        <Modal
          open={nested}
          onClose={() => setNested(false)}
          title="Вложенный диалог"
          data-testid="nested-dialog"
        >
          <Input aria-label="Вложенное поле" />
        </Modal>
      </Drawer>
      <ConfirmDialog
        open={confirm}
        onClose={() => setConfirm(false)}
        title="Удалить запись?"
        description="Пример подтверждения, данные не изменяются."
        confirmLabel="Удалить"
        destructive
        busy={busy}
        onConfirm={() => {
          setConfirmed((v) => v + 1);
          setBusy(true);
        }}
      />
      <EntityPicker
        open={entity}
        onOpenChange={setEntity}
        title="Компании"
        items={companies}
        value={company}
        onChange={setCompany}
      />
      <TreePicker
        open={picker}
        onOpenChange={setPicker}
        title="Статьи"
        items={tree}
        value={category}
        onChange={setCategory}
      />
    </main>
  );
}
