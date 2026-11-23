# Вкладки настроек компании

Ветка `feat/company-edit-tabs`. [Pull request и проверки GitHub](https://github.com/pavelsur07/app-service-finance/pulls?q=is%3Apr+head%3Afeat%2Fcompany-edit-tabs).

- `site/templates/company/edit.html.twig` и два partials раскладывают формы по вкладкам «Компания», «Налоги», «Консолидация». Пересчёт остатков остаётся самостоятельной формой на «Компании».
- `site/assets/pages/company_edit.js` синхронизирует Bootstrap Tab с URL, историей, фокусом и восстановлением страницы. Общий Twig partial задаёт оформление и доступные имена.
- `CompanyController::edit()` возвращает успешное сохранение на `#company` с подтверждением. Налоговые и консолидационные формы используют прежние якоря и правила записи. Миграции и изменения публичного API отсутствуют.

## Проверки

- Company и тесты остатков прошли. 322 теста, 1362 проверки PHPUnit.
- Прошли `make site-stan`, `make site-cs-check`, `make site-cs-strict-types`, `make site-test-unit`, `make site-cs-twig`. После поправки разметки повторно проверены Twig-шаблоны Company и общие partials.
- Прошли ESLint, Vite, Vitest и Chromium на 1440 и 390 px. Проверены прямые ссылки, история и фокус, сохранение и ошибки, мобильная таблица и доступность форм без JS Tabler. Полный PHPUnit запущен в отдельном локальном `var`, результат фиксируется в PR.

## Ревью

- Внутреннее независимое ревью. BLOCKER 0, IMPORTANT 1. Исправлено восстановление фокуса. Проверено в Chromium.
- Внешнее ревью. Claude CLI не авторизован, использована отдельная read-only сессия Codex с диффом и исходными фрагментами. Один результативный раунд. BLOCKER 0, IMPORTANT 1. Исправлена синхронизация при выходе из BFCache и подтверждена браузером. Повторный внешний раунд не требовался.
