### Stage 2: общие уточнения — DONE

Risk HIGH-LOCAL. Stage base `5467b7b49`. Work items 2.1–2.3.

Cash применяет commonText ко всему выбранному пакету под одной блокировкой и
ревизией, с company/actor validation и долговечным receipt. Дубли reply
отклоняются без нового эффекта. Исключённые строки не меняются; неизвестное
название или нераспознанная часть откатывают всё действие. Telegram понимает
общий ответ на сводку и явный общий ответ при единственном открытом черновике.
Начальные явные заголовки списка заполняют неизвестные дату/направление/
проект/ЦФО. Местная дата/направление имеют приоритет. Короткий год явно
трактуется как 20xx и показывается перед сохранением.

DoD выполнено. Суммы/плательщики не угадываются; «оплачено» само по себе не
подтверждает оплату со средств компании. Вопросы о личных средствах остаются.
Tests first: common parser/action RED (3 errors / 2 failures), header method
RED, unthreaded common reply RED 1 failure → GREEN.
Module: 150 tests / 1065 assertions PASS. Focused PHPStan PASS; CS Fixer PASS;
git diff --check PASS. Production/LLM не вызывались.

Internal review 1 iteration: B0/I1 fixed (повтор date/conflicting clause должен
отклонять весь ответ); повторная focused review B0/I0. Типизация offset исправлена
после PHPStan; baseline не увеличен. Question resolution сохраняет суммы,
статьи и вопросы источника/компенсации. Stage diff <500 строк, external только
на handoff. Next Stage 3 автоматически.
