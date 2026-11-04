# План: «Вход», «Регистрация», логотип, юридический блок (legacy-слой)

Задача: `docs/design-system/PROMPT-auth-pages.md`, эталон — `AUTH-SPEC.md`,
приёмка — `CHECKLIST-auth-pages.md`. Класс — Large (редизайн фронта + auth-код),
риск HIGH-LOCAL (auth, FormType регистрации). Ветка `feat/ds-auth-pages`,
`stage_base_commit` задачи — `c5023898b`. Один Draft PR на задачу (AGENTS §8 выше
промпта, который просил PR на этап): этапы — отдельными коммитами.

## Stage 0 — аудит (03.11.2026)

**1. Что применено сейчас.**
- Вход: `GET|POST /login` (`app_login`, `src/Shared/Controller/SecurityController.php`),
  шаблон `templates/security/login.html.twig` → `security/base_auth.html.twig`
  (Vite `app` + `login` = `assets/styles/pages/login.css`, новый кит, двухколоночная
  раскладка с рекламной панелью). Поля `_username`, `_password`, `_remember_me`,
  CSRF `authenticate` (сессионный: stateless в `csrf.yaml` закомментирован).
  Firewall `main`: `form_login`, `login_throttling` 5 / 15 мин; **`remember_me` в
  firewall нет** — чекбокс «Запомнить меня» сейчас ни на что не влияет.
- Регистрация: `GET|POST /register` (`app_register`,
  `src/Company/Controller/RegistrationController.php`), форма
  `RegistrationFormType` (`registration_form[companyName|businessProfile|email|plainPassword|agreeTerms|website]`),
  `website` — honeypot, `RegistrationRateLimiter` по IP. Шаблон
  `security/register.html.twig` — самостоятельный HTML на Bootstrap 5 с CDN и
  инлайн-JS, бренд «Fin-Plan». Приглашение (`?invite=`) рендерит тот же шаблон без
  компании и профиля.
- Сервер: пароль `NotBlank` + `Length(min 6)`, **правил сложности нет**; согласие —
  одно `agreeTerms` (`IsTrue`), не маппится и **не сохраняется** (ни дата, ни версия).
  Email — `UserEmailType` (TextType, не `type=email`: Unicode-адреса), занятый email —
  `UniqueEntity` на `User`. Сообщения валидатора частично английские.
- После регистрации — мгновенный вход (`$security->login`) и письмо «Регистрация
  завершена» (async). **Подтверждения email и экрана «Проверьте почту» нет.**
  **Сброса пароля нет** (в шаблоне `href="#"` с TODO).

**2. Логотип и название.** Текстовый «ВФ»-квадрат — `login.html.twig`;
`static/logo-vf-001.png` — легаси-меню `partials/_sidebar.html.twig` (меню светлое,
`--tblr-bg-surface`), шапка нового слоя `app/_shell/header.html.twig`, админка
`admin/partials/_header.html.twig`; favicon — `static/favicon.svg` во всех layout'ах,
`favicon.ico` в админ-логине (файл — тоже SVG); письма — `brand_logo_url` в
`notifications/email/base.html.twig` никто не передаёт, логотипа в письмах нет,
`default_uri` роутера не задан. Manifest и OG-тегов нет. «Fin-Plan» — `<title>` и
копирайт регистрации.

**3. Юридические страницы.** Роутов `/legal/*` нет, документов нет ни одного.

**4. Cookie и аналитика.** Скриптов аналитики/маркетинга (Метрика, GTM, gtag) в
шаблонах нет; баннера cookie нет. Cookie — только сессия.

**5. Тесты и селекторы.** `LoginControllerTest` (кнопка «Войти», `.alert--danger`
с текстом `Invalid credentials.`), `PublicRegistrationFlowTest`,
`InviteRegistrationFlowTest`, `BusinessProfileOnCreationTest` (кнопка «Создать
аккаунт», имена `registration_form[...]`); e2e `tests/e2e/*.cjs` логинятся через
`input[name=_username]`, `input[name=_password]`, `button[type=submit]`.
Baseline: 4 файла, 15 тестов, 108 assertions — OK.

**6. Разбивка.** Один PR, три этапа-коммита: Stage 1 — логотип/favicon, общие
partials, реквизиты в конфиге, баннер cookie; Stage 2 — вход; Stage 3 — регистрация.

## Решения (расхождения с SPEC — в отчёт)

1. **Ссылки на документы** берутся из конфигурации `legal.documents.*`; пустой URL —
   название без ссылки, мёртвых ссылок нет. Пока страниц нет — футер без строки
   документов. Вопрос владельцу — SPEC §10.2.
2. **Реквизиты**: параметр `vf_legal` (`config/packages/vf_legal.yaml`); в проде
   пусто → строки и блок «Сведения о компании» скрыты; в `dev` — демо из
   `legal.example.yaml` с пометкой DEMO, чтобы сверять вид с макетом.
3. **Второе обязательное согласие** `agreePersonalData` — добавляется в
   `RegistrationFormType` (unmapped, `IsTrue`): серверная проверка обязательна по
   SPEC; существующие имена полей не меняются. Хранится так же, как `agreeTerms`, —
   никак (вопрос §10.3).
4. **Согласие на рассылку не выводится**: хранить негде без миграции, а собирать
   согласие, которое не сохраняется, нельзя. Вопрос §10.3.
5. **Правила пароля** — серверные: одно правило «Не короче 6 символов»;
   индикатор надёжности — подсказка, не блокирует. Вопрос: поднимать ли серверные
   требования до макета (8 / регистр / цифра).
6. **«Забыли пароль?» и «восстановить пароль»** не выводятся: роута нет.
7. **«Запомнить меня»** оставлен (включён по умолчанию, как в SPEC), но работать
   начнёт только после `remember_me` в firewall — вне рамок (Security не трогаем).
8. **Экран «Проверьте почту»** не делается — потока подтверждения нет; после
   регистрации — вход, как сейчас (§10.4).
9. **Email** остаётся текстовым полем с `inputmode=email` (Unicode-адреса,
   комментарий `UserEmailType`), а не `type=email`.
10. **Логотип в легаси-меню** — `logo-full.svg` (меню светлое, белый логотип на
    нём не виден). Шапка нового слоя и админка — вне рамок (промпт запрещает новый
    слой), письма — follow-up (нужен `default_uri`).

## Этапы

| Stage | Риск | Definition of Done |
|---|---|---|
| 1 логотип и общее | MEDIUM | `public/brand/*`; favicon заменён во всех layout'ах; логотип в легаси-меню; Vite-entry `auth` (CSS+JS+cookie); partials `_logo`, `_legal_footer`, `_cookie_banner`; `vf_legal` в конфиге |
| 2 вход | HIGH-LOCAL | шаблон по SPEC §2, §4; поля/роут/CSRF прежние; тесты зелёные; скриншоты 01–06 |
| 3 регистрация | HIGH-LOCAL | шаблон по SPEC §2, §5; `agreePersonalData` с тестом; плашка «email занят»; приглашение работает; скриншоты 07–13 |
| handoff | — | гейты, ревью в чистой сессии, внешнее ревью, `docs/plan/ds-auth-pages.md` |

**Статус: закрыта 04.11.2026** — PR #2872 на проде с 03.11; итог и приёмка — `docs/plan/ds-auth-pages.md` §7.
