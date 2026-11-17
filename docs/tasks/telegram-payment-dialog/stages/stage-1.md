### Stage 1: управление черновиком — DONE

Risk: HIGH-LOCAL. Stage base: `c3286aabe`. Work items: 1.1, 1.2.

Добавлены /draft, /cancel и постоянное меню. Список ограничен actor/company/
binding/chat; несколько черновиков требуют выбора. Отмена закрывает Cash draft,
редактирование и poll generation; следующий шаг старой доставки не запускается.
Перед новым HTTP дополнительно проверяется generation.

DoD: выполнено. Compact summary / no whole-list resend — Stage 3 по плану.
Baseline 136/992 PASS. Новые 3 функциональные регрессии: RED 3 failures на старом
коде, затем GREEN. Дополнительная гонка отмены во время HTTP: GREEN.
Module: `docker exec vf-intake-dialog-php php bin/phpunit --filter PaymentIntake`
— 140 tests / 1011 assertions PASS. Focused PHPStan 5 production files PASS;
PHP CS Fixer task files PASS (первый запуск не мог писать как container user,
исправлено локальным `docker exec -u 0`, production не затронут).

Internal review: 1 iteration, B0/I0; проверены scope/IDOR, callback ownership,
late-result guards, fencing, отсутствие LLM calls при меню/отмене.
External review: не требуется на Stage (<500 lines); обязателен на handoff.
Ограничение: уже начатый HTTP может завершиться после отмены; дальнейшие шаги
не публикуются. Нет schema/config/dependencies изменений.
Next: Stage 2 автоматически.
