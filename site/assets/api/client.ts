import createClient from 'openapi-fetch';
import type { paths } from './schema';

/**
 * Типизированный клиент для внутреннего API VashFinDir.
 *
 * Аутентификация — через session cookie (PHPSESSID). credentials: 'include'
 * добавляется автоматически для всех запросов, baseUrl пуст — запросы идут
 * на тот же origin, где подан фронт.
 */
export const api = createClient<paths>({
    baseUrl: '',
    credentials: 'include',
});
