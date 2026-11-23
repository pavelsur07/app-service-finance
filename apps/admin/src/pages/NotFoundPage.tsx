import { EmptyState } from '@vashfindir/ds';
import { NavigationLink } from '../shared/NavigationLink';

// TODO(ds): dedicated 404 sheet pending; reuse the DS empty state.
export function NotFoundPage() {
  return (
    <EmptyState
      title="Не найдено"
      headingLevel={1}
      action={<NavigationLink href="/">На главную</NavigationLink>}
    >
      Такой страницы нет.
    </EmptyState>
  );
}
