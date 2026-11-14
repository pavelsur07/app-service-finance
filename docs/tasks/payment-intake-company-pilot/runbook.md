# Запуск company-scoped Telegram пилота

## Область и переключатели

Пилот одобрен владельцем для одной однозначно найденной компании. Её UUID — только runtime variable TELEGRAM_PAYMENT_INTAKE_COMPANIES, исходники/фикстуры содержат synthetic UUIDs. Общие AI grants и company monthly limit не изменяются; это не ночной бюджет30/900.

- TELEGRAM_PAYMENT_INTAKE_ENABLED=0 по умолчанию,1 включает при наличии scope.
- TELEGRAM_PAYMENT_INTAKE_COMPANIES — canonical UUID CSV без wildcard/пустых элементов/кавычек/newline. Пустой список закрывает пилот. На данном выпуске ровно один UUID.
- Разрешение Telegram pilot не означает automatic financial Confirm. Модель предлагает; пользователь проверяет и подтверждает.
- Уже принятый AI job может закончить обработку после отключения Telegram pilot. Отключение запрещает новые Telegram actions/Confirm/delivery после обновления процессов; не обещает отмену уже принятого provider request и не удаляет деньги/черновики.

## Два шага активации

1. Кодовой deploy с флагом0 и согласованным scope. Сначала ai-service получает single DeepSeek route; профилированный intake worker не стартует. Read-only проверить новый immutable SHA и healthy API.
2. Только после этого флаг1 и обычный production_action=deploy того же SHA. Тогда новый PHP не попадёт в старый двухмодельный AI route во время rolling update. Worker запускается explicit --profile payment-intake без --wait, recovery cron использует тот же scope.

Права на production changes — root AGENTS. Не выполнять вручную messenger consume/recovery/SQL writes через более широкий SSH. Миграций в этой задаче нет, исходные три уже выпущены PR2985.

## Проверка оператора

- PR merge SHA присутствует в master; required CI зелёный.
- Deployment success; API healthy, scheduler и site-messenger-worker-payment-intake работают на одном SHA; cgroup без OOM.
- GitHub variables соответствуют единственному согласованному scope и флагу1; конфиг и scripts протестированы без внешних действий.
- Read-only scoped SQL: readiness привязки, статусы/возраст только черновиков данной компании и queue counts без text/items/payload/PII. В wrapper messenger:stats допустим, ручной consume недопустим вне named approval.
- Nightly продолжает работать30/900 с новым fingerprint и реально настроенным parse route. Первый failed GPT report остаётся в истории; завершённый run той же даты не переигрывается после смены route. До следующего ежедневного run version_changed не является доказательством текущего плохого качества или PASS.
- Нет новых ручных paid benchmarks. Проверка на известных regression данных не является independent holdout или live Telegram E2E.

## Действия пользователя после выпуска

В выбранной компании открыть /integrations/telegram, нажать «Сгенерировать ссылку привязки», затем «Открыть Telegram и привязать». Старый binding не содержит actor_user_id, поэтому не назначать его владельцу автоматически и не создавать ссылку за неизвестного app actor. При необходимости вставить показанную /start-команду вручную; одноразовый token не пересылать в отчёты.

В личном чате /set_cash выбрать нужную кассу. Отправить настоящее сообщение о платежах. Проверить суммы/дату/направление/ДДС/проект/ЦФО, ответить на вопросы. Подтвердить факт оплаты и пакет только после проверки; ненужные строки явно исключить. Повторный Confirm возвращает прежние операции, не создаёт дубли. Фото не поддерживаются v1.

Это обязательный пользовательский rebind и фактическая live acceptance; агент не имитирует пользователя, не рассылает Telegram сообщения и не пишет фиктивные ledger entries.

## Откат

Флаг0 + обычный deploy останавливает profiled worker и recovery script. Pilot worker использует restart: unless-stopped, чтобы ручная остановка сохранялась после перезапуска Docker ([документация Docker](https://docs.docker.com/engine/containers/start-containers-automatically/)). Согласованный scope сохраняется, чтобы сообщения компании при отключённом флаге не переходили в legacy. Уже сохранённые финансовые операции не удаляются. На более широкую группу scope не расширять без owner scope decision. Возврат GPT fallback — отдельное изменение после проверки его качества.

## Известные ограничения

Ночной scheduler heartbeat отдельно не настроен; получение GlitchTip notification не подтверждено. Не заявлять защиту от полной остановки scheduler. Эти отдельные follow-ups не менять скрыто в задаче пилота.
