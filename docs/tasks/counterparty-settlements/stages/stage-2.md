### Stage2: источники и защита жизненного цикла — REVIEWING

Risk HIGH-LOCAL. Base66e666657.

Реализованы locked DTO/Fascade Cash/Finance, явное создание оплаты/основания,
повторная проверка source fingerprint при проведении, активные ссылки и отмена.
Все суммы — Money; legacy Cash→ОПиУ allocation не меняется.

Source guards покрывают все write paths из source-write-paths.md. Импорт атомарен,
отклонённые пакеты сохраняют завершённый журнал ошибки. Счёт и строки остаются
защищены до commit/rollback. Изменения описаний допускаются.

Checks: targeted combined set PASS118 tests /594 assertions; focused PHPStan/CS PASS.
После header-lock cleanup source/Finance subset PASS62 /387, PHPStan PASS.
Две реальные PG-сессии проверяют sorted locks, registration vs mutation, и lease
между возвратом сервиса и внешним commit; не только DAMA rollback.

Internal: B0, I5 unique fixed (PAYMENT correction bypass + 4 reviewer issues), MINOR1 fixed.
Fresh bounded rereview: no open B/I. Existing direct-service overwrite tests adapted to actual Action; PASS6 /49.
External: first round running; Stage not closed.

Next: complete external round, commit/push, then Stage3 automatically.
