# Stage 1 — логотип и общие компоненты

**Задача.** Заменить текстовый «ВФ» и старые favicon файлами бренда; собрать общий каркас страниц авторизации: шапка, подвал с реквизитами, баннер cookie.
**Результат.** `public/brand/*`, favicon во всех layout'ах, логотип в легаси-меню, Vite-entry `auth`, partials `security/_auth/*`, параметр `vf_legal`.
**Выполнено, если.** Страницы рендерятся без Tabler и кита; в prod нет демо-реквизитов и мёртвых ссылок; баннер пишет `vf_cookie_consent`.
**Чек-лист приёмки.** [x] файлы бренда в public; [x] favicon/apple-touch/OG; [x] логотип в меню 32 px (`after/sidebar-logo-1440.png`); [x] `vf_legal` пусто в prod, DEMO в dev; [x] баннер и событие `vf:cookie-consent`.
**Итог.** Коммит `1c23af9f6`. Внутреннее ревью — без BLOCKER/IMPORTANT.
