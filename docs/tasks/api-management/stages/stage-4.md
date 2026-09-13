# Stage 4 — матрица прав и итоговая проверка — DONE

Risk: MEDIUM. Base: `17de7b7f`. Work items: 4.1, 4.2, 4.3. Реализация и проверка интерфейса завершены; полные gates выполнены, внешний handoff review завершён.

Владелец редактирует 16 отдельных подготовленных прав. Наборы «Только чтение» и «Все действия» выбирают конкретные отметки и требуют отдельного сохранения. Все десять ресурсов имеют статус «Не подключён» и недоступный переключатель включения. Форма использует company/key CSRF и версию записи; конфликт возвращает409 со ссылкой «Перечитать права», без повторного применения устаревшего выбора.

Файлы: PermissionsController, ApiKeyPermissionsType, permissions template, list/error templates, api_settings.js, route write-gate coverage и functional tests. Дополнительно исправлены namespace-префиксы констант в девяти файлах этой задачи: CI использует более строгий .php-cs-fixer.php, чем ошибочно выбранный ранее .dist.php. Конфигурация CI не менялась.

Проверки: targeted10tests87assertions; полный Api module+route coverage351tests1458assertions PASS; focused PHPStan, canonical CS, Twig, npm lint/build PASS. UI Kit9107baseline нарушений без новых; React mapping47 существующих ошибок без изменений. Браузер проверил16/10 presets, отключённое включение, подготовленные права в auth/check при effective=[], конфликт двух вкладок409 и сохранение нового выбора после reload, отсутствие секрета в хранилище и разметке, desktop/mobile390px.

Ручная проверка нашла1MINOR: скрытые absolute sr-only подписи таблицы расширяли документ до466px. Избыточные подписи удалены, aria-label каждого checkbox сохранён. Повторный browser smoke зелёный: viewport/document/workzone/scrollWidth=390. Временные browser fixture и native-session override удалены до итоговых тестов.

Review metrics Stage4 implementation:0BLOCKER/0IMPORTANT/1MINOR, исправлен. Итоговый fresh-context internal review и внешний round на handoff завершены (ниже).

Full gates: stan0errors/2550files, canonicalCS/strictTypes0/2565, unit2839/15897 PASS, api-types PASS. Первый full suite выявил 2 существующих aged-fixture failures Ingestion. Узкий date-only ремонт фикстуры проверен классом6/95, PHPStan/CS и независимым review0findings. Итоговый full suite PASS: 4819 тестов / 27838 проверок, 6 deprecations, без failures/warnings; production/финансовые правила не менялись. Fresh whole-task review0BLOCKER/0IMPORTANT/0MINOR. Внешний handoff round1 завершён:0BLOCKER/1IMPORTANT/3MINOR. IMPORTANT отклонён доказательством stopPropagation + реальный ErrorListener/logger-never test для5исключений. MINOR исправлены: rename lock, HTML Retry-After-only, существующий legacy dropdown-item длястарогоменю. Исправления25tests188assertions, PHPStan/CS/Twig иUIkit9108:baseline9107 +1legacy-исключение; fresh scoped re-review:0open. Fixed without re-run по §7.2.

Legacy-совместимость: старый layout загружает Tabler, а не UI Kit menu.css. Ссылка API использует тот же dropdown-item, что соседние пункты. Это одно явное дополнительное срабатывание UI Kit checker (9108 вместо9107), без нового CSS/зависимостей. Основание: root AGENTS.md §1 — существующий паттерн при интеграции; новый интерфейс API остаётся на UI Kit. Попытка menu-item выявлена scoped review как MINOR и исправлена; открытых замечаний0.
