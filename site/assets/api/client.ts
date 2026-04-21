import createClient from 'openapi-fetch';
import type { paths } from './schema';

/**
 * Типизированный клиент для внутреннего API VashFinDir.
 *
 * # Аутентификация
 * Через session cookie PHPSESSID, credentials: 'include' отправляется
 * автоматически для всех запросов.
 *
 * # CSRF
 * Защита обеспечивается на уровне cookie policy (SameSite=Lax на
 * PHPSESSID, см. config/packages/framework.yaml). Это стандартная
 * практика для cookie-based API в одном origin с UI.
 *
 * Если в будущем будут выделяться кросс-доменные клиенты или
 * публичный API — потребуется явный CSRF-токен через заголовок
 * X-CSRF-Token и соответствующий ExceptionSubscriber на бэке.
 * Это отдельная задача уровня реформы API.
 *
 * # Ошибки
 * Текущие эндпоинты возвращают разные форматы ошибок (legacy).
 * Целевой формат — RFC 7807 (схема Problem), но миграция постепенная.
 * error из openapi-fetch содержит response.data — разбирать по
 * месту с оглядкой на реальный формат конкретного эндпоинта.
 */
export const api = createClient<paths>({
    baseUrl: '',
    credentials: 'include',
});
