# Stage 2 — выбор компании — DONE

Risk MEDIUM. Base `62fbe9d82`. Work items 2.1–2.3.

CompanyPicker использует DS Card 520, Radio, Avatar, Input, Pagination, Button.
Черновой выбор не вызывает POST: только «Продолжить» запускает прежний selectCompany.
После login account при >1 компании показывается chooser, при 0/1 возврат через
неизменённый safeReturnPath. Search с 8 компаний, на нескольких страницах явно
обозначен поиск по текущей странице. Предвыбор — active_company из API; это не
гарантированная долговременная last-used настройка (TODO). ИНН/роли API не отдаёт.
Сохранены AbortController cleanup, 401→refresh, 422 поздней страницы→страница1,
пагинация и server reconciliation. В workspace заголовок h2, после login h1.

Checks: исправленный стабильный mock store: старый CompanyPicker RED3/3,
новый GREEN4/4 (включая single-company без POST); account module162 passed,
typecheck и lint GREEN; preview account auth/company E2E12/12 на desktop/390.
Первые E2E10/12: два старых ожидания имени loading кнопки «Войти»; обновлены
на требуемое «Входим…», затем все12 GREEN. Изначальный mock store создавал новый
объект на render и зацикливал эффект; тестовый harness исправлен до RED/GREEN.

Internal review: полный Stage diff + focused fix review. B0/I0; MINOR2 исправлены:
уровень заголовка workspace и запрещение скрытого поиском draft выбора.
External: не требуется для MEDIUM промежуточного Stage; общий handoff далее.
Открытых B/I нет. site diff пуст.
