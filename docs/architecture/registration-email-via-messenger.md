# Registration email via Messenger

## Цель
После успешной регистрации (создан `User` и `Company`, выполнен `flush`) система должна **асинхронно** отправить пользователю email с подтверждением регистрации.

**Критерий успеха:** пользователь получает письмо о регистрации.

---

## Почему через Messenger (Message Bus)
- Регистрация не должна зависеть от скорости SMTP/провайдера.
- Если email-провайдер временно недоступен, задача будет повторена (retry) и/или попадёт в failure transport.
- Контроллер регистрации остаётся простым: он фиксирует факт регистрации и ставит задачу в очередь.

---

## Поток выполнения (step-by-step)

### 1) RegistrationController (HTTP слой)
Файл: `site/src/Controller/RegistrationController.php`

**Отвечает за:**
- обработку формы регистрации
- создание сущностей `User` и `Company`
- сохранение в БД (`persist` + `flush`)
- постановку задачи в Messenger (dispatch message)
- логин пользователя (как и было)

**Ключевое правило:**
`dispatch()` выполняется **после** `$entityManager->flush()` и **до** возврата ответа (login/redirect).

---

### 2) Message (DTO задачи)
Файл: `site/src/Message/SendRegistrationEmailMessage.php`

**Содержит только идентификаторы:**
- `userId: string`
- `companyId: string`
- `createdAt: DateTimeImmutable`

**Почему id-only:**
- Doctrine-сущности не сериализуются надёжно для очередей.
- Сообщение должно быть стабильным и переносимым между процессами.
- Данные всегда подтягиваются актуальными из БД в момент обработки.

---

### 3) Routing Messenger → async transport
Файл: `site/config/packages/messenger.yaml`

В секции routing добавляется:
- `App\Message\SendRegistrationEmailMessage: async`

**Зачем:**
- выполнение обработчика происходит не в HTTP запросе, а в фоне.

---

### 4) Worker (consumer)
Команда (пример):
- `php bin/console messenger:consume async`

**Отвечает за:**
- чтение сообщений из транспорта `async`
- вызов соответствующих обработчиков
- retry при ошибках (настройки в messenger.yaml)
- отправку в failure transport при превышении retry

---

### 5) MessageHandler (исполнитель задачи)
Файл: `site/src/MessageHandler/SendRegistrationEmailHandler.php`

**Отвечает за:**
1) загрузку данных из БД:
   - `UserRepository->find(userId)`
   - `CompanyRepository->find(companyId)`
2) валидацию наличия данных
3) формирование уведомления
4) отправку email через NotificationRouter

**Важно:**
handler — единственное место, где происходит реальная отправка email.

---

### 6) NotificationRouter + EmailSender (доставка)
Файлы:
- `site/src/Notification/Service/NotificationRouter.php`
- `site/src/Notification/Channel/EmailSender.php`
- `site/src/Notification/DTO/EmailMessage.php`

**Отвечают за:**
- выбор канала (`email`)
- рендер шаблонов
- отправку через mail transport

---

## Шаблоны email
Файлы:
- `site/templates/notifications/email/registration_success.html.twig`
- `site/templates/notifications/email/registration_success.txt.twig`

**Используемые переменные (vars):**
- `user`
- `company`

---

## Обработка ошибок и повторы
- Retry политика берётся из `messenger.yaml` (max_retries/multiplier).
- После превышения retry сообщение попадает в `failure_transport` (`failed`).

Рекомендация:
- если `User` или `Company` не найдены (например, данные удалили), handler должен логировать warning и завершаться без исключения.
- если SMTP/transport упал — допускается исключение (Messenger выполнит retry).

---

## Ответственности (коротко)
- **RegistrationController:** создать и сохранить данные, поставить задачу в bus
- **SendRegistrationEmailMessage:** переносит `userId/companyId` между процессами
- **Messenger routing:** отправляет задачу в async transport
- **Worker:** исполняет очередь
- **SendRegistrationEmailHandler:** загружает данные и вызывает отправку email
- **NotificationRouter/EmailSender:** формируют и отправляют письмо
