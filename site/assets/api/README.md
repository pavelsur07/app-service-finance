# API-контракт фронта

Этот каталог — **сгенерированный мост** между OpenAPI-спекой бэкенда (Symfony + Nelmio) и TypeScript-кодом фронта.

## Файлы

- `schema.d.ts` — **автогенерируется**. НЕ править руками. Содержит типы всех API-эндпоинтов и DTO-схем.
- `client.ts` — типизированный клиент `openapi-fetch`. Единая точка вызова API.

## Когда регенерировать типы

Запусти `make api-types` после любого изменения в бэке:

- Добавил/изменил эндпоинт под `/api`
- Изменил Request/Response-DTO
- Изменил `#[OA\Schema]` атрибут
- Обновил `config/packages/nelmio_api_doc.yaml`

После генерации — закоммить `schema.d.ts` в git вместе с кодом бэка. CI проверяет соответствие.

## Как использовать

```typescript
import { api } from '@/api/client';

// GET с query-параметрами
const { data, error } = await api.GET('/api/marketplace-analytics/snapshots', {
  params: { query: { page: 1, perPage: 20 } },
});

if (error) {
  console.error(error);
  return;
}
// data типизирован: { data: SnapshotResponse[], meta: PaginationMeta }

// POST с body
const { data: result } = await api.POST('/api/marketplaceanalytics', {
  body: { title: 'Аналитика Q1' },
});
```

## Как проверить, что типы работают

Запусти `yarn build` в `site-frontend`. Если вызов API ссылается на несуществующее поле или неверный метод — TypeScript ругнётся на этапе компиляции.

Быстрая проверка вручную:

```typescript
const { data } = await api.GET('/api/marketplace-analytics/snapshots', { params: { query: {} } });
data?.data[0].listing_name;  // ok
data?.data[0].nonexistent;   // TS error: Property 'nonexistent' does not exist
```

## Безопасность

- **Auth:** session cookie `PHPSESSID` (form_login на `/login`)
- **CSRF:** `SameSite=Lax` на cookie (см. `config/packages/framework.yaml`) — защита от cross-site запросов на уровне браузера
- **API в том же origin**, что и UI — кросс-доменные вызовы не поддерживаются

Если разрабатывается интеграция с внешним доменом или мобильным клиентом — `client.ts` не подходит, нужен отдельный клиент с API-token или JWT auth. Это требует реформы firewall на бэке (отдельная задача, не в этой серии PR).
