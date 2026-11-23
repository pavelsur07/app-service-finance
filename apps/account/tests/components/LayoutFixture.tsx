import { useState } from 'react';
import {
  AppShell,
  AppHeader,
  Sidebar,
  PageHeader,
  AppFooter,
  Button,
  Modal,
  IconHome,
  IconInfoCircle,
  useSidebarState,
  type SidebarItem,
} from '@vashfindir/ds';
const items: readonly SidebarItem[] = [
  {
    id: 'workspace',
    label: 'Кабинет',
    icon: <IconHome />,
    children: [
      { id: 'overview', label: 'Обзор', href: '#overview' },
      { id: 'details', label: 'Детали', href: '#details' },
    ],
  },
  {
    id: 'settings',
    label: 'Настройки',
    icon: <IconInfoCircle />,
    children: [
      {
        id: 'notifications',
        label: 'Параметры уведомлений',
        href: '#notifications',
      },
    ],
  },
  ...Array.from({ length: 35 }, (_, i) => ({
    id: `row-${i}`,
    label: `Раздел ${i + 1}`,
    href: `#row-${i}`,
  })),
];
export function LayoutFixture() {
  const sidebar = useSidebarState('vf.fixture.sidebar');
  const [long, setLong] = useState(false);
  const [logout, setLogout] = useState(0);
  const [modal, setModal] = useState(false);
  return (
    <AppShell
      {...sidebar}
      header={
        <AppHeader
          brandLabel="Ваш Финдир"
          breadcrumbs={[
            { label: 'Кабинет', href: '#home' },
            { label: 'Обзор' },
          ]}
        />
      }
      sidebar={
        <Sidebar
          items={items}
          activeId="overview"
          user={{ name: 'person@example.test', detail: 'Тестовый контекст' }}
          onLogout={() => setLogout((v) => v + 1)}
        />
      }
      footer={<AppFooter>Информация о приложении</AppFooter>}
    >
      <PageHeader
        title="Обзор"
        description="Каркас приложения"
        actions={
          <Button onClick={() => setLong((v) => !v)}>Длинная страница</Button>
        }
      />
      <Button onClick={() => setModal(true)}>Открыть окно в каркасе</Button>
      <Modal open={modal} onClose={() => setModal(false)} title="Окно каркаса">
        <p>Фон каркаса изолирован</p>
      </Modal>
      <p>Рабочая зона</p>
      <output aria-label="Выходов">{logout}</output>
      {long && <div className="h-[2400px]">Длинное содержимое</div>}
    </AppShell>
  );
}
