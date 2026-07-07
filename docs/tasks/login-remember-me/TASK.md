# Task: Включить «Запомнить меня» (remember_me) на login

> Активировать remember_me в Symfony Security. Чекбокс в форме уже есть, но firewall его не обрабатывает — сейчас флажок декоративный.
> Риск: 🔴 HIGH (правка `security.yaml` / auth). Обязательная остановка на ревью Владельцем перед merge.

---

## Контекст

Форма login (`templates/security/login.html.twig`) содержит:
```twig
<label class="checkbox-label">
  <input class="checkbox" type="checkbox" name="_remember_me">
  <span>Запомнить меня на этом устройстве</span>
</label>
```
Имя `_remember_me` — стандартное, Symfony его ждёт. Но в `config/packages/security.yaml`, firewall `main`, **нет секции `remember_me`** → отметка ни на что не влияет: после закрытия сессии (session cookie) пользователя разлогинивает.

Связано: HTTPS за прокси уже чинён (`trusted_proxies`, PR #2120) — поэтому `secure: true` на remember_me куке теперь корректно сработает.

---

## Что делаем

1. Добавить `remember_me` в firewall `main` (`security.yaml`).
2. Убедиться, что чекбокс формы уже совместим (менять форму не нужно).
3. Функциональный тест: с галкой ставится кука `REMEMBERME`, без галки — нет.

Вариант по умолчанию — **signature-based** (кука подписана `%kernel.secret%`, без таблицы в БД). Persistent-вариант (token provider + таблица, серверная инвалидация / «выйти со всех устройств») — **опционально**, вынести отдельно, если Владелец захочет.

---

## Pre-flight

1. Свежий master, `git status` чистый.
2. Подтвердить текущее состояние:
   ```bash
   grep -n "_remember_me" site/templates/security/login.html.twig   # чекбокс есть
   sed -n '/main:/,/access_control/p' site/config/packages/security.yaml  # remember_me нет
   grep -n "APP_SECRET" site/.env                                    # секрет есть (для %kernel.secret%)
   ```

---

## Шаг 1 — remember_me в firewall main

В `config/packages/security.yaml`, firewall `main`, после `logout`:

```yaml
        main:
            lazy: true
            provider: app_user_provider
            form_login:
                login_path: app_login
                check_path: app_login
                enable_csrf: true
            remember_me:
                secret: '%kernel.secret%'
                lifetime: 604800          # 7 дней
                path: /
                secure: true             # только https (после trusted_proxies fix кука уже secure)
                samesite: lax
                # token_provider: ...    # НЕ добавлять — только для persistent-варианта (см. ниже)
            logout:
                path: app_logout
```

Пояснения:
- `secret` — подпись куки, берём `%kernel.secret%` (= `APP_SECRET`).
- `lifetime` — 7 дней (согласовать с Владельцем; типично 1–4 недели).
- `secure: true` — кука только по HTTPS. Работает, т.к. Symfony теперь видит https (PR #2120). На локалке без https проверять в конфиге dev или через `secure: auto`.
- `always_remember_me` не задаём (дефолт `false`) → remember_me включается **только** при отметке чекбокса.
- httponly по умолчанию `true` — не трогаем.

**Форму НЕ меняем** — `name="_remember_me"` уже правильное (Symfony ищет request-параметр `_remember_me`).

---

## Шаг 2 — Функциональный тест

`tests/.../SecurityRememberMeTest.php` (или в существующий security-тест):

- **happy-path:** POST на `app_login` с валидными кредами **и** `_remember_me=on` → ответ ставит куку `REMEMBERME` (проверить `Set-Cookie`).
- **негатив:** тот же POST **без** `_remember_me` → куки `REMEMBERME` нет.
- (опц.) с истёкшей session-cookie, но валидной `REMEMBERME` → доступ к защищённой странице сохраняется.

Прогнать: `make test -- --filter RememberMe` (или как настроено).

---

## Шаг 3 — Ручной smoke (после деплоя, Владелец)

1. Логин с галкой «Запомнить меня» → в DevTools есть кука `REMEMBERME` (secure, httponly, samesite=lax).
2. Удалить session-cookie (`PHPSESSID`), обновить защищённую страницу → **остался залогинен** (реавторизация по remember_me).
3. Логин без галки → `REMEMBERME` не ставится → после удаления `PHPSESSID` разлогинивает.
4. Logout → `REMEMBERME` очищается (Symfony делает автоматически, `logout` уже настроен).

---

## Self-review

- [ ] `remember_me` добавлен в firewall `main`, `secret: '%kernel.secret%'`
- [ ] `always_remember_me` НЕ включён (реагирует только на чекбокс)
- [ ] `secure: true`, `samesite: lax`
- [ ] Форма не тронута (`_remember_me` уже был)
- [ ] `lint:yaml` + `cache:clear` — конфиг парсится
- [ ] Функциональный тест (с галкой/без) — зелёный
- [ ] `make test && make stan && make cs` — чисто

---

## Что НЕ делать

```
always_remember_me: true                     — разлогинит логику чекбокса, ставит куку всегда
менять name чекбокса                          — _remember_me уже правильное
добавлять token_provider/таблицу БД           — только для persistent-варианта, отдельная задача
трогать trusted_proxies / session config      — вне scope (уже сделано в #2120)
secure: false на проде                        — кука должна быть только по https
merge без ручного smoke входом                — HIGH-risk auth
```

---

## Открытый вопрос для Владельца

- `lifetime`: 7 дней ок, или другое (1/2/4 недели)?
- Нужна ли серверная инвалидация remember_me («выйти со всех устройств», отзыв при смене пароля)? Если да → persistent token provider + таблица БД — **отдельная задача** поверх этой.

---

## Closing

🔴 HIGH-risk (security.yaml). Draft PR → STOP, ревью Владельцем + ручной smoke входом → merge.
