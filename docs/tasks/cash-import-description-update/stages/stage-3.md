# Stage 3 — предпросмотр, явный выбор и результат — DONE

Risk: MEDIUM; mixed POST write gate — HIGH-LOCAL verification.
Review base / stage_base_commit: backend Stage2 `d512ea500`. Backend/UI реализовывались параллельно в одной ветке.
Work items: 3.1–3.3.

## Что изменено

- Сводка/фильтры всего файла, Pagerfanta по 50 строк с нормализацией выхода за границы страниц.
- Реквизиты/источник, old/new описание, причины конфликтов и подробные ошибки строк. Нет предвыбранных обновлений.
- Явный выбор, select-all для всего плана, сохранение между страницами; применение профиля/mapping сбрасывает согласие даже при одинаковых колонках.
- Единая форма учитывает несохранённые флажки при запуске; маленький progressive script обновляет общий счётчик, включая другие страницы. Нет нового Vite entry/React/пакетов.
- Stable confirmation, committed_job_id и результат текущего задания; повтор не отправляет новое сообщение и не показывает устаревшие предложения.
- Ожидаемые ошибки плана/хранилища дают понятный flash; результат остаётся доступным при повреждении детализации. Итоги различают созданное/обновлённое/конфликты/ошибки/невыбранное/уже появившееся.
- POST предпросмотра требует FINANCE_WRITE, результат FINANCE_READ; все данные ограничены активной компанией. CSRF и экранирование проверены.

## Проверки

- 12 Functional tests / 78 assertions до последних UUID/encoding регрессий: selection, whole-file/page3, unsaved selection, повтор/повреждение/отсутствие marker, same mapping/profile reset, CSRF/foreign row, scoped result.
- Mixed-route write gates и schema checks после исправления: 7 tests / 39 assertions OK на новой тестовой БД.
- Twig lint: все 3 шаблона OK. PHPStan/CS проверены. npm lint/build не требовались: assets/package/Vite entries не менялись; inline progressive script проверен реальным браузером.
- Playwright Chromium: реальная загрузка CSV/маппинг/предпросмотр; empty filter и ошибки invalid; длинный текст и HTML из файла; keyboard Space selection и общий счётчик 1; запуск с несохранённым checkbox → очередь. Desktop1440×1000, mobile390×844, horizontal scroll old/new. JavaScript errors=0, dialogs=0.
- Синтетические browser fixtures удалены, локальный сервер остановлен. Артефакты screenshots/; видимый debug toolbar относится только к локальному dev.

## Ревью и DoD

Internal: обычные/пустые/ошибочные/адаптивные состояния и permission/consent проверены. Нет открытых BLOCKER/IMPORTANT. Внешнее — covered Stage 2 и handoff rounds, все подтверждённые IMPORTANT/MINOR исправлены, подробности handoff.md.

Далее: Stage 4, интеграция и передача. Не выполнены merge, production migration или deploy — это отдельное решение владельца.
