### Stage2: источники и защита жизненного цикла — DONE

Risk HIGH-LOCAL. Base66e666657.

Реализованы locked DTO/Fascade Cash/Finance, явное создание оплаты/основания,
повторная проверка source fingerprint при проведении, активные ссылки и отмена.
Все суммы — Money; legacy Cash→ОПиУ allocation не меняется.

Source guards покрывают все write paths из source-write-paths.md. Импорт атомарен,
отклонённые пакеты сохраняют завершённый журнал ошибки. Счёт и строки остаются
защищены до commit/rollback. Изменения описаний допускаются.

Checks: targeted combined set PASS119 tests /608 assertions; focused PHPStan/CS PASS.
После header-lock cleanup source/Finance subset PASS62 /387, PHPStan PASS.
Две реальные PG-сессии проверяют sorted locks, registration vs mutation, и lease
между возвратом сервиса и внешним commit; не только DAMA rollback.

Internal: B0, I5 unique fixed (PAYMENT correction bypass + 4 reviewer issues), MINOR1 fixed.
Fresh bounded rereview: no open B/I. Existing direct-service overwrite tests adapted to actual Action; PASS6 /49.
External: one round; B0/I2/MINOR3, all fixed and verified, fixed without re-run.
Source business exception retains existing forms/flash+redirect; actual functional tests PASS2/17.
Overwrite prelocks only actual file targets; unrelated history stays unlocked in two-session test.
Final targeted set PASS119/608; focused project CS/PHPStan PASS.

Next: Stage3 automatically. Full handoff gates remain scheduled for Stage5.
