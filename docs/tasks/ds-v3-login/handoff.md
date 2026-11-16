# ds-v3-login — handoff

PR [#3043](https://github.com/pavelsur07/app-service-finance/pull/3043),
branch `feat/ds-v3-login`, base `master`. Stage commits: `62fbe9d82`, `c716a8d1e`,
Stage3 code `56d0adc94`; дополнительный tests/docs commit — tip PR. Merge/deploy ещё не выполнены: нужно единственное
решение Владельца `merge and deploy #3043` по AGENTS §3.2. Миграций нет.

## Результат и файлы

- `apps/packages/ds/src/components/AuthScreen/` — общий экран, форма, юридический
  текст и cookie; DS styles и `public/brand/logo-full.svg` — визуал v3.4.
- Button spinner, InputGroup auth/eye18, Avatar tint — расширены существующие
  компоненты с тестами; defaults прежних вариантов сохранены.
- `apps/{account,admin}/src/pages/LoginPage.tsx`, app routes/providers/guards и
  presentation.ts — интеграция и достоверный UI признак истечения сессии.
- Account CompanyPicker — radio/явное Continue, search≥8, pagination, logout.
- `site/`, raw session stores, safeReturnPath, API/financial/security contracts
  не изменены. Отдельные auth-host сессии и старый 401/reconciliation сохранены.
- [План](plan.md), [визуальная сверка со скриншотами](visual-acceptance.md),
  [Stage1](stages/stage-1.md), [Stage2](stages/stage-2.md), [Stage3](stages/stage-3.md).

## Проверки

- Baseline до кода `make front-test`: account140/admin136 GREEN.
- Тесты сначала RED: login6/10, controls2/36, исправленный company harness3/3,
  expiry provenance1/15, Avatar tint1/20, source shadow и company style в browser.
- `make front-typecheck front-lint front-test front-build` GREEN. После найденных
  исправлений typecheck/build/lint повторены, module: account166/admin158 GREEN.
- `make front-e2e MODE=preview`: account29 + admin25 + DS component48 GREEN;
  10 штатных conditional skips (production-only и desktop-only случаи).
  После review fixes focused account12/admin6 GREEN (обе ширины), финальный Avatar
  variant: company4/4 GREEN. Проверен expiry→login→chooser→logout со старым reason.
- По AGENTS full PHP gates один раз на handoff: `make site-stan site-cs-check
  site-cs-strict-types site-test-unit` GREEN; unit4928/27251 assertions.
- Contrast фактического DOM: текст минимум4.682:1, field border3.280:1, focus2px
  на семи элементах формы/баннера в обоих apps и обеих ширинах. [Метрики](artifacts/acceptance-metrics.json).
- `make front-auth-e2e MODE=dev` GREEN: настоящий Symfony account2/admin2 и DS48.
  Старые CI ожидания select/мгновенного sidebar обновлены под chooser; изоляция
  cookie/host/CSRF/legacy сохранена, fresh internal review GREEN. Nginx smoke на
  code56d0adc94 прошёл; итоговый tests/docs tip проверяется PR CI перед Ready.
- `git diff --stat origin/master -- site/` пуст; raw store diff тоже пуст.

## Ревью

Internal: Stage self-review + свежая read-only сессия в handoff, затем проверка
исправлений. B0/I1: query-only expiry мог появиться у гостя/после logout; исправлен
AND real provenance + redirect reason, regression RED→GREEN. MINOR доступности,
props interface и визуала исправлены. Последний fresh re-review REVIEW_GREEN.

External: Claude CLI отсутствует → отдельный Codex CLI, две точки по одному раунду.
Stage1 B0/I1 (известный email/password focus при expiry), fixed without re-run с
регрессией; handoff B0/I0, точный REVIEW_GREEN. Статический полный clean diff+
задание/эталоны/контракты через stdin, tools запрещены; независимых запусков тестов
reviewer не делал. Последний безопасный визуальный Avatar minor после external
проверен internal + SSR/E2E, повтор внешнего раунда не нужен по §7.2.

Метрики: internal BLOCKER0/IMPORTANT1; external BLOCKER0/IMPORTANT1;
все подтверждённые исправлены, открытых0/0. External rounds: Stage1=1, handoff=1.

## TODO(ds), отклонения и решения для продолжения

1. В login API нет remember. «Запомнить меня» скрыто; потребуется отдельное решение
   о поддержке API, если элемент нужен.
2. В SPA нет URL восстановления. «Забыли пароль?» скрыто; нужен рабочий URL/flow.
3. В account SPA нет регистрации. Оба signup блока и разделитель скрыты; нужен
   подтверждённый адрес, без ссылки на несуществующий `/register` этого host.
4. `/legal/offer|terms|privacy|personal-data|cookies` отсутствуют: названия документов
   обычным текстом, юридическая строка без ссылок. Нужны реальные доступные URL.
5. В apps нет legal company config. «Сведения о компании» скрыто, copyright только
   с известным брендом. Нужны реквизиты/номера реестров/правообладатель/контакты и
   способ передачи конфигурации; demo данные не использованы.
6. Companies API отдаёт id/name. ИНН/роль не выводятся; нужны поля контракта, если
   они обязательны в следующей итерации.
7. Предвыбор — active_company API на видимой странице. Backend автоматически
   выбирает компанию при login и не гарантирует durable last-used; нужен контракт
   предпочтения, если необходимо именно сохранение между входами.
8. Поиск — по текущей странице (при total≥8), явно подписан при pagination. Для
   глобального поиска нужен поддержанный API-параметр.

Остальные визуальные отклонения: мобильные hero28/cookie32 и footer по главной
таблице (illustrative CSS24/40 и без footer); форма короче из-за отсутствующих
функций; ошибка использует contrast-safe danger-text вместо source danger-dot2.80.
Cookie sticky находится в потоке, в demo absolute перекрывает footer. Analytics
в apps нет, новые загрузчики не добавлены. Все согласованные элементы реализованы;
перечисленные неподдержанные API/URL/данные не изобретались.
