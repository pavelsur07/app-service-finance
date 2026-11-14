# Пилот PaymentIntake для одной компании

Owner brief: «Запускаем пилот на ИП Третьякова» после предложения ограничить компанию, использовать DeepSeek без GPT fallback, подготовить worker и обе recovery-команды и проверить сценарий.
Компания однозначно найдена read-only; UUID хранится только в runtime variable/локальном ignored evidence, не в исходниках. AI grant уже есть, лимит компании не меняем. Единственная действующая Telegram-привязка не имеет actor_user_id: владелец должен заново связать Telegram из кабинета. Не назначать actor автоматически и не посылать сообщения пользователям от агента.

## Обязательное поведение
- Доступ Telegram intake: existing global bool TELEGRAM_PAYMENT_INTAKE_ENABLED и strict CSV TELEGRAM_PAYMENT_INTAKE_COMPANIES. По умолчанию off/empty, wildcard запрещён, malformed list полностью закрывает scope.
- Неучастники с никогда не выбранным intake сохраняют legacy. Известные source/reply receipts/session/sticky intake не падают в legacy при отключении/отзыве/смене компании.
- Gate message/callback/poll/publication/recovery ДО изменений сессии, Cash действий, pull или Telegram HTTP. Глобальные ownership guards сохраняются независимо от флага.
- Cash остаётся source-neutral; общие AI grants, финансовые формулы, права, ручной Confirm, идемпотентность не меняются. Уже принятая AI задача может завершиться после выключения Telegram пилота; автоматического ledger write нет. Отзыв запрещает Telegram edits/Confirm/delivery, но не обещает отмену уже допущенного provider call.
- parse_payment_message: single DeepSeek. Categorization и source-type draft сохраняют текущие fallback-маршруты. Nightly продолжает проверять реально настроенный task route; старый GPT failed отчёт не удаляется и платный run текущего дня не переигрывается.
- Profiled production worker async_payment_intake, bounded memory, same immutable image; enabled+valid nonempty scope => explicit start, иначе stop; общий финальный up не включает профиль.
- Обе company-scoped recovery-команды каждые 5 минут, strict validation whole list до первой команды. Ошибка Cash не пропускает Telegram delivery и остальные компании; общий exit ненулевой. Telegram --skip-cash-recovery сохраняет прежнее поведение без опции.
- Новых schema/dependencies/secrets, production SQL writes/backfill/автоматического подтверждения, новых ручных paid benchmarks нет. Ночной бюджет 30/900 не меняем; business pilot использует существующие company AI limits.

## Приёмка
- Тесты isolation/rollback/no-legacy/revoked callbacks+poll+recovery, actorless rebind, existing Confirm replay зелёные.
- Runtime tests доказывают безопасный scope и условный worker; parse route single, другие routes прежние.
- Полные CI gates, независимое внутреннее и внешнее review, один task PR.
- После разрешённого deploy: read-only schema/process/queue/scope evidence; реальный Telegram ввод и подтверждение делает сам пользователь после rebind. Stub E2E не объявляется live E2E; независимый holdout остаётся открытым.
